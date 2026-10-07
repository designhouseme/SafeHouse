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
 * recovery drops this site's keys before reading any and removes only the marker it actually saw.
 * The database generation prevents older in-flight requests from refilling the recovered namespace.
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
	shouse_object_cache_mark_stale();
	return;
}

require_once __DIR__ . '/Cache.php';

$shouse_guard_ok = false;
if ( is_object( $shouse_db ) ) {
	$shouse_table    = shouse_object_cache_state_table();
	$shouse_suppress = $shouse_db->suppress_errors( true );
	$shouse_stale    = $shouse_db->get_var( $shouse_db->prepare( "SELECT option_value FROM {$shouse_table} WHERE option_name = %s", 'shouse_object_cache_stale' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$shouse_guard_ok = '' === $shouse_db->last_error;
	if ( $shouse_guard_ok && null !== $shouse_stale ) {
		$shouse_guard    = new SafeHouse\ObjectCache\Cache( $shouse_redis, shouse_object_cache_prefix(), shouse_object_cache_secret() );
		$shouse_guard_ok = false !== shouse_object_cache_generation( true ) && $shouse_guard->flush();
		if ( $shouse_guard_ok ) {
			$shouse_guard_ok = 1 === $shouse_db->query( $shouse_db->prepare( "DELETE FROM {$shouse_table} WHERE option_name = %s AND option_value = %s", 'shouse_object_cache_stale', $shouse_stale ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- do not erase a concurrent failed request's marker.
		} else {
			$GLOBALS['shouse_object_cache_error'] = $shouse_guard->last_error();
		}
	}
	$shouse_db->suppress_errors( $shouse_suppress );
}

$shouse_generation = $shouse_guard_ok ? shouse_object_cache_generation() : false;
if ( false === $shouse_generation ) {
	$GLOBALS['shouse_object_cache_error'] ??= 'The object cache recovery guard could not be completed.';
	shouse_object_cache_mark_stale();
	try {
		$shouse_redis->close();
	} catch ( Throwable ) {
		// A failed connection must never stop WordPress from loading its native cache.
		unset( $shouse_redis );
	}
	return; // No alias/functions: WordPress must be free to load its native cache and database transients.
}

if ( ! class_exists( 'WP_Object_Cache', false ) ) {
	class_alias( SafeHouse\ObjectCache\Cache::class, 'WP_Object_Cache' ); // Code that checks `instanceof WP_Object_Cache` keeps working.
}

$GLOBALS['shouse_object_cache_redis']      = $shouse_redis;
$GLOBALS['shouse_object_cache_generation'] = $shouse_generation;
unset( $shouse_redis, $shouse_redis_error, $shouse_db, $shouse_suppress, $shouse_stale, $shouse_table, $shouse_guard, $shouse_guard_ok, $shouse_generation );

require_once __DIR__ . '/functions.php';
