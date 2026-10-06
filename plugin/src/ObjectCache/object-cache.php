<?php
/**
 * SafeHouse object cache, loaded by the wp-content/object-cache.php loader that
 * `wp shouse object-cache enable` installs (template: loader.php).
 *
 * If Redis is not usable right now, this file defines nothing: WordPress then sees no external
 * object cache and uses its own, with transients in the database. Nothing is lost and nothing breaks.
 *
 * Stale data guard: while Redis is unusable, writes go to the database only, so whatever Redis still
 * holds (if it keeps data across restarts) may be out of date when it comes back. Every request that
 * runs without Redis (including WP-CLI on a PHP without PhpRedis) leaves a row in the options table;
 * the first request that reaches Redis again drops this site's keys before reading any, then removes
 * the row. The check is one primary-key lookup per request.
 *
 * @package SafeHouse
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/legacy.php'; // Starts before the plugin: old WPHOUSE_* constants must count here too.
require_once __DIR__ . '/connect.php';

[ $shouse_redis, $shouse_redis_error ] = shouse_object_cache_connect();
$shouse_db                             = $GLOBALS['wpdb'] ?? null;

if ( null === $shouse_redis ) {
	$GLOBALS['shouse_object_cache_error'] = $shouse_redis_error;
	if ( is_object( $shouse_db ) ) {
		$shouse_suppress = $shouse_db->suppress_errors( true );
		$shouse_db->query( $shouse_db->prepare( "INSERT IGNORE INTO {$shouse_db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", 'shouse_object_cache_stale', (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- runs before the cache exists.
		$shouse_db->suppress_errors( $shouse_suppress );
	}
	return;
}

require_once __DIR__ . '/Cache.php';
if ( ! class_exists( 'WP_Object_Cache', false ) ) {
	class_alias( SafeHouse\ObjectCache\Cache::class, 'WP_Object_Cache' ); // Code that checks `instanceof WP_Object_Cache` keeps working.
}

if ( is_object( $shouse_db ) ) {
	$shouse_suppress = $shouse_db->suppress_errors( true );
	$shouse_stale    = $shouse_db->get_var( $shouse_db->prepare( "SELECT option_value FROM {$shouse_db->options} WHERE option_name = %s", 'shouse_object_cache_stale' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( null !== $shouse_stale ) {
		( new SafeHouse\ObjectCache\Cache( $shouse_redis, shouse_object_cache_prefix(), shouse_object_cache_secret() ) )->flush();
		$shouse_db->query( $shouse_db->prepare( "DELETE FROM {$shouse_db->options} WHERE option_name = %s", 'shouse_object_cache_stale' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$shouse_db->suppress_errors( $shouse_suppress );
}

$GLOBALS['shouse_object_cache_redis'] = $shouse_redis;
unset( $shouse_redis, $shouse_redis_error, $shouse_db, $shouse_suppress, $shouse_stale );

require_once __DIR__ . '/functions.php';
