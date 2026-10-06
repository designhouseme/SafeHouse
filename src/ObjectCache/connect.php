<?php
/**
 * Redis connection and key settings for the SafeHouse object cache, shared by the drop-in and WP-CLI.
 * Reads the same constants as the Redis Object Cache plugin, so a site can switch by swapping drop-ins:
 * WP_REDIS_SCHEME (tcp, tls or unix), WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PATH, WP_REDIS_PASSWORD
 * (a string, or [ user, password ]), WP_REDIS_DATABASE, WP_REDIS_TIMEOUT, WP_REDIS_READ_TIMEOUT,
 * WP_REDIS_PREFIX or WP_CACHE_KEY_SALT. Our keys add "wph:" after the prefix, so this cache and that
 * plugin never read each other's data.
 *
 * @package SafeHouse
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- reads the WP_REDIS_* constants of the Redis ecosystem.

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'shouse_object_cache_connect' ) ) {

	/**
	 * Connect and ping. Never throws: returns null and the reason when Redis is not usable.
	 *
	 * @return array{0: Redis|null, 1: string}
	 */
	function shouse_object_cache_connect(): array {
		if ( ! class_exists( 'Redis' ) ) {
			return [ null, 'The PhpRedis extension is not installed.' ];
		}
		$scheme  = defined( 'WP_REDIS_SCHEME' ) ? strtolower( (string) WP_REDIS_SCHEME ) : 'tcp';
		$host    = defined( 'WP_REDIS_HOST' ) ? (string) WP_REDIS_HOST : '127.0.0.1';
		$port    = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;
		$timeout = defined( 'WP_REDIS_TIMEOUT' ) ? (float) WP_REDIS_TIMEOUT : 1.0;
		$read    = defined( 'WP_REDIS_READ_TIMEOUT' ) ? (float) WP_REDIS_READ_TIMEOUT : 1.0;
		try {
			$redis = new Redis();
			if ( 'unix' === $scheme ) {
				$ok = $redis->connect( defined( 'WP_REDIS_PATH' ) ? (string) WP_REDIS_PATH : '/var/run/redis/redis.sock' );
			} else {
				$ok = $redis->connect( ( 'tls' === $scheme ? 'tls://' : '' ) . $host, $port, $timeout, null, 0, $read );
			}
			if ( ! $ok ) {
				return [ null, 'Could not connect to Redis.' ];
			}
			if ( defined( 'WP_REDIS_PASSWORD' ) && '' !== WP_REDIS_PASSWORD && [] !== WP_REDIS_PASSWORD ) {
				$redis->auth( WP_REDIS_PASSWORD );
			}
			if ( defined( 'WP_REDIS_DATABASE' ) && (int) WP_REDIS_DATABASE > 0 ) {
				$redis->select( (int) WP_REDIS_DATABASE );
			}
			$redis->ping();
			return [ $redis, '' ];
		} catch ( Throwable $e ) {
			return [ null, $e->getMessage() ];
		}
	}

	/** Key prefix: the configured one, or one derived from this install so sites sharing a Redis stay apart. */
	function shouse_object_cache_prefix(): string {
		if ( defined( 'WP_REDIS_PREFIX' ) && '' !== (string) WP_REDIS_PREFIX ) {
			$prefix = (string) WP_REDIS_PREFIX;
		} elseif ( defined( 'WP_CACHE_KEY_SALT' ) && '' !== (string) WP_CACHE_KEY_SALT ) {
			$prefix = (string) WP_CACHE_KEY_SALT;
		} else {
			$prefix = substr( sha1( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( $GLOBALS['table_prefix'] ?? '' ) . '|' . ABSPATH ), 0, 12 ) . ':';
		}
		return $prefix . 'wph:';
	}

	/** Signing key for cached values, derived from the site's secret keys. */
	function shouse_object_cache_secret(): string {
		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'NONCE_KEY', 'DB_PASSWORD' ] as $constant ) {
			$material .= defined( $constant ) ? (string) constant( $constant ) : '';
		}
		return hash_hmac( 'sha256', 'shouse-object-cache|' . shouse_object_cache_prefix(), $material, true );
	}
}
