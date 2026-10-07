<?php
/**
 * Redis connection and key settings for the SafeHouse object cache, shared by the drop-in and WP-CLI.
 * Reads the same constants as the Redis Object Cache plugin, so a site can switch by swapping drop-ins:
 * WP_REDIS_SCHEME (tcp, tls or unix), WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PATH, WP_REDIS_PASSWORD
 * (a string, or [ user, password ]), WP_REDIS_DATABASE, WP_REDIS_TIMEOUT, WP_REDIS_READ_TIMEOUT,
 * WP_REDIS_PREFIX or WP_CACHE_KEY_SALT. A versioned, installation-specific namespace is appended,
 * so this cache never reads another plugin's format or accidentally reuses another site's prefix.
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
		if ( ! shouse_object_cache_has_secret() ) {
			return [ null, 'The object cache requires at least one non-default WordPress authentication key.' ];
		}
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
				if ( ! $redis->auth( WP_REDIS_PASSWORD ) ) {
					return [ null, 'Redis authentication failed.' ];
				}
			}
			if ( defined( 'WP_REDIS_DATABASE' ) && (int) WP_REDIS_DATABASE > 0 ) {
				if ( ! $redis->select( (int) WP_REDIS_DATABASE ) ) {
					return [ null, 'Redis database selection failed.' ];
				}
			}
			if ( ! $redis->ping() ) {
				return [ null, 'Redis did not answer PING.' ];
			}
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
			$prefix = '';
		}
		$identity = [ ABSPATH, (string) ( $GLOBALS['table_prefix'] ?? '' ) ];
		foreach ( [ 'DB_HOST', 'DB_NAME', 'AUTH_KEY', 'SECURE_AUTH_KEY' ] as $constant ) {
			$identity[] = defined( $constant ) ? (string) constant( $constant ) : '';
		}
		// The identity remains part of the namespace even when operators reuse a configured prefix.
		return $prefix . 'shouse:v2:' . substr( hash( 'sha256', serialize( $identity ) ), 0, 32 ) . ':'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- unambiguous local identity, never decoded.
	}

	/** Signing key for cached values, derived from the site's secret keys. */
	function shouse_object_cache_secret(): string {
		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ] as $constant ) {
			$material .= defined( $constant ) ? (string) constant( $constant ) : '';
		}
		return hash_hmac( 'sha256', 'shouse-object-cache|' . shouse_object_cache_prefix(), $material, true );
	}

	/** Reject the known empty/default configuration; this cannot measure an operator's key entropy. */
	function shouse_object_cache_has_secret(): bool {
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ] as $constant ) {
			$value = defined( $constant ) ? trim( (string) constant( $constant ) ) : '';
			if ( '' !== $value && 'put your unique phrase here' !== $value ) {
				return true;
			}
		}
		return false;
	}

	/** The shared installation's options table, also after switch_to_blog(). */
	function shouse_object_cache_state_table(): string {
		$db = $GLOBALS['wpdb'] ?? null;
		return is_object( $db ) ? $db->base_prefix . 'options' : '';
	}

	/**
	 * Mark degraded requests in the database, never Redis. A new generation makes writes from older
	 * in-flight requests unreachable. Repeat at shutdown because database writes may follow this call.
	 */
	function shouse_object_cache_mark_stale( bool $at_shutdown = false ): void {
		$db = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $db ) ) {
			return;
		}
		$table    = shouse_object_cache_state_table();
		$token    = bin2hex( random_bytes( 16 ) );
		$suppress = $db->suppress_errors( true );
		$db->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cache failure must not recurse through option caches.
			$db->prepare(
				"INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'off'), (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				'shouse_object_cache_stale',
				$token,
				'shouse_object_cache_generation',
				$token
			)
		);
		$db->suppress_errors( $suppress );
		if ( ! $at_shutdown && empty( $GLOBALS['shouse_object_cache_shutdown_guard'] ) ) {
			$GLOBALS['shouse_object_cache_shutdown_guard'] = true;
			register_shutdown_function( 'shouse_object_cache_mark_stale', true );
		}
	}

	/** Return the trusted generation, or false when the database guard cannot be read or written. */
	function shouse_object_cache_generation( bool $rotate = false ): string|false {
		$db = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $db ) ) {
			return false;
		}
		$table    = shouse_object_cache_state_table();
		$suppress = $db->suppress_errors( true );
		if ( $rotate && false === $db->query( $db->prepare( "INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", 'shouse_object_cache_generation', bin2hex( random_bytes( 16 ) ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$db->suppress_errors( $suppress );
			return false;
		}
		$value = $db->get_var( $db->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", 'shouse_object_cache_generation' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null === $value && '' === $db->last_error ) {
			$db->query( $db->prepare( "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", 'shouse_object_cache_generation', bin2hex( random_bytes( 16 ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$value = $db->get_var( $db->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s", 'shouse_object_cache_generation' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$valid = '' === $db->last_error && is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
		$db->suppress_errors( $suppress );
		return $valid ? $value : false;
	}
}
