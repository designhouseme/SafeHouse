<?php
/**
 * WPHouse object cache loader (Redis).
 *
 * Installed by `wp wphouse object-cache enable` and removed by `wp wphouse object-cache disable` or
 * when WPHouse is deactivated. It only loads the cache from the WPHouse plugin, so plugin updates
 * update the cache too. WordPress uses its own cache instead when WPHouse safe mode is on,
 * WPHOUSE_OBJECT_CACHE or WP_REDIS_DISABLED says so, the plugin is missing or Redis is down.
 *
 * Whenever this request runs without the cache, writes reach only the database, so the loader leaves
 * a row in the options table. The next request with the cache drops the site's Redis keys before
 * reading any (see object-cache.php). Self-contained on purpose: it must work without the plugin.
 *
 * @package WPHouse
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WP_REDIS_DISABLED belongs to the Redis ecosystem.

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$file = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/__WPHOUSE_FOLDER__/src/ObjectCache/object-cache.php';
	$off  = ( defined( 'WPHOUSE_OBJECT_CACHE' ) && ! WPHOUSE_OBJECT_CACHE ) || ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED )
		|| ( defined( 'WPHOUSE_SAFE_MODE' ) && WPHOUSE_SAFE_MODE ) || file_exists( WP_CONTENT_DIR . '/wphouse-safe-mode' ) || ! is_readable( $file );
	if ( ! $off ) {
		require $file;
		return;
	}
	$db = $GLOBALS['wpdb'] ?? null;
	if ( is_object( $db ) ) {
		$suppress = $db->suppress_errors( true );
		$db->query( $db->prepare( "INSERT IGNORE INTO {$db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", 'wphouse_object_cache_stale', (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- runs before any cache exists.
		$db->suppress_errors( $suppress );
	}
} )();
