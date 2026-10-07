<?php
/**
 * SafeHouse object cache loader (Redis).
 *
 * Installed by `wp shouse object-cache enable` and removed by `wp shouse object-cache disable` or
 * when SafeHouse is deactivated. It only loads the cache from the SafeHouse plugin, so plugin updates
 * update the cache too. WordPress uses its own cache instead when SafeHouse safe mode is on,
 * SHOUSE_OBJECT_CACHE or WP_REDIS_DISABLED says so, the plugin is missing or Redis is down.
 *
 * Whenever this request runs without the cache, writes reach only the database, so the loader leaves
 * a row in the options table. The next request with the cache drops the site's Redis keys before
 * reading any (see object-cache.php). Self-contained on purpose: it must work without the plugin.
 *
 * @package SafeHouse
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WP_REDIS_DISABLED belongs to the Redis ecosystem.

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$file = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' ) . '/__SHOUSE_FOLDER__/src/ObjectCache/object-cache.php';
	// WPHOUSE_* and wphouse-safe-mode are the names from before the rename to SafeHouse; both still count.
	$off = ( defined( 'SHOUSE_OBJECT_CACHE' ) && ! SHOUSE_OBJECT_CACHE ) || ( defined( 'WPHOUSE_OBJECT_CACHE' ) && ! WPHOUSE_OBJECT_CACHE ) || ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED )
		|| ( defined( 'SHOUSE_SAFE_MODE' ) && SHOUSE_SAFE_MODE ) || ( defined( 'WPHOUSE_SAFE_MODE' ) && WPHOUSE_SAFE_MODE )
		|| file_exists( WP_CONTENT_DIR . '/shouse-safe-mode' ) || file_exists( WP_CONTENT_DIR . '/wphouse-safe-mode' ) || ! is_readable( $file );
	if ( ! $off ) {
		require $file;
		return;
	}
	$db = $GLOBALS['wpdb'] ?? null;
	if ( is_object( $db ) ) {
		$mark = static function () use ( $db ): void {
			$table    = $db->base_prefix . 'options';
			$token    = bin2hex( random_bytes( 16 ) );
			$suppress = $db->suppress_errors( true );
			$db->query( $db->prepare( "INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'off'), (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", 'shouse_object_cache_stale', $token, 'shouse_object_cache_generation', $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- runs before any cache exists; trusted generation isolates in-flight requests.
			$db->suppress_errors( $suppress );
		};
		$mark();
		register_shutdown_function( $mark );
	}
} )();
