<?php
/**
 * WPHouse object cache, loaded by the wp-content/object-cache.php loader that
 * `wp wphouse object-cache enable` installs (template: loader.php).
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
 * @package WPHouse
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/connect.php';

[ $wphouse_redis, $wphouse_redis_error ] = wphouse_object_cache_connect();
$wphouse_db                              = $GLOBALS['wpdb'] ?? null;

if ( null === $wphouse_redis ) {
	$GLOBALS['wphouse_object_cache_error'] = $wphouse_redis_error;
	if ( is_object( $wphouse_db ) ) {
		$wphouse_suppress = $wphouse_db->suppress_errors( true );
		$wphouse_db->query( $wphouse_db->prepare( "INSERT IGNORE INTO {$wphouse_db->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", 'wphouse_object_cache_stale', (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- runs before the cache exists.
		$wphouse_db->suppress_errors( $wphouse_suppress );
	}
	return;
}

require_once __DIR__ . '/Cache.php';

if ( is_object( $wphouse_db ) ) {
	$wphouse_suppress = $wphouse_db->suppress_errors( true );
	$wphouse_stale    = $wphouse_db->get_var( $wphouse_db->prepare( "SELECT option_value FROM {$wphouse_db->options} WHERE option_name = %s", 'wphouse_object_cache_stale' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( null !== $wphouse_stale ) {
		( new WPHouse\ObjectCache\Cache( $wphouse_redis, wphouse_object_cache_prefix(), wphouse_object_cache_secret() ) )->flush();
		$wphouse_db->query( $wphouse_db->prepare( "DELETE FROM {$wphouse_db->options} WHERE option_name = %s", 'wphouse_object_cache_stale' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$wphouse_db->suppress_errors( $wphouse_suppress );
}

$GLOBALS['wphouse_object_cache_redis'] = $wphouse_redis;
unset( $wphouse_redis, $wphouse_redis_error, $wphouse_db, $wphouse_suppress, $wphouse_stale );

require_once __DIR__ . '/functions.php';
