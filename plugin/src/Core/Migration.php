<?php
/**
 * One-time move of a WPHouse install (the plugin's name before 0.2) to SafeHouse. Options,
 * transients, the two tables and scheduled events carry the shouse prefix now; rows are renamed in
 * place, so values, autoload flags and table contents stay exactly as they were.
 *
 * Runs on activation and early on every load until it has finished once. Each step checks before it
 * acts, so an interrupted run simply continues. Old WPHOUSE_* constants and the old safe mode file
 * keep working through src/legacy.php and SafeMode.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Migration {

	public const DONE = 'shouse_migrated_from_wphouse';

	private const OLD = 'wphouse_';

	private const NEW = 'shouse_';

	/** Option name prefixes to carry over: plain options and both transient kinds. */
	private const OPTION_PREFIXES = [ '', '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ];

	private const TABLES = [
		'wphouse_log'   => 'shouse_log',
		'wphouse_login' => 'shouse_login',
	];

	public static function run(): void {
		if ( get_option( self::DONE ) ) {
			return;
		}
		self::options();
		self::tables();
		self::events();
		update_option( self::DONE, time(), false );
	}

	private static function options(): void {
		global $wpdb;
		$renamed = false;
		foreach ( self::OPTION_PREFIXES as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix . self::OLD ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration.
			foreach ( $names as $old ) {
				$new   = $prefix . self::NEW . substr( $old, strlen( $prefix . self::OLD ) );
				$taken = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration.
				if ( $taken ) {
					$wpdb->delete( $wpdb->options, [ 'option_name' => $old ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the new row wins.
				} else {
					$wpdb->update( $wpdb->options, [ 'option_name' => $new ], [ 'option_name' => $old ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- renamed in place, no option hooks.
				}
				wp_cache_delete( $old, 'options' );
				wp_cache_delete( $new, 'options' );
				$renamed = true;
			}
		}
		if ( $renamed ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	private static function tables(): void {
		global $wpdb;
		foreach ( self::TABLES as $old => $new ) {
			$old = $wpdb->prefix . $old;
			$new = $wpdb->prefix . $new;
			if ( self::table_exists( $old ) && ! self::table_exists( $new ) ) {
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old, $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time migration.
			}
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time migration.
	}

	/** The new names are scheduled by Plugin; the old ones would only fire into nothing. */
	private static function events(): void {
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( array_keys( (array) $hooks ) as $hook ) {
				if ( str_starts_with( (string) $hook, self::OLD ) ) {
					wp_unschedule_hook( (string) $hook );
				}
			}
		}
	}
}
