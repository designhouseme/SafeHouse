<?php
/**
 * Durable, at-least-once jobs. Payloads never contain transport credentials.
 * Claims and rate reservations use SQL, independently of the object cache.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use Throwable;
use WP_CLI;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the queue must bypass cache and claim rows atomically.
final class Queue {

	private const VERSION      = '2';
	private const MAX_ATTEMPTS = 5;
	private const LEASE        = 600;
	private const WORKER       = 'shouse_queue_worker';
	/** @var array<string, callable(array<string, mixed>): (true|WP_Error)> */
	private static array $handlers = [];
	private static bool $running   = false;

	public static function boot(): void {
		self::install();
		add_action( 'shouse_queue', [ self::class, 'run' ] );
		add_action( 'shouse_hourly', [ self::class, 'run' ] );
		add_filter( 'site_status_tests', [ self::class, 'health_tests' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse queue', QueueCommand::class );
		}
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
				paused tinyint unsigned NOT NULL DEFAULT 0,
				available_at bigint(20) unsigned NOT NULL,
				locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
				lock_token varchar(36) NOT NULL DEFAULT '',
				created_at bigint(20) unsigned NOT NULL,
				last_error varchar(300) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY ready (queue,available_at,locked_until),
				KEY handler_dispatch (queue,paused,available_at,id),
				KEY dispatch (paused,available_at,id)
			) {$wpdb->get_charset_collate()};"
		);
		if ( '' === $wpdb->last_error && $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			// New claims put their lease into available_at, making the next wake-up indexable.
			if ( false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET available_at = locked_until WHERE locked_until > available_at', $table ) ) ) {
				update_option( 'shouse_queue_db_version', self::VERSION, false );
			}
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
		if ( ! isset( self::$handlers[ $name ] ) || strlen( $name ) > 32 || false === $json || strlen( $json ) > MB_IN_BYTES ) {
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
		self::schedule( time() + 60 ); // The new job's due time is known: do not scan the backlog.
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

	/**
	 * One cooperative worker per site. The job limit is shared by all handlers.
	 * A callback cannot be preempted; leases recover crashed workers, not arbitrary hung PHP.
	 */
	public static function run( string $only = '', int $limit = 10 ): void {
		if ( self::$running ) {
			return;
		}
		global $wpdb;
		$owner = self::acquire();
		if ( '' === $owner ) {
			return;
		}
		$limit         = max( 1, min( 20, $limit ) );
		$budget        = new WorkBudget();
		self::$running = true;
		try {
			for ( $i = 0; $i < $limit; ++$i ) {
				if ( $budget->exhausted() ) {
					break;
				}
				$now = time();
				$row = '' === $only
					? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE paused = 0 AND available_at <= %d AND locked_until <= %d ORDER BY available_at, id LIMIT 1', self::table(), $now, $now ), ARRAY_A )
					: $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE queue = %s AND paused = 0 AND available_at <= %d AND locked_until <= %d ORDER BY available_at, id LIMIT 1', self::table(), $only, $now, $now ), ARRAY_A );
				if ( ! is_array( $row ) || $budget->exhausted() ) {
					break;
				}
				// Do not begin another callback if an expired worker has lost ownership.
				$renewed = (string) ( $now + self::LEASE ) . ':' . wp_generate_uuid4();
				if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $renewed, self::WORKER, $owner ) ) ) {
					break;
				}
				$owner = $renewed;
				$name  = (string) $row['queue'];
				if ( ! isset( self::$handlers[ $name ] ) || (int) $row['attempts'] >= self::MAX_ATTEMPTS ) {
					$reason = isset( self::$handlers[ $name ] ) ? 'Retry budget exhausted. Review the failure and resume explicitly.' : 'Handler unavailable. Enable its module and resume explicitly.';
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET paused = 1, locked_until = 0, lock_token = %s, last_error = %s WHERE id = %d AND paused = 0 AND locked_until <= %d', self::table(), '', $reason, $row['id'], $now ) );
					continue;
				}
				$token = wp_generate_uuid4();
				// Count before entering plugin/transport code: fatal failures consume the retry budget too.
				if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = attempts + 1, available_at = %d, locked_until = %d, lock_token = %s WHERE id = %d AND paused = 0 AND available_at <= %d AND locked_until <= %d', self::table(), $now + self::LEASE, $now + self::LEASE, $token, $row['id'], $now, $now ) ) ) {
					continue;
				}
				$payload = json_decode( $row['payload'], true );
				if ( $budget->exhausted() ) {
					// The callback has not started: yield without charging a delivery failure.
					$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = %d, available_at = %d, locked_until = 0, lock_token = %s WHERE id = %d AND lock_token = %s', self::table(), (int) $row['attempts'], time() + 60, '', $row['id'], $token ) );
					break;
				}
				// Persist a recovery wake-up before code that may terminate PHP without finally.
				self::schedule( $now + self::LEASE );
				try {
					$result = is_array( $payload ) ? ( self::$handlers[ $name ] )( $payload ) : new WP_Error( 'queue_payload', 'Invalid queue payload.', [ 'permanent' => true ] );
				} catch ( Throwable $error ) {
					// Exceptions may include credentials or mail content. Store only the class.
					$result = new WP_Error( 'queue_exception', 'Delivery raised ' . get_class( $error ) );
				}
				if ( true === $result ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND lock_token = %s', self::table(), $row['id'], $token ) );
					continue;
				}
				$data     = $result->get_error_data();
				$attempts = (int) $row['attempts'] + ( is_array( $data ) && ! empty( $data['deferred'] ) ? 0 : 1 );
				$paused   = $attempts >= self::MAX_ATTEMPTS || ( is_array( $data ) && ! empty( $data['permanent'] ) );
				$delay    = is_array( $data ) && isset( $data['retry_after'] ) ? max( 30, min( DAY_IN_SECONDS, (int) $data['retry_after'] ) ) : min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 6, max( 0, $attempts - 1 ) ) ) ) + wp_rand( 0, 30 );
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = %d, paused = %d, available_at = %d, locked_until = 0, lock_token = %s, last_error = %s WHERE id = %d AND lock_token = %s', self::table(), $attempts, (int) $paused, time() + $delay, '', mb_substr( sanitize_text_field( $result->get_error_message() ), 0, 300 ), $row['id'], $token ) );
			}
		} finally {
			self::$running = false;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::WORKER, $owner ) );
			self::schedule();
		}
	}

	/** SQL ownership token; deleting or renewing a lease always compares its complete value. */
	private static function acquire(): string {
		global $wpdb;
		$now   = time();
		$owner = (string) ( $now + self::LEASE ) . ':' . wp_generate_uuid4();
		if ( 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::WORKER, $owner ) ) ) {
			return $owner;
		}
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d", $owner, self::WORKER, $now ) ) ? $owner : '';
	}

	/** Resume retained jobs only on an explicit operator request, at most twenty at a time. */
	public static function resume( int $limit = 20 ): int {
		global $wpdb;
		if ( ! self::$handlers ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( self::$handlers ), '%s' ) );
		$parameters   = array_merge( [ self::table() ], array_keys( self::$handlers ), [ time(), max( 1, min( 20, $limit ) ) ] );
		$ids          = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE paused = 1 AND queue IN ($placeholders) AND locked_until <= %d ORDER BY id LIMIT %d", $parameters ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$resumed      = 0;
		foreach ( $ids as $id ) {
			$resumed += max( 0, (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET paused = 0, attempts = 0, available_at = %d, locked_until = 0, lock_token = %s WHERE id = %d AND paused = 1 AND locked_until <= %d', self::table(), time(), '', (int) $id, time() ) ) );
		}
		if ( $resumed > 0 ) {
			self::schedule( time() + 60 );
		}
		return $resumed;
	}

	private static function schedule( ?int $next = null ): void {
		global $wpdb;
		if ( null === $next ) {
			// dispatch(paused,available_at,id) gives one indexed row; paused jobs never wake a worker.
			$due = $wpdb->get_var( $wpdb->prepare( 'SELECT available_at FROM %i WHERE paused = 0 ORDER BY available_at, id LIMIT 1', self::table() ) );
			if ( null === $due ) {
				return;
			}
			$next = (int) $due;
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

	/** @return array{pending: int, failed: int, paused: int, attempts: int, oldest: int, storage_available: bool} */
	public static function status(): array {
		global $wpdb;
		$row = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS pending, SUM(attempts > 0) AS failed, SUM(paused > 0) AS paused, SUM(attempts) AS attempts, MIN(created_at) AS oldest FROM %i', self::table() ), ARRAY_A );
		return [
			'storage_available' => '' === $wpdb->last_error,
			'pending'           => (int) ( $row['pending'] ?? 0 ),
			'failed'            => (int) ( $row['failed'] ?? 0 ),
			'paused'            => (int) ( $row['paused'] ?? 0 ),
			'attempts'          => (int) ( $row['attempts'] ?? 0 ),
			'oldest'            => (int) ( $row['oldest'] ?? 0 ),
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
		$bad    = ! $status['storage_available'] || get_option( 'shouse_queue_storage_error' ) || $status['paused'] > 0 || $status['failed'] > 0 || $status['pending'] > 1000 || ( $status['oldest'] > 0 && $status['oldest'] < time() - HOUR_IN_SECONDS );
		return [
			'label'       => $bad ? __( 'SafeHouse has undelivered work', 'shouse' ) : __( 'SafeHouse delivery queue is healthy', 'shouse' ),
			'status'      => $bad ? 'recommended' : 'good',
			'badge'       => [
				'label' => __( 'Security', 'shouse' ),
				'color' => 'blue',
			],
			/* translators: 1: retained jobs, 2: jobs with previous attempts, 3: paused jobs. */
			'description' => '<p>' . esc_html( sprintf( __( 'Pending: %1$d. Previously attempted: %2$d. Paused: %3$d.', 'shouse' ), $status['pending'], $status['failed'], $status['paused'] ) ) . '</p><p>' . esc_html__( 'Failed jobs are retained and pause after five attempts. Unavailable handlers also pause. Correct the cause, then resume a bounded batch in Stability or with wp shouse queue resume. Check SMTP, Cloudflare credentials, database access and WP-Cron. Mail acceptance does not confirm inbox delivery.', 'shouse' ) . '</p>',
			'test'        => 'shouse_queue',
		];
	}
}
