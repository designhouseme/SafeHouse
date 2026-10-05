<?php
/**
 * Removes everything WPHouse stored. WPHouse never writes to .htaccess or wp-config.php,
 * so there is nothing to undo on disk except the optional safe-mode flag file.
 *
 * @package WPHouse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wphouse_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'wphouse_' ) . '%',
		$wpdb->esc_like( '_transient_wphouse_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wphouse_' ) . '%',
		$wpdb->esc_like( '_site_transient_wphouse_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_wphouse_' ) . '%'
	)
);
// phpcs:enable

wp_clear_scheduled_hook( 'wphouse_hourly' );
wp_clear_scheduled_hook( 'wphouse_daily' );

$wphouse_flag = WP_CONTENT_DIR . '/wphouse-safe-mode';
if ( file_exists( $wphouse_flag ) ) {
	wp_delete_file( $wphouse_flag );
}
