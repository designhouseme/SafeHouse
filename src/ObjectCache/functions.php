<?php
/**
 * The wp_cache_*() API, backed by SafeHouse\ObjectCache\Cache. Signatures follow wp-includes/cache.php.
 * Only loaded once Redis has answered (see object-cache.php).
 *
 * @package SafeHouse
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- these are WordPress's object cache functions.

use SafeHouse\ObjectCache\Cache;

defined( 'ABSPATH' ) || exit;

function wp_cache_init(): void {
	$GLOBALS['wp_object_cache'] = new Cache( $GLOBALS['shouse_object_cache_redis'] ?? null, shouse_object_cache_prefix(), shouse_object_cache_secret() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- creating it is wp_cache_init()'s job.
}

/** Same rule as WP_Object_Cache::is_valid_key(). */
function shouse_object_cache_valid_key( mixed $key ): bool {
	if ( is_int( $key ) || ( is_string( $key ) && '' !== trim( $key ) ) ) {
		return true;
	}
	if ( function_exists( '_doing_it_wrong' ) ) {
		_doing_it_wrong( 'wp_cache_*', 'Cache key must be an integer or a non-empty string.', '6.1.0' );
	}
	return false;
}

function shouse_object_cache(): Cache {
	if ( ! ( $GLOBALS['wp_object_cache'] ?? null ) instanceof Cache ) {
		wp_cache_init();
	}
	return $GLOBALS['wp_object_cache'];
}

/**
 * @param int|string $key    Key.
 * @param mixed      $data   Value.
 * @param string     $group  Group.
 * @param int        $expire Seconds, 0 for no expiry.
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool {
	return shouse_object_cache_valid_key( $key ) && shouse_object_cache()->add( $key, $data, (string) $group, (int) $expire );
}

/**
 * @param array<int|string, mixed> $data   Key => value.
 * @param string                   $group  Group.
 * @param int                      $expire Seconds.
 * @return array<int|string, bool>
 */
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ): array {
	return shouse_object_cache()->add_multiple( $data, (string) $group, (int) $expire );
}

/**
 * @param int|string $key    Key.
 * @param mixed      $data   Value.
 * @param string     $group  Group.
 * @param int        $expire Seconds.
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ): bool {
	return shouse_object_cache_valid_key( $key ) && shouse_object_cache()->replace( $key, $data, (string) $group, (int) $expire );
}

/**
 * @param int|string $key    Key.
 * @param mixed      $data   Value.
 * @param string     $group  Group.
 * @param int        $expire Seconds.
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool {
	return shouse_object_cache_valid_key( $key ) && shouse_object_cache()->set( $key, $data, (string) $group, (int) $expire );
}

/**
 * @param array<int|string, mixed> $data   Key => value.
 * @param string                   $group  Group.
 * @param int                      $expire Seconds.
 * @return array<int|string, bool>
 */
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ): array {
	return shouse_object_cache()->set_multiple( $data, (string) $group, (int) $expire );
}

/**
 * @param int|string $key   Key.
 * @param string     $group Group.
 * @param bool       $force Skip the runtime copy.
 * @param bool|null  $found Set to whether the key was found.
 * @return mixed
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	if ( ! shouse_object_cache_valid_key( $key ) ) {
		$found = false;
		return false;
	}
	return shouse_object_cache()->get( $key, (string) $group, (bool) $force, $found );
}

/**
 * @param array<int, mixed> $keys  Keys; invalid ones (callers do pass them) get false, as in WP_Object_Cache.
 * @param string            $group Group.
 * @param bool              $force Skip the runtime copy.
 * @return array<int|string, mixed>
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ): array {
	$keys  = (array) $keys;
	$found = shouse_object_cache()->get_multiple( array_values( array_filter( $keys, 'shouse_object_cache_valid_key' ) ), (string) $group, (bool) $force );
	$out   = [];
	foreach ( $keys as $key ) {
		if ( is_scalar( $key ) || null === $key ) {
			$out[ (string) $key ] = $found[ (string) $key ] ?? false; // Like WP_Object_Cache: every requested key gets an entry.
		}
	}
	return $out;
}

/**
 * @param int|string $key   Key.
 * @param string     $group Group.
 */
function wp_cache_delete( $key, $group = '' ): bool {
	return shouse_object_cache_valid_key( $key ) && shouse_object_cache()->delete( $key, (string) $group );
}

/**
 * @param array<int, mixed> $keys  Keys; invalid ones get false.
 * @param string            $group Group.
 * @return array<int|string, bool>
 */
function wp_cache_delete_multiple( array $keys, $group = '' ): array {
	$done = shouse_object_cache()->delete_multiple( array_values( array_filter( $keys, 'shouse_object_cache_valid_key' ) ), (string) $group );
	$out  = [];
	foreach ( $keys as $key ) {
		if ( is_scalar( $key ) || null === $key ) {
			$out[ (string) $key ] = $done[ (string) $key ] ?? false;
		}
	}
	return $out;
}

/**
 * @param int|string $key    Key.
 * @param int        $offset Amount.
 * @param string     $group  Group.
 * @return int|false
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return shouse_object_cache_valid_key( $key ) ? shouse_object_cache()->incr( $key, (int) $offset, (string) $group ) : false;
}

/**
 * @param int|string $key    Key.
 * @param int        $offset Amount.
 * @param string     $group  Group.
 * @return int|false
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return shouse_object_cache_valid_key( $key ) ? shouse_object_cache()->decr( $key, (int) $offset, (string) $group ) : false;
}

function wp_cache_flush(): bool {
	return shouse_object_cache()->flush();
}

function wp_cache_flush_runtime(): bool {
	return shouse_object_cache()->flush_runtime();
}

/**
 * @param string $group Group.
 */
function wp_cache_flush_group( $group ): bool {
	return shouse_object_cache()->flush_group( (string) $group );
}

/**
 * @param string $feature Feature name.
 */
function wp_cache_supports( $feature ): bool {
	return in_array( $feature, [ 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ], true );
}

function wp_cache_close(): bool {
	return shouse_object_cache()->close();
}

/**
 * @param string|array<int, string> $groups Groups.
 */
function wp_cache_add_global_groups( $groups ): void {
	shouse_object_cache()->add_global_groups( $groups );
}

/**
 * @param string|array<int, string> $groups Groups.
 */
function wp_cache_add_non_persistent_groups( $groups ): void {
	shouse_object_cache()->add_non_persistent_groups( $groups );
}

/**
 * @param int $blog_id Site ID.
 */
function wp_cache_switch_to_blog( $blog_id ): void {
	shouse_object_cache()->switch_to_blog( (int) $blog_id );
}

/** Deprecated in WordPress 3.5, kept for old callers. */
function wp_cache_reset(): bool {
	if ( function_exists( '_deprecated_function' ) ) {
		_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
	}
	return false;
}
