<?php
/**
 * Durable, at-least-once jobs. Payloads never contain transport credentials.
 * Claims and rate reservations use SQL, independently of the object cache.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the queue must bypass cache and claim rows atomically.
final class Queue {

	private const VERSION = '1';
	/** @var array<string, callable(array<string, mixed>): (true|WP_Error)> */
	private static array $handlers = [];
	private static bool $running   = false;

	public static function boot(): void {
		self::install();
		add_action( 'shouse_queue', [ self::class, 'run' ] );
		add_action( 'shouse_hourly', [ self::class, 'run' ] );
		add_filter( 'site_status_tests', [ self::class, 'health_tests' ] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shouse_jobs';
	}

	private static function install(): void {
		if ( self::VERSION === get_option( 'shouse_queue_db_version' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				queue varchar(32) NOT NULL,
				payload longtext NOT NULL,
				attempts int unsigned NOT NULL DEFAULT 0,
				available_at bigint(20) unsigned NOT NULL,
				locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
				lock_token varchar(36) NOT NULL DEFAULT '',
				created_at bigint(20) unsigned NOT NULL,
				last_error varchar(300) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY ready (queue,available_at,locked_until)
			) {$wpdb->get_charset_collate()};"
		);
		if ( '' === $wpdb->last_error && $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			update_option( 'shouse_queue_db_version', self::VERSION, false );
		} else {
			update_option( 'shouse_queue_storage_error', time(), false );
		}
	}

	/** @param callable(array<string, mixed>): (true|WP_Error) $handler Known, code-defined handler. */
	public static function handler( string $name, callable $handler ): void {
		self::$handlers[ $name ] = $handler;
	}

	/**
	 * Every enqueue has its own row: a change arriving during delivery must not be lost by deduplication.
	 *
	 * @param array<string, mixed> $payload JSON data, never serialized executable callbacks.
	 */
	public static function add( string $name, array $payload ): bool {
		global $wpdb;
		$json = wp_json_encode( $payload );
		if ( ! isset( self::$handlers[ $name ] ) || false === $json || strlen( $json ) > MB_IN_BYTES ) {
			return false;
		}
		$ok = $wpdb->insert(
			self::table(),
			[
				'queue'        => $name,
				'payload'      => $json,
				'available_at' => time(),
				'created_at'   => time(),
			],
			[ '%s', '%s', '%d', '%d' ]
		);
		if ( false === $ok ) {
			update_option( 'shouse_queue_storage_error', time(), false );
			return false;
		}
		delete_option( 'shouse_queue_storage_error' );
		self::schedule();
		return true;
	}

	/** Atomic spacing shared by workers; no persistent-cache dependency. */
	public static function reserve( string $name, int $seconds ): bool {
		global $wpdb;
		$key = 'shouse_queue_rate_' . hash( 'sha256', $name );
		$now = time();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'off')", $key ) );
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d", (string) ( $now + max( 1, $seconds ) ), $key, $now ) );
	}

	/** A bounded worker. Crashed workers' leases expire; failed jobs remain until delivered. */
	public static function run( string $only = '', int $limit = 10 ): void {
		if ( self::$running ) {
			return;
		}
		global $wpdb;
		$limit         = max( 1, min( 20, $limit ) );
		self::$running = true;
		try {
			$names = '' === $only ? array_keys( self::$handlers ) : ( isset( self::$handlers[ $only ] ) ? [ $only ] : [] );
			foreach ( $names as $name ) {
				for ( $i = 0; $i < $limit; ++$i ) {
					$now = time();
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE queue = %s AND available_at <= %d AND locked_until <= %d ORDER BY id LIMIT 1', self::table(), $name, $now, $now ), ARRAY_A );
					if ( ! is_array( $row ) ) {
						break;
					}
					$token = wp_generate_uuid4();
					if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET locked_until = %d, lock_token = %s WHERE id = %d AND available_at <= %d AND locked_until <= %d', self::table(), $now + 600, $token, $row['id'], $now, $now ) ) ) {
						continue;
					}
					$payload = json_decode( $row['payload'], true );
					try {
						$result = is_array( $payload ) ? ( self::$handlers[ $name ] )( $payload ) : new WP_Error( 'queue_payload', 'Invalid queue payload.' );
					} catch ( Throwable $error ) {
						// Exceptions may include credentials or mail content. Store only the class.
						$result = new WP_Error( 'queue_exception', 'Delivery raised ' . get_class( $error ) );
					}
					if ( true === $result ) {
						$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND lock_token = %s', self::table(), $row['id'], $token ) );
						continue;
					}
					$attempts = (int) $row['attempts'] + 1;
					$data     = $result->get_error_data();
					$delay    = is_array( $data ) && isset( $data['retry_after'] ) ? max( 30, min( DAY_IN_SECONDS, (int) $data['retry_after'] ) ) : min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 6, $attempts - 1 ) ) ) + wp_rand( 0, 30 );
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = %d, available_at = %d, locked_until = 0, lock_token = %s, last_error = %s WHERE id = %d AND lock_token = %s', self::table(), $attempts, time() + $delay, '', mb_substr( sanitize_text_field( $result->get_error_message() ), 0, 300 ), $row['id'], $token ) );
				}
			}
		} finally {
			self::$running = false;
			self::schedule();
		}
	}

	private static function schedule(): void {
		global $wpdb;
		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(GREATEST(available_at, locked_until)) FROM %i', self::table() ) );
		if ( null === $next ) {
			return;
		}
		$next      = max( time() + 60, (int) $next );
		$scheduled = wp_next_scheduled( 'shouse_queue' );
		if ( false !== $scheduled && $scheduled > $next + 60 ) {
			wp_unschedule_event( $scheduled, 'shouse_queue' );
			$scheduled = false;
		}
		if ( false === $scheduled ) {
			wp_schedule_single_event( $next, 'shouse_queue' );
		}
	}

	/** @return array{pending: int, failed: int, oldest: int} */
	public static function status(): array {
		global $wpdb;
		$row = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS pending, SUM(attempts > 0) AS failed, MIN(created_at) AS oldest FROM %i', self::table() ), ARRAY_A );
		return [
			'pending' => (int) ( $row['pending'] ?? 0 ),
			'failed'  => (int) ( $row['failed'] ?? 0 ),
			'oldest'  => (int) ( $row['oldest'] ?? 0 ),
		];
	}

	/**
	 * @param array<string, mixed> $tests Tests.
	 * @return array<string, mixed>
	 */
	public static function health_tests( array $tests ): array {
		$tests['direct']['shouse_queue'] = [
			'label' => __( 'SafeHouse delivery queue', 'shouse' ),
			'test'  => [ self::class, 'health' ],
		];
		return $tests;
	}

	/** @return array<string, mixed> */
	public static function health(): array {
		$status = self::status();
		$bad    = get_option( 'shouse_queue_storage_error' ) || $status['failed'] > 0 || ( $status['oldest'] > 0 && $status['oldest'] < time() - HOUR_IN_SECONDS );
		return [
			'label'       => $bad ? __( 'SafeHouse has undelivered work', 'shouse' ) : __( 'SafeHouse delivery queue is healthy', 'shouse' ),
			'status'      => $bad ? 'recommended' : 'good',
			'badge'       => [
				'label' => __( 'Security', 'shouse' ),
				'color' => 'blue',
			],
			/* translators: 1: pending jobs, 2: jobs awaiting retry. */
			'description' => '<p>' . esc_html( sprintf( __( 'Pending: %1$d. Awaiting retry: %2$d.', 'shouse' ), $status['pending'], $status['failed'] ) ) . '</p><p>' . esc_html__( 'Alerts and cache purges are retained until the transport accepts them. Check SMTP, Cloudflare credentials, database access and WP-Cron if work remains pending. Mail acceptance does not confirm inbox delivery.', 'shouse' ) . '</p>',
			'test'        => 'shouse_queue',
		];
	}
}
