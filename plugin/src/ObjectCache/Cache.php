<?php
/**
 * Redis object cache. Same behaviour as WordPress's WP_Object_Cache (runtime copy, cloned objects,
 * global and non-persistent groups, found flag), with Redis behind it.
 *
 * Every value written to Redis is signed: HMAC-SHA256 over the Redis key and the serialized value,
 * with a key derived from AUTH_KEY. A mismatching signature is never unserialized. Redis still needs
 * isolated ACL credentials: signatures do not prevent replay of an old value. WordPress user,
 * session and permission-option groups therefore never use persistent Redis storage.
 *
 * Flushes delete only keys under this site's prefix (SCAN + UNLINK), never the whole database.
 * If Redis fails during a request, the rest of the request runs on the runtime copy only.
 *
 * Loaded by src/ObjectCache/object-cache.php before WordPress has loaded plugins: no WordPress
 * functions beyond what wp-settings.php has loaded by then.
 *
 * @package SafeHouse
 */

namespace SafeHouse\ObjectCache;

use Redis;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Cache {

	private const SIGNATURE_BYTES = 32;
	private const SCAN_BATCH      = 1000;
	private const CHANGE_RETRIES  = 64;

	/** Read by debugging tools (Query Monitor, Debug Bar) like WP_Object_Cache's. */
	public int $cache_hits = 0;

	/** Read by debugging tools like WP_Object_Cache's. */
	public int $cache_misses = 0;

	/**
	 * Runtime copy: encoded group + blog/global scope => key => value.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $cache = [];

	/** @var array<string, true> WordPress's cache-switch fallback and debugging tools read this map. */
	public array $global_groups = [];

	/** @var array<string, true> */
	private array $non_persistent_groups = [
		'users'        => true,
		'user_meta'    => true,
		'userlogins'   => true,
		'useremail'    => true,
		'userslugs'    => true,
		'user-queries' => true,
		'options'      => true, // Includes role definitions and security-related plugin settings.
		'site-options' => true,
	];

	private string $blog_prefix = '1';

	private string $last_error = '';

	private ?Redis $redis;

	private string $prefix;

	private string $secret;

	/** Trusted database generation: requests predating recovery cannot refill the current namespace. */
	private string $generation;

	/**
	 * Without arguments (as WordPress's own tests create a second cache) it uses the connection and
	 * settings of the running cache.
	 */
	public function __construct( ?Redis $redis = null, ?string $prefix = null, ?string $secret = null ) {
		$this->redis      = $redis ?? ( $GLOBALS['shouse_object_cache_redis'] ?? null );
		$this->prefix     = $prefix ?? ( function_exists( 'shouse_object_cache_prefix' ) ? shouse_object_cache_prefix() : 'wph:' );
		$this->secret     = $secret ?? ( function_exists( 'shouse_object_cache_secret' ) ? shouse_object_cache_secret() : '' );
		$this->generation = (string) ( $GLOBALS['shouse_object_cache_generation'] ?? '0' );
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			$this->blog_prefix = (string) get_current_blog_id();
		}
	}

	/** WordPress compatibility: expose group metadata and a read-only view of the current blog. */
	public function __get( string $name ): mixed {
		if ( 'no_mc_groups' === $name ) {
			return array_keys( $this->non_persistent_groups );
		}
		if ( 'cache' !== $name ) {
			return null;
		}
		$view = [];
		foreach ( $this->cache as $runtime_group => $values ) {
			[ $encoded, $scope ] = explode( ':', $runtime_group, 2 );
			$group               = hex2bin( $encoded );
			if ( false !== $group && $scope === $this->scope( $group ) ) {
				$view[ $group ] = array_map( static fn( $value ) => is_object( $value ) ? clone $value : $value, $values );
			}
		}
		return $view;
	}

	public function __isset( string $name ): bool {
		return in_array( $name, [ 'cache', 'no_mc_groups' ], true );
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
			if ( ! $this->redis_call( fn( Redis $r ) => $r->set( $this->redis_key( $key, $group ), $this->encode( $this->redis_key( $key, $group ), $data ), $options ) ) && null !== $this->redis ) {
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
			if ( null !== $this->redis || ! $this->in_runtime( $key, $group ) ) {
				return false;
			}
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
			if ( ! $ok && null !== $this->redis ) {
				$this->failed( 'Redis refused a cache write.' );
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
		$ok = $this->redis_call(
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
		if ( null !== $this->redis && ( ! is_array( $ok ) || count( $ok ) !== count( $data ) || in_array( false, $ok, true ) ) ) {
			$this->failed( 'Redis refused a batch cache write.' );
		}
		$result = [];
		foreach ( $data as $key => $value ) {
			$stored         = null === $this->redis || ( is_array( $ok ) && ! empty( $ok[ count( $result ) ] ) );
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
			return self::copy( $this->cache[ $this->runtime_group( $group ) ][ $key ] );
		}
		if ( ! $this->persistent( $group ) ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}
		$redis_key = $this->redis_key( $key, $group );
		$raw       = $this->redis_call( fn( Redis $r ) => $r->get( $redis_key ) );
		if ( null === $this->redis && $this->in_runtime( $key, $group ) ) {
			return $this->get( $key, $group, false, $found );
		}
		[ $found, $value ] = $this->decode( $redis_key, $raw );
		if ( ! $found ) {
			$this->forget( $key, $group );
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
				$result[ $key ] = self::copy( $this->cache[ $this->runtime_group( $group ) ][ $key ] );
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
				if ( null === $this->redis && $this->in_runtime( $key, $group ) ) {
					$result[ $key ] = $this->get( $key, $group );
					continue;
				}
				[ $found, $value ] = $this->decode( $redis_keys[ $i ], is_array( $raw ) ? ( $raw[ $i ] ?? false ) : false );
				if ( $found ) {
					++$this->cache_hits;
					$this->remember( $key, $group, $value );
					$result[ $key ] = self::copy( $value );
				} else {
					$this->forget( $key, $group );
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
			if ( false === $deleted && null !== $this->redis ) {
				$this->failed( 'Redis refused a cache deletion.' );
			}
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
		foreach ( array_keys( $this->cache ) as $runtime_group ) {
			if ( str_starts_with( (string) $runtime_group, bin2hex( $group ) . ':' ) ) {
				unset( $this->cache[ $runtime_group ] );
			}
		}
		if ( ! $this->persistent( $group ) ) {
			return true;
		}
		return $this->unlink_matching( self::glob_escape( $this->prefix ) . bin2hex( $group ) . ':*' );
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
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$this->blog_prefix = (string) $blog_id;
		}
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
		$this->redis                          = null;
		$GLOBALS['shouse_object_cache_redis'] = null;
		$this->mark_stale();
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
			'<p><strong>SafeHouse object cache</strong> %s<br>Hits: %d<br>Misses: %d</p>',
			null !== $this->redis ? 'Redis connected' : 'Redis not connected',
			(int) $this->cache_hits,
			(int) $this->cache_misses
		);
	}

	private function change( int|string $key, int $offset, string $group ): int|false {
		$group = self::group( $group );
		$key   = (string) $key;
		if ( $this->persistent( $group ) ) {
			$redis_key = $this->redis_key( $key, $group );
			$value     = $this->redis_call(
				function ( Redis $r ) use ( $redis_key, $offset ): int|false {
					for ( $attempt = 0; $attempt < self::CHANGE_RETRIES; $attempt++ ) {
						if ( ! $r->watch( $redis_key ) ) {
							throw new RuntimeException( 'Redis refused WATCH.' );
						}
						[ $found, $current ] = $this->decode( $redis_key, $r->get( $redis_key ) );
						if ( ! $found ) {
							$r->unwatch();
							return false;
						}
						$next = max( 0, ( is_numeric( $current ) ? (int) $current : 0 ) + $offset );
						$pipe = $r->multi();
						if ( false === $pipe ) {
							throw new RuntimeException( 'Redis refused MULTI.' );
						}
						// XX also prevents resurrection when the key expires on Redis older than 6.0.9.
						$pipe->set( $redis_key, $this->encode( $redis_key, $next ), [ 'XX', 'KEEPTTL' ] );
						$result = $pipe->exec();
						if ( is_array( $result ) ) {
							return ! empty( $result[0] ) ? $next : false;
						}
						usleep( min( 5000, 50 * ( $attempt + 1 ) ) + random_int( 0, 200 ) );
					}
					$this->last_error = 'Cache counter changed too often; retry the operation.';
					return false;
				}
			);
			if ( false !== $value ) {
				$this->remember( $key, $group, $value );
				return (int) $value;
			}
			if ( null !== $this->redis ) {
				$this->forget( $key, $group );
				return false;
			}
		}
		// No Redis (including a failure above): preserve WordPress's request-local behaviour.
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		$value = max( 0, ( is_numeric( $value ) ? (int) $value : 0 ) + $offset );
		$this->remember( $key, $group, $value );
		return $value;
	}

	private function unlink_matching( string $pattern ): bool {
		if ( null === $this->redis ) {
			return false;
		}
		$this->scan(
			$pattern,
			function ( array $keys ): void {
				$deleted = $this->redis_call( fn( Redis $r ) => $r->unlink( $keys ) );
				if ( false === $deleted && null !== $this->redis ) {
					$this->failed( 'Redis refused UNLINK.' );
				}
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
					if ( ! $r->setOption( Redis::OPT_SCAN, Redis::SCAN_RETRY ) ) {
						throw new RuntimeException( 'Redis refused the SCAN option.' );
					}
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
	 * @phpstan-impure
	 */
	private function redis_call( callable $command ): mixed {
		if ( null === $this->redis ) {
			return false;
		}
		try {
			$this->redis->clearLastError();
			$result = $command( $this->redis );
			$error  = $this->redis->getLastError();
			if ( is_string( $error ) && '' !== $error ) {
				throw new RuntimeException( $error );
			}
			return $result;
		} catch ( Throwable $e ) {
			$this->failed( $e->getMessage() );
			return false;
		}
	}

	private function failed( string $message ): void {
		$this->last_error                     = $message;
		$this->redis                          = null;
		$GLOBALS['shouse_object_cache_redis'] = null;
		$this->mark_stale();
	}

	private function mark_stale(): void {
		if ( function_exists( 'shouse_object_cache_mark_stale' ) ) {
			shouse_object_cache_mark_stale();
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
		return $this->prefix . bin2hex( $group ) . ':' . $this->generation . ':' . $this->scope( $group ) . ':' . bin2hex( $key );
	}

	private function scope( string $group ): string {
		return isset( $this->global_groups[ $group ] ) ? 'g' : $this->blog_prefix;
	}

	private function runtime_group( string $group ): string {
		return bin2hex( $group ) . ':' . $this->scope( $group );
	}

	private function persistent( string $group ): bool {
		return null !== $this->redis && ! isset( $this->non_persistent_groups[ $group ] );
	}

	private function in_runtime( string $key, string $group ): bool {
		$runtime_group = $this->runtime_group( $group );
		return isset( $this->cache[ $runtime_group ] ) && array_key_exists( $key, $this->cache[ $runtime_group ] );
	}

	private function remember( string $key, string $group, mixed $data ): void {
		$this->cache[ $this->runtime_group( $group ) ][ $key ] = self::copy( $data );
	}

	private function forget( string $key, string $group ): void {
		unset( $this->cache[ $this->runtime_group( $group ) ][ $key ] );
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
