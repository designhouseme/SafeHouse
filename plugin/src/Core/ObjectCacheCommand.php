<?php
/**
 * `wp shouse object-cache`: install, remove and inspect the SafeHouse Redis object cache.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Install, remove and inspect the SafeHouse Redis object cache.
 */
final class ObjectCacheCommand {

	/**
	 * Install the object cache drop-in after checking that Redis answers. Clears this site's old keys first.
	 *
	 * The PHP that runs WP-CLI needs the PhpRedis extension too: commands that change data while the
	 * cache cannot connect would leave Redis out of date.
	 */
	public function enable(): void {
		if ( ! class_exists( 'Redis' ) ) {
			WP_CLI::error( 'The PhpRedis extension is not installed for the PHP that runs WP-CLI. The web server\'s PHP needs it as well.' );
		}
		if ( 'other' === ObjectCache::dropin_state() ) {
			WP_CLI::error( 'Another plugin\'s object-cache.php drop-in is installed. Remove it (or deactivate that plugin) first.' );
		}
		$error = ObjectCache::flush_keys();
		if ( '' !== $error ) {
			WP_CLI::error( 'Redis is not usable: ' . $error );
		}
		$version = ObjectCache::redis_version();
		if ( '' === $version || version_compare( $version, ObjectCache::MIN_REDIS, '<' ) ) {
			WP_CLI::error( sprintf( 'Redis %s or newer is required (this server: %s).', ObjectCache::MIN_REDIS, '' !== $version ? $version : 'unknown' ) );
		}
		$error = ObjectCache::install();
		if ( '' !== $error ) {
			WP_CLI::error( $error );
		}
		Log::add( 'object_cache', 'SafeHouse object cache enabled from WP-CLI', [], 'warning' );
		WP_CLI::success( 'Object cache enabled. Change alerts will report the new object-cache.php drop-in; that is expected.' );
	}

	/**
	 * Remove the object cache drop-in and this site's keys from Redis.
	 */
	public function disable(): void {
		if ( 'ours' !== ObjectCache::dropin_state() ) {
			WP_CLI::warning( 'The SafeHouse object cache is not installed.' );
			return;
		}
		$error = ObjectCache::shut_down();
		Log::add( 'object_cache', 'SafeHouse object cache disabled from WP-CLI', [], 'warning' );
		if ( '' !== $error ) {
			WP_CLI::warning( 'Drop-in removed, but old keys could not be cleared: ' . $error . '. Run `wp shouse object-cache enable` later only after Redis is back.' );
			return;
		}
		WP_CLI::success( 'Object cache disabled and its keys removed from Redis.' );
	}

	/**
	 * Show the drop-in, the connection and this site's keys.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$cache = ObjectCache::active();
		$rows  = [
			[
				'item'  => 'drop-in',
				'value' => ObjectCache::dropin_state(),
			],
			[
				'item'  => 'phpredis',
				'value' => class_exists( 'Redis' ) ? (string) phpversion( 'redis' ) : 'missing',
			],
			[
				'item'  => 'redis',
				'value' => null !== $cache && $cache->redis_status() ? 'connected' : 'not connected' . ( '' !== ObjectCache::load_error() ? ': ' . ObjectCache::load_error() : '' ),
			],
		];
		if ( null !== $cache ) {
			$rows[] = [
				'item'  => 'prefix',
				'value' => $cache->prefix(),
			];
			$rows[] = [
				'item'  => 'keys',
				'value' => (string) ( $cache->key_count() ?? '?' ),
			];
			$rows[] = [
				'item'  => 'hits/misses (this command)',
				'value' => $cache->cache_hits . '/' . $cache->cache_misses,
			];
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'item', 'value' ] );
	}

	/**
	 * Drop this site's keys from Redis.
	 */
	public function flush(): void {
		if ( null === ObjectCache::active() ) {
			WP_CLI::error( 'The SafeHouse object cache is not running in this process.' );
		}
		wp_cache_flush();
		WP_CLI::success( 'Object cache flushed.' );
	}
}
