<?php
/**
 * Removes everything SafeHouse stored. SafeHouse never writes to .htaccess or wp-config.php,
 * so there is nothing to undo on disk except the optional safe-mode flag file.
 *
 * @package SafeHouse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shouse_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shouse_login" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shouse_price_history" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shouse_jobs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shouse_incidents" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}shouse_form_guard" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
// Also the names from before the rename to SafeHouse, in case the plugin is removed before it ever ran.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wphouse_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wphouse_login" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
foreach ( [ 'shouse_', 'wphouse_' ] as $shouse_prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( $shouse_prefix ) . '%',
			$wpdb->esc_like( '_transient_' . $shouse_prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $shouse_prefix ) . '%',
			$wpdb->esc_like( '_site_transient_' . $shouse_prefix ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' . $shouse_prefix ) . '%'
		)
	);
}
// phpcs:enable

wp_clear_scheduled_hook( 'shouse_hourly' );
wp_clear_scheduled_hook( 'shouse_daily' );
wp_clear_scheduled_hook( 'shouse_queue' );
wp_clear_scheduled_hook( 'shouse_cloudflare_purge' );
wp_clear_scheduled_hook( 'shouse_watch_continue' );

// Our object cache loader, if deactivation did not remove it already. Another plugin's drop-in is left alone.
$shouse_dropin = WP_CONTENT_DIR . '/object-cache.php';
$shouse_head   = file_exists( $shouse_dropin ) ? (string) file_get_contents( $shouse_dropin, false, null, 0, 1024 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
if ( str_contains( $shouse_head, 'SafeHouse object cache loader' ) || str_contains( $shouse_head, 'WPHouse object cache loader' ) ) {
	wp_delete_file( $shouse_dropin );
}

foreach ( [ 'shouse-safe-mode', 'wphouse-safe-mode' ] as $shouse_flag ) {
	if ( file_exists( WP_CONTENT_DIR . '/' . $shouse_flag ) ) {
		wp_delete_file( WP_CONTENT_DIR . '/' . $shouse_flag );
	}
}
