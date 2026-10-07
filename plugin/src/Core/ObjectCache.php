<?php
/**
 * Management of the SafeHouse Redis object cache (implementation in src/ObjectCache/).
 *
 * The wp-content/object-cache.php loader is written only by `wp shouse object-cache enable` and
 * removed by `disable`, plugin deactivation or uninstall: never by a web request. Enabling and
 * disabling also drop this site's keys from Redis, so a cache that was off for a while never
 * serves data from before.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_CLI;
use SafeHouse\ObjectCache\Cache;

defined( 'ABSPATH' ) || exit;

final class ObjectCache {

	public const MARKER = 'SafeHouse object cache loader';

	/** The marker of loaders installed before the rename to SafeHouse. They point at the old plugin folder. */
	public const LEGACY_MARKER = 'WPHouse object cache loader';

	public static function register(): void {
		add_filter( 'site_status_tests', [ self::class, 'site_health_test' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse object-cache', ObjectCacheCommand::class );
		}
	}

	public static function dropin(): string {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	/** "none", "ours" (the SafeHouse loader) or "other" (another plugin's drop-in). */
	public static function dropin_state(): string {
		$file = self::dropin();
		if ( ! file_exists( $file ) ) {
			return 'none';
		}
		$head = (string) file_get_contents( $file, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return str_contains( $head, self::MARKER ) || str_contains( $head, self::LEGACY_MARKER ) ? 'ours' : 'other';
	}

	/** The running SafeHouse cache, or null when WordPress uses another or its own. */
	public static function active(): ?Cache {
		$cache = $GLOBALS['wp_object_cache'] ?? null;
		return $cache instanceof Cache ? $cache : null;
	}

	/** Why the installed loader did not start the cache in this request, if it did not. */
	public static function load_error(): string {
		if ( self::legacy_loader() ) {
			return __( 'The object-cache.php loader dates from before the rename to SafeHouse. Run `wp shouse object-cache enable` to install the current one.', 'shouse' );
		}
		return (string) ( $GLOBALS['shouse_object_cache_error'] ?? '' );
	}

	private static function legacy_loader(): bool {
		$file = self::dropin();
		return file_exists( $file ) && str_contains( (string) file_get_contents( $file, false, null, 0, 1024 ), self::LEGACY_MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/** Write the loader. Returns an error message, or '' on success. Operator commands only. */
	public static function install(): string {
		$folder = basename( dirname( SHOUSE_FILE ) );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $folder ) ) {
			return 'Unexpected plugin folder name: ' . $folder;
		}
		$template = (string) file_get_contents( dirname( SHOUSE_FILE ) . '/src/ObjectCache/loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$loader   = str_replace( '__SHOUSE_FOLDER__', $folder, $template );
		if ( false === file_put_contents( self::dropin(), $loader ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- operator command.
			return 'Could not write ' . self::dropin();
		}
		return '';
	}

	/** Remove the loader if it is ours. */
	public static function remove(): bool {
		if ( 'ours' !== self::dropin_state() ) {
			return false;
		}
		wp_delete_file( self::dropin() );
		return ! file_exists( self::dropin() );
	}

	/** Oldest Redis the cache supports: increments use SET ... KEEPTTL. */
	public const MIN_REDIS = '6.0';

	/** Version of the Redis server, '' when it cannot be read. */
	public static function redis_version(): string {
		require_once dirname( SHOUSE_FILE ) . '/src/ObjectCache/connect.php';
		[ $redis ] = shouse_object_cache_connect();
		if ( null === $redis ) {
			return '';
		}
		try {
			$info = $redis->info( 'server' );
		} catch ( \Throwable ) {
			return '';
		}
		return is_array( $info ) ? (string) ( $info['redis_version'] ?? '' ) : '';
	}

	/**
	 * Drop this site's keys from Redis. Returns an error message, or '' on success.
	 */
	public static function flush_keys(): string {
		$active = self::active();
		if ( null !== $active && $active->redis_status() ) {
			return $active->flush() ? '' : $active->last_error();
		}
		require_once dirname( SHOUSE_FILE ) . '/src/ObjectCache/connect.php';
		[ $redis, $error ] = shouse_object_cache_connect();
		if ( null === $redis ) {
			return $error;
		}
		require_once dirname( SHOUSE_FILE ) . '/src/ObjectCache/Cache.php';
		$cache = new Cache( $redis, shouse_object_cache_prefix(), shouse_object_cache_secret() );
		return $cache->flush() ? '' : $cache->last_error();
	}

	/**
	 * Remove the loader and this site's keys, and stop this process from writing to Redis again
	 * (shutdown hooks would otherwise leave fresh keys behind). Returns an error message, or ''.
	 */
	public static function shut_down(): string {
		self::remove();
		$error = self::flush_keys();
		self::active()?->disconnect();
		return $error;
	}

	/** On plugin deactivation: no cache without the plugin that manages it. */
	public static function deactivate(): void {
		if ( 'ours' === self::dropin_state() ) {
			self::shut_down();
		}
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function site_health_test( array $tests ): array {
		if ( 'ours' === self::dropin_state() ) {
			$tests['direct']['shouse_object_cache'] = [
				'label' => __( 'SafeHouse object cache', 'shouse' ),
				'test'  => [ self::class, 'site_health_result' ],
			];
		}
		return $tests;
	}

	/** @return array<string, mixed> */
	public static function site_health_result(): array {
		$cache = self::active();
		if ( null !== $cache && $cache->redis_status() ) {
			$status = 'good';
			$label  = __( 'The SafeHouse object cache is connected to Redis', 'shouse' );
			$text   = __( 'Database results are kept in Redis between requests. Values are signed; WordPress user, session and permission-option data stay outside persistent Redis. Use isolated Redis ACL credentials: signatures do not prevent replay of old cache data.', 'shouse' );
		} else {
			$status = 'critical';
			$label  = __( 'The SafeHouse object cache cannot reach Redis', 'shouse' );
			/* translators: %s: error message. */
			$text = sprintf( __( 'WordPress is using its own cache for now, so the site works but is slower. Reason: %s', 'shouse' ), '' !== self::load_error() ? self::load_error() : ( null !== $cache ? $cache->last_error() : '?' ) );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Performance', 'shouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'test'        => 'shouse_object_cache',
		];
	}
}
