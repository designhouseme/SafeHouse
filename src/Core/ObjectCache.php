<?php
/**
 * Management of the WPHouse Redis object cache (implementation in src/ObjectCache/).
 *
 * The wp-content/object-cache.php loader is written only by `wp wphouse object-cache enable` and
 * removed by `disable`, plugin deactivation or uninstall: never by a web request. Enabling and
 * disabling also drop this site's keys from Redis, so a cache that was off for a while never
 * serves data from before.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

use WP_CLI;
use WPHouse\ObjectCache\Cache;

defined( 'ABSPATH' ) || exit;

final class ObjectCache {

	public const MARKER = 'WPHouse object cache loader';

	public static function register(): void {
		add_filter( 'site_status_tests', [ self::class, 'site_health_test' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wphouse object-cache', ObjectCacheCommand::class );
		}
	}

	public static function dropin(): string {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	/** "none", "ours" (the WPHouse loader) or "other" (another plugin's drop-in). */
	public static function dropin_state(): string {
		$file = self::dropin();
		if ( ! file_exists( $file ) ) {
			return 'none';
		}
		$head = (string) file_get_contents( $file, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return str_contains( $head, self::MARKER ) ? 'ours' : 'other';
	}

	/** The running WPHouse cache, or null when WordPress uses another or its own. */
	public static function active(): ?Cache {
		$cache = $GLOBALS['wp_object_cache'] ?? null;
		return $cache instanceof Cache ? $cache : null;
	}

	/** Why the installed loader did not start the cache in this request, if it did not. */
	public static function load_error(): string {
		return (string) ( $GLOBALS['wphouse_object_cache_error'] ?? '' );
	}

	/** Write the loader. Returns an error message, or '' on success. Operator commands only. */
	public static function install(): string {
		$folder = basename( dirname( WPHOUSE_FILE ) );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $folder ) ) {
			return 'Unexpected plugin folder name: ' . $folder;
		}
		$template = (string) file_get_contents( dirname( WPHOUSE_FILE ) . '/src/ObjectCache/loader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$loader   = str_replace( '__WPHOUSE_FOLDER__', $folder, $template );
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

	/**
	 * Drop this site's keys from Redis. Returns an error message, or '' on success.
	 */
	public static function flush_keys(): string {
		$active = self::active();
		if ( null !== $active && $active->redis_status() ) {
			return $active->flush() ? '' : $active->last_error();
		}
		require_once dirname( WPHOUSE_FILE ) . '/src/ObjectCache/connect.php';
		[ $redis, $error ] = wphouse_object_cache_connect();
		if ( null === $redis ) {
			return $error;
		}
		require_once dirname( WPHOUSE_FILE ) . '/src/ObjectCache/Cache.php';
		$cache = new Cache( $redis, wphouse_object_cache_prefix(), wphouse_object_cache_secret() );
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
			$tests['direct']['wphouse_object_cache'] = [
				'label' => __( 'WPHouse object cache', 'wphouse' ),
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
			$label  = __( 'The WPHouse object cache is connected to Redis', 'wphouse' );
			$text   = __( 'Database results are kept in Redis between requests. Every cached value is signed, so other sites on a shared Redis cannot plant data.', 'wphouse' );
		} else {
			$status = 'critical';
			$label  = __( 'The WPHouse object cache cannot reach Redis', 'wphouse' );
			/* translators: %s: error message. */
			$text = sprintf( __( 'WordPress is using its own cache for now, so the site works but is slower. Reason: %s', 'wphouse' ), '' !== self::load_error() ? self::load_error() : ( null !== $cache ? $cache->last_error() : '?' ) );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Performance', 'wphouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'test'        => 'wphouse_object_cache',
		];
	}
}
