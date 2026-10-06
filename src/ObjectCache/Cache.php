<?php
/**
 * Redis object cache. Same behaviour as WordPress's WP_Object_Cache (runtime copy, cloned objects,
 * global and non-persistent groups, found flag), with Redis behind it.
 *
 * Every value written to Redis is signed: HMAC-SHA256 over the Redis key and the serialized value,
 * with a key derived from AUTH_KEY. A value whose signature does not match (written by someone else
 * on a shared Redis, or for another key) is a cache miss and is never unserialized.
 *
 * Flushes delete only keys under this site's prefix (SCAN + UNLINK), never the whole database.
 * If Redis fails during a request, the rest of the request runs on the runtime copy only.
 *
 * Loaded by src/ObjectCache/object-cache.php before WordPress has loaded plugins: no WordPress
 * functions beyond what wp-settings.php has loaded by then.
 *
 * @package WPHouse
 */

namespace WPHouse\ObjectCache;

use Redis;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Cache {

	private const SIGNATURE_BYTES = 32;
	private const SCAN_BATCH      = 1000;

	/** Read by debugging tools (Query Monitor, Debug Bar) like WP_Object_Cache's. */
	public int $cache_hits = 0;

	/** Read by debugging tools like WP_Object_Cache's. */
	public int $cache_misses = 0;

	/**
	 * Runtime copy: group => key => value.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = [];

	/** @var array<string, true> */
	private array $global_groups = [];

	/** @var array<string, true> */
	private array $non_persistent_groups = [];

	private string $blog_prefix = '1';

	private string $last_error = '';

	public function __construct( private ?Redis $redis, private string $prefix, private string $secret ) {
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			$this->blog_prefix = (string) get_current_blog_id();
		}
	}

	public function add( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		$group = self::group( $group );
		$key   = (string) $key;
		if ( $this->in_runtime( $key, $group ) ) {
			return false;
		}
		if ( $this->persistent( $group ) ) {
			$options = [ 'NX' ];
			if ( $expire > 0 ) {
				$options['EX'] = $expire;
			}
			if ( ! $this->redis_call( fn( Redis $r ) => $r->set( $this->redis_key( $key, $group ), $this->encode( $this->redis_key( $key, $group ), $data ), $options ) ) ) {
				return false;
			}
		}
		$this->remember( $key, $group, $data );
		return true;
	}

	/**
	 * @param array<int|string, mixed> $data Key => value.
	 * @return array<int|string, bool>
	 */
	public function add_multiple( array $data, string $group = '', int $expire = 0 ): array {
		$result = [];
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $result;
	}

	public function replace( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		$group = self::group( $group );
		$key   = (string) $key;
		if ( ! $this->persistent( $group ) ) {
			if ( ! $this->in_runtime( $key, $group ) ) {
				return false;
			}
			$this->remember( $key, $group, $data );
			return true;
		}
		$options = [ 'XX' ];
		if ( $expire > 0 ) {
			$options['EX'] = $expire;
		}
		if ( ! $this->redis_call( fn( Redis $r ) => $r->set( $this->redis_key( $key, $group ), $this->encode( $this->redis_key( $key, $group ), $data ), $options ) ) ) {
			return false;
		}
		$this->remember( $key, $group, $data );
		return true;
	}

	public function set( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		$group = self::group( $group );
		$key   = (string) $key;
		if ( $this->persistent( $group ) ) {
			$redis_key = $this->redis_key( $key, $group );
			$value     = $this->encode( $redis_key, $data );
			$ok        = $this->redis_call( fn( Redis $r ) => $expire > 0 ? $r->setex( $redis_key, $expire, $value ) : $r->set( $redis_key, $value ) );
			if ( ! $ok ) {
				$this->forget( $key, $group ); // Never keep a runtime value that Redis does not have.
				return false;
			}
		}
		$this->remember( $key, $group, $data );
		return true;
	}

	/**
	 * @param array<int|string, mixed> $data Key => value.
	 * @return array<int|string, bool>
	 */
	public function set_multiple( array $data, string $group = '', int $expire = 0 ): array {
		$group = self::group( $group );
		if ( ! $data ) {
			return [];
		}
		if ( ! $this->persistent( $group ) || null === $this->redis ) {
			$result = [];
			foreach ( $data as $key => $value ) {
				$result[ $key ] = $this->set( $key, $value, $group, $expire );
			}
			return $result;
		}
		$ok     = $this->redis_call(
			function ( Redis $r ) use ( $data, $group, $expire ) {
				$pipe = $r->multi( Redis::PIPELINE );
				foreach ( $data as $key => $value ) {
					$redis_key = $this->redis_key( (string) $key, $group );
					$encoded   = $this->encode( $redis_key, $value );
					if ( $expire > 0 ) {
						$pipe->setex( $redis_key, $expire, $encoded );
					} else {
						$pipe->set( $redis_key, $encoded );
					}
				}
				return $pipe->exec();
			}
		);
		$result = [];
		foreach ( $data as $key => $value ) {
			$stored         = is_array( $ok ) && ! empty( $ok[ count( $result ) ] );
			$result[ $key ] = $stored;
			if ( $stored ) {
				$this->remember( (string) $key, $group, $value );
			} else {
				$this->forget( (string) $key, $group );
			}
		}
		return $result;
	}

	/**
	 * @param bool|null $found Set to whether the key was found.
	 * @param-out bool  $found
	 */
	public function get( int|string $key, string $group = '', bool $force = false, ?bool &$found = null ): mixed {
		$group = self::group( $group );
		$key   = (string) $key;
		if ( ( ! $force || ! $this->persistent( $group ) ) && $this->in_runtime( $key, $group ) ) {
			$found = true;
			++$this->cache_hits;
			return self::copy( $this->cache[ $group ][ $key ] );
		}
		if ( ! $this->persistent( $group ) ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}
		$redis_key         = $this->redis_key( $key, $group );
		$raw               = $this->redis_call( fn( Redis $r ) => $r->get( $redis_key ) );
		[ $found, $value ] = $this->decode( $redis_key, $raw );
		if ( ! $found ) {
			++$this->cache_misses;
			return false;
		}
		++$this->cache_hits;
		$this->remember( $key, $group, $value );
		return self::copy( $value );
	}

	/**
	 * @param array<int, int|string> $keys Keys.
	 * @return array<int|string, mixed> Key => value, false for misses.
	 */
	public function get_multiple( array $keys, string $group = '', bool $force = false ): array {
		$group  = self::group( $group );
		$result = [];
		$fetch  = [];
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( ( ! $force || ! $this->persistent( $group ) ) && $this->in_runtime( $key, $group ) ) {
				$result[ $key ] = self::copy( $this->cache[ $group ][ $key ] );
				++$this->cache_hits;
			} elseif ( $this->persistent( $group ) ) {
				$fetch[]        = $key;
				$result[ $key ] = false;
			} else {
				$result[ $key ] = false;
				++$this->cache_misses;
			}
		}
		if ( $fetch ) {
			$redis_keys = array_map( fn( $k ) => $this->redis_key( $k, $group ), $fetch );
			$raw        = $this->redis_call( fn( Redis $r ) => $r->mget( $redis_keys ) );
			foreach ( $fetch as $i => $key ) {
				[ $found, $value ] = $this->decode( $redis_keys[ $i ], is_array( $raw ) ? ( $raw[ $i ] ?? false ) : false );
				if ( $found ) {
					++$this->cache_hits;
					$this->remember( $key, $group, $value );
					$result[ $key ] = self::copy( $value );
				} else {
					++$this->cache_misses;
				}
			}
		}
		return $result;
	}

	public function delete( int|string $key, string $group = '' ): bool {
		$group   = self::group( $group );
		$key     = (string) $key;
		$existed = $this->in_runtime( $key, $group );
		$this->forget( $key, $group );
		if ( $this->persistent( $group ) ) {
			$deleted = $this->redis_call( fn( Redis $r ) => $r->del( $this->redis_key( $key, $group ) ) );
			$existed = $existed || (int) $deleted > 0;
		}
		return $existed;
	}

	/**
	 * @param array<int, int|string> $keys Keys.
	 * @return array<int|string, bool>
	 */
	public function delete_multiple( array $keys, string $group = '' ): array {
		$result = [];
		foreach ( $keys as $key ) {
			$result[ $key ] = $this->delete( $key, $group );
		}
		return $result;
	}

	public function incr( int|string $key, int $offset = 1, string $group = '' ): int|false {
		return $this->change( $key, $offset, $group );
	}

	public function decr( int|string $key, int $offset = 1, string $group = '' ): int|false {
		return $this->change( $key, -$offset, $group );
	}

	/** Drop this site's keys from Redis (never the whole database) and the runtime copy. */
	public function flush(): bool {
		$this->cache = [];
		return $this->unlink_matching( self::glob_escape( $this->prefix ) . '*' );
	}

	public function flush_runtime(): bool {
		$this->cache = [];
		return true;
	}

	public function flush_group( string $group ): bool {
		$group = self::group( $group );
		unset( $this->cache[ $group ] );
		if ( ! $this->persistent( $group ) ) {
			return true;
		}
		return $this->unlink_matching( self::glob_escape( $this->prefix ) . '*:' . self::glob_escape( $group ) . ':*' );
	}

	/**
	 * @param string|array<int, string> $groups Groups shared by every site of a network.
	 */
	public function add_global_groups( string|array $groups ): void {
		foreach ( (array) $groups as $group ) {
			$this->global_groups[ (string) $group ] = true;
		}
	}

	/**
	 * @param string|array<int, string> $groups Groups kept for one request only.
	 */
	public function add_non_persistent_groups( string|array $groups ): void {
		foreach ( (array) $groups as $group ) {
			$this->non_persistent_groups[ (string) $group ] = true;
		}
	}

	public function switch_to_blog( int $blog_id ): void {
		$this->blog_prefix = (string) $blog_id;
	}

	public function close(): bool {
		if ( null !== $this->redis ) {
			try {
				$this->redis->close();
			} catch ( Throwable ) {
				return false;
			}
		}
		return true;
	}

	/** Stop using Redis for the rest of this request; later writes stay in the runtime copy. */
	public function disconnect(): void {
		$this->close();
		$this->redis = null;
	}

	/** Whether Redis is answering, for the Integrations card and WP-CLI. */
	public function redis_status(): bool {
		return null !== $this->redis;
	}

	public function last_error(): string {
		return $this->last_error;
	}

	public function prefix(): string {
		return $this->prefix;
	}

	/** Number of this site's keys in Redis, or null when Redis is not reachable. */
	public function key_count(): ?int {
		if ( null === $this->redis ) {
			return null;
		}
		$count = 0;
		$this->scan(
			self::glob_escape( $this->prefix ) . '*',
			static function ( array $keys ) use ( &$count ): void {
				$count += count( $keys );
			}
		);
		return null === $this->redis ? null : $count;
	}

	/** Printed by debugging tools that call stats() on the object cache. */
	public function stats(): void {
		printf(
			'<p><strong>WPHouse object cache</strong> %s<br>Hits: %d<br>Misses: %d</p>',
			null !== $this->redis ? 'Redis connected' : 'Redis not connected',
			(int) $this->cache_hits,
			(int) $this->cache_misses
		);
	}

	private function change( int|string $key, int $offset, string $group ): int|false {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		$value = is_numeric( $value ) ? (int) $value + $offset : $offset;
		$value = max( 0, $value );
		$group = self::group( $group );
		if ( $this->persistent( $group ) ) {
			$redis_key = $this->redis_key( (string) $key, $group );
			if ( ! $this->redis_call( fn( Redis $r ) => $r->set( $redis_key, $this->encode( $redis_key, $value ), [ 'KEEPTTL' ] ) ) ) {
				$this->forget( (string) $key, $group );
				return false;
			}
		}
		$this->remember( (string) $key, $group, $value );
		return $value;
	}

	private function unlink_matching( string $pattern ): bool {
		if ( null === $this->redis ) {
			return false;
		}
		$this->scan(
			$pattern,
			function ( array $keys ): void {
				$this->redis_call( fn( Redis $r ) => $r->unlink( $keys ) );
			}
		);
		return null !== $this->redis;
	}

	/**
	 * @param callable(array<int, string>): void $each Called with each batch of matching keys.
	 */
	private function scan( string $pattern, callable $each ): void {
		$iterator = null;
		do {
			$keys = $this->redis_call(
				function ( Redis $r ) use ( &$iterator, $pattern ) {
					$r->setOption( Redis::OPT_SCAN, Redis::SCAN_RETRY );
					return $r->scan( $iterator, $pattern, self::SCAN_BATCH );
				}
			);
			if ( is_array( $keys ) && $keys ) {
				$each( $keys );
			}
		} while ( null !== $this->redis && $iterator > 0 );
	}

	/**
	 * Run a Redis command. On a connection error, stop using Redis for the rest of the request.
	 *
	 * @param callable(Redis): mixed $command Command.
	 */
	private function redis_call( callable $command ): mixed {
		if ( null === $this->redis ) {
			return false;
		}
		try {
			return $command( $this->redis );
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			$this->redis      = null;
			return false;
		}
	}

	private function encode( string $redis_key, mixed $data ): string {
		$payload = serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- same storage format as WP_Object_Cache, signed below.
		return hash_hmac( 'sha256', $redis_key . "\0" . $payload, $this->secret, true ) . $payload;
	}

	/**
	 * @return array{0: bool, 1: mixed} Found, value.
	 */
	private function decode( string $redis_key, mixed $raw ): array {
		if ( ! is_string( $raw ) || strlen( $raw ) <= self::SIGNATURE_BYTES ) {
			return [ false, false ];
		}
		$signature = substr( $raw, 0, self::SIGNATURE_BYTES );
		$payload   = substr( $raw, self::SIGNATURE_BYTES );
		if ( ! hash_equals( hash_hmac( 'sha256', $redis_key . "\0" . $payload, $this->secret, true ), $signature ) ) {
			return [ false, false ]; // Not written by this site for this key: never unserialize it.
		}
		if ( 'b:0;' === $payload ) {
			return [ true, false ];
		}
		$value = @unserialize( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- signature verified above: only data this site wrote.
		return false === $value ? [ false, false ] : [ true, $value ];
	}

	private function redis_key( string $key, string $group ): string {
		return $this->prefix . ( isset( $this->global_groups[ $group ] ) ? 'g' : $this->blog_prefix ) . ':' . $group . ':' . $key;
	}

	private function persistent( string $group ): bool {
		return ! isset( $this->non_persistent_groups[ $group ] );
	}

	private function in_runtime( string $key, string $group ): bool {
		return isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] );
	}

	private function remember( string $key, string $group, mixed $data ): void {
		$this->cache[ $group ][ $key ] = is_object( $data ) ? clone $data : $data;
	}

	private function forget( string $key, string $group ): void {
		unset( $this->cache[ $group ][ $key ] );
	}

	/** Objects are handed out as copies, as WP_Object_Cache does, so callers cannot change the cached one. */
	private static function copy( mixed $value ): mixed {
		return is_object( $value ) ? clone $value : $value;
	}

	private static function group( string $group ): string {
		return '' === $group ? 'default' : $group;
	}

	private static function glob_escape( string $value ): string {
		return (string) preg_replace( '/([*?\[\]\\\\])/', '\\\\$1', $value );
	}
}
