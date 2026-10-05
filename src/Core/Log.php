<?php
/**
 * Security event log in its own table. Only state changes and admin actions are logged,
 * never anonymous traffic, so a scan cannot turn into a flood of database writes.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Log {

	public const DB_VERSION     = '1';
	public const RETENTION_DAYS = 90;

	private const SECRET_KEYS = '/pass|secret|token|key|auth|cookie|nonce|hash|salt/i';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wphouse_log';
	}

	/** Create or upgrade the table. Runs on activation and whenever the stored version differs. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				event varchar(64) NOT NULL,
				severity varchar(10) NOT NULL DEFAULT 'info',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ip varchar(45) NOT NULL DEFAULT '',
				message text NOT NULL,
				context longtext NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY event (event)
			) {$collate};"
		);
		update_option( 'wphouse_db_version', self::DB_VERSION, true );
	}

	public static function maybe_install(): void {
		if ( get_option( 'wphouse_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Record an event. Context values whose keys look like secrets are redacted.
	 *
	 * @param string               $event    Machine name, e.g. "admin_created".
	 * @param string               $message  Human-readable English summary.
	 * @param array<string, mixed> $context  Extra data, stored as JSON.
	 * @param string               $severity info|warning|critical.
	 */
	public static function add( string $event, string $message, array $context = [], string $severity = 'info' ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- own table.
			self::table(),
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'event'      => substr( $event, 0, 64 ),
				'severity'   => in_array( $severity, [ 'info', 'warning', 'critical' ], true ) ? $severity : 'info',
				'user_id'    => get_current_user_id(),
				'ip'         => Net::client_ip(),
				'message'    => mb_substr( $message, 0, 2000 ),
				'context'    => $context ? wp_json_encode( self::redact( $context ) ) : null,
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * @param int $limit Number of rows.
	 * @return array<int, object{id: string, created_at: string, event: string, severity: string, user_id: string, ip: string, message: string, context: ?string}>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		// Own table, always read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), max( 1, min( 500, $limit ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : [];
	}

	public static function purge(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param array<mixed> $data Context data.
	 * @return array<mixed>
	 */
	private static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SECRET_KEYS, $key ) ) {
				$data[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}
}
