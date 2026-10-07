<?php
/**
 * Self-hosted updates with signed manifests.
 *
 * Flow: core asks the `update_plugins_{host}` filter (thanks to the Update URI header, the plugin
 * is never looked up on wordpress.org, so a lookalike slug there cannot hijack it). We fetch
	 * release.json, an atomic envelope, verify the Ed25519 signature over the exact bytes with
 * a public key baked into the plugin, and only then offer the update. When core downloads the
 * package, upgrader_pre_download checks its SHA-256 against the signed manifest and refuses
 * anything else. A compromised update host can therefore not ship code: it has no private key.
 *
 * Constants:
 *   SHOUSE_DISABLE_UPDATES  true: no update checks at all (e.g. sites deployed from git).
 *   SHOUSE_AUTO_UPDATE      false: do not force background auto-updates for SafeHouse.
 *   SHOUSE_UPDATE_URL, SHOUSE_UPDATE_PUBLIC_KEYS: overrides, honoured only on local/development sites.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Updater {

	/** Must match the host in the plugin's Update URI header. */
	public const HOST = 'updates.designhouse.me';

	private const MANIFEST_URL = 'https://updates.designhouse.me/shouse/manifest.json';

	/** Base64 Ed25519 public keys. Rotation requires a bridge release; see dev/SIGNED-DATA-PROTOCOL.txt. */
	private const PUBLIC_KEYS = [
		'SOrVjhZX5PC6q78ohxcoO9zqcll7YarqDyF6hQ6knBg=', // 2026-10-06
	];

	private const CACHE_KEY      = 'shouse_update_manifest_v2';
	private const FLOOR_OPTION   = 'shouse_update_floor';
	private const CACHE_TTL      = 3 * HOUR_IN_SECONDS;
	private const ERROR_TTL      = 5 * MINUTE_IN_SECONDS;
	private const MAX_MANIFEST   = 64 * 1024;
	private const MAX_PACKAGE    = 20 * 1024 * 1024;
	private const MAX_AGE        = 180 * DAY_IN_SECONDS;
	private const VERSION_FORMAT = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

	public static function register(): void {
		if ( Compat::constant_on( 'SHOUSE_DISABLE_UPDATES' ) || self::is_dev_checkout() ) {
			return;
		}
		add_filter( 'update_plugins_' . self::HOST, [ self::class, 'offer' ], 10, 3 );
		add_filter( 'plugins_api', [ self::class, 'details' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ self::class, 'verify_download' ], 10, 4 );
		add_filter( 'auto_update_plugin', [ self::class, 'auto_update' ], 10, 2 );
		if ( self::is_local() ) {
			add_filter( 'http_request_host_is_external', [ self::class, 'allow_local_host' ], 10, 2 );
		}
	}

	/**
	 * A git working copy must never be overwritten by an update: the plugin folder itself, or the repository
	 * it sits in (plugin/ of the SafeHouse repo, or a plugins folder deployed from git). Release zips contain no .git.
	 */
	public static function is_dev_checkout(): bool {
		$dir = dirname( SHOUSE_FILE );
		return file_exists( $dir . '/.git' ) || file_exists( dirname( $dir ) . '/.git' );
	}

	public static function basename(): string {
		return plugin_basename( SHOUSE_FILE );
	}

	/**
	 * @param mixed                $update      Update data from earlier filters.
	 * @param array<string, mixed> $plugin_data Plugin headers.
	 * @param string               $plugin_file Plugin basename.
	 * @return mixed
	 */
	public static function offer( mixed $update, array $plugin_data, string $plugin_file ): mixed {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return $update;
		}
		return [
			'slug'         => 'shouse',
			'version'      => $manifest['version'],
			'url'          => $manifest['homepage'],
			'package'      => $manifest['download_url'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
			'tested'       => $manifest['tested'],
			'icons'        => [ 'svg' => plugins_url( 'assets/icon.svg', SHOUSE_FILE ) ],
		];
	}

	/**
	 * "View details" modal on the Plugins and Updates screens.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action plugins_api action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function details( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || 'shouse' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return $result;
		}
		return (object) [
			'name'          => 'SafeHouse',
			'slug'          => 'shouse',
			'version'       => $manifest['version'],
			'author'        => '<a href="https://designhouse.me/">Design House</a>',
			'homepage'      => $manifest['homepage'],
			'requires'      => $manifest['requires'],
			'requires_php'  => $manifest['requires_php'],
			'tested'        => $manifest['tested'],
			'last_updated'  => $manifest['released'],
			'download_link' => $manifest['download_url'],
			'sections'      => [
				'changelog' => wpautop( esc_html( $manifest['changelog'] ) ),
			],
		];
	}

	/**
	 * Download our package ourselves and refuse it unless its SHA-256 matches the signed manifest.
	 * Fails closed: an update for this plugin whose package is not the verified one is rejected.
	 *
	 * @param mixed                $reply      Short-circuit value from earlier filters.
	 * @param string               $package    Package URL or path.
	 * @param object               $upgrader   Upgrader instance.
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 * @return mixed
	 */
	public static function verify_download( mixed $reply, string $package, object $upgrader, array $hook_extra = [] ): mixed {
		if ( false !== $reply ) {
			return $reply;
		}
		$is_our_update = self::basename() === ( $hook_extra['plugin'] ?? '' );
		$package_host  = wp_parse_url( $package, PHP_URL_HOST );
		if ( ! $is_our_update && $package_host !== wp_parse_url( self::manifest_url(), PHP_URL_HOST ) ) {
			return $reply; // Another plugin's download: no network call from us.
		}
		$manifest   = self::manifest();
		$is_our_url = null !== $manifest && $package === $manifest['download_url'];
		if ( ! $is_our_update && ! $is_our_url ) {
			return $reply;
		}

		if ( ! $is_our_url ) {
			// Manifest may have moved on since the update was offered; check once more, uncached.
			delete_site_transient( self::CACHE_KEY );
			$manifest = self::manifest();
			if ( null === $manifest || $package !== $manifest['download_url'] ) {
				Log::add( 'update_rejected', 'SafeHouse update refused: package URL is not in the signed manifest', [ 'package' => $package ], 'critical' );
				return new WP_Error( 'shouse_unsigned_package', __( 'This SafeHouse package is not listed in the signed release manifest. Update cancelled.', 'shouse' ) );
			}
		}

		$file = self::download( $package, $manifest['size'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$hash = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( $manifest['sha256'], $hash ) ) {
			wp_delete_file( $file );
			Log::add( 'update_rejected', 'SafeHouse update refused: package checksum does not match the signed manifest', [ 'version' => $manifest['version'] ], 'critical' );
			return new WP_Error( 'shouse_bad_checksum', __( 'The downloaded SafeHouse package does not match the signed checksum. Update cancelled.', 'shouse' ) );
		}
		Log::add( 'update_verified', 'SafeHouse ' . $manifest['version'] . ' package verified' );
		return $file;
	}

	/**
	 * The HTTP response never controls a filesystem name. Limit bytes while streaming, before hashing.
	 * A legacy manifest has no signed size; its migration path still enforces MAX_PACKAGE.
	 */
	private static function download( string $url, int $size ): string|WP_Error {
		$base = realpath( sys_get_temp_dir() );
		if ( false === $base || ! wp_is_writable( $base ) ) {
			return new WP_Error( 'shouse_private_temp', 'A writable private system temporary directory is required.' );
		}
		foreach ( [ ABSPATH, WP_CONTENT_DIR ] as $public_root ) {
			$root = realpath( $public_root );
			if ( false !== $root && ( $base === $root || str_starts_with( $base . '/', rtrim( $root, '/' ) . '/' ) ) ) {
				return new WP_Error( 'shouse_private_temp', 'The update temporary directory must be outside the website.' );
			}
		}
		$dir = $base . '/shouse-' . bin2hex( random_bytes( 16 ) );
		if ( ! mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- exclusive private directory, no recursive fallback.
			return new WP_Error( 'shouse_private_temp', 'Cannot create a private update directory.' );
		}
		$file    = $dir . '/package.zip';
		$cleanup = static function () use ( $file, $dir ): void {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only our empty private directory.
			}
		};
		$keep    = false;
		try {
			$handle = fopen( $file, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- exclusive filename in private directory.
			if ( false === $handle ) {
				return new WP_Error( 'shouse_private_temp', 'Cannot create the update file.' );
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- close our own handle.
			$limit    = $size > 0 ? min( $size, self::MAX_PACKAGE ) : self::MAX_PACKAGE;
			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'             => 120,
					'redirection'         => 2,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => $limit + 1,
				]
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'shouse_package_http', 'The update download failed.' );
			}
			clearstatcache( true, $file );
			$actual = filesize( $file );
			if ( false === $actual || $actual < 1 || $actual > $limit || ( $size > 0 && $actual !== $size ) ) {
				return new WP_Error( 'shouse_package_size', 'The update package size does not match its signed limit.' );
			}
			register_shutdown_function( $cleanup );
			$keep = true;
			return $file;
		} finally {
			if ( ! $keep ) {
				$cleanup();
			}
		}
	}

	/**
	 * SafeHouse updates itself in the background unless SHOUSE_AUTO_UPDATE is false.
	 *
	 * @param mixed  $update Current decision.
	 * @param object $item   Update offer.
	 * @return mixed
	 */
	public static function auto_update( mixed $update, object $item ): mixed {
		if ( self::basename() !== ( $item->plugin ?? '' ) ) {
			return $update;
		}
		if ( defined( 'SHOUSE_AUTO_UPDATE' ) && ! SHOUSE_AUTO_UPDATE ) {
			return $update;
		}
		return true;
	}

	/** Lets wp_safe_remote_get() reach a private-network update host, on local/development sites only. */
	public static function allow_local_host( bool $external, string $host ): bool {
		return $host === wp_parse_url( self::manifest_url(), PHP_URL_HOST ) ? true : $external;
	}

	/** Clear the cache and ask core to check again. Returns the offered version or an error. */
	public static function check_now(): string|WP_Error {
		delete_site_transient( self::CACHE_KEY );
		$manifest = self::fetch();
		set_site_transient( self::CACHE_KEY, $manifest, is_wp_error( $manifest ) ? self::ERROR_TTL : self::CACHE_TTL );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		return is_wp_error( $manifest ) ? $manifest : $manifest['version'];
	}

	/**
	 * Verified manifest, cached. Null when unavailable or invalid.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function manifest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! self::accept( $cached ) ) {
			$cached = false;
		}
		if ( false === $cached ) {
			$cached = self::fetch();
			set_site_transient( self::CACHE_KEY, $cached, is_wp_error( $cached ) ? self::ERROR_TTL : self::CACHE_TTL );
		}
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private static function fetch(): array|WP_Error {
		$url      = self::manifest_url();
		$args     = [
			'timeout'             => 10,
			'redirection'         => 2,
			'limit_response_size' => 2 * self::MAX_MANIFEST,
		];
		$envelope = self::get_body( dirname( $url ) . '/release.json', $args );
		if ( is_wp_error( $envelope ) ) {
			$floor = get_option( self::FLOOR_OPTION, [] );
			if ( 'shouse_update_not_found' !== $envelope->get_error_code() || ( is_array( $floor ) && ( $floor['protocol'] ?? 1 ) >= 2 ) ) {
				return $envelope;
			}
			$body = self::get_body( $url, $args );
			$sig  = self::get_body( $url . '.sig', $args );
			if ( is_wp_error( $body ) || is_wp_error( $sig ) ) {
				return is_wp_error( $body ) ? $body : $sig;
			}
			$body = Signature::verify( $body, $sig, self::public_keys() ) ? $body : null;
		} else {
			$body = Signature::unpack( $envelope, self::public_keys(), self::MAX_MANIFEST );
		}
		if ( null === $body ) {
			Log::add( 'update_rejected', 'SafeHouse update manifest has an invalid signature', [ 'url' => $url ], 'critical' );
			return new WP_Error( 'shouse_bad_signature', 'Manifest signature is invalid.' );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || 'shouse' !== ( $data['slug'] ?? '' ) ) {
			return new WP_Error( 'shouse_bad_manifest', 'Manifest is not for SafeHouse.' );
		}
		$freshness = Signature::freshness( $data, 'release', self::MAX_AGE, 'released', 'Y-m-d' );
		if ( null === $freshness ) {
			return new WP_Error( 'shouse_stale_manifest', 'Release metadata is expired, future-dated or malformed.' );
		}
		foreach ( [ 'version', 'download_url', 'sha256', 'requires', 'requires_php', 'tested', 'released', 'homepage', 'changelog' ] as $field ) {
			if ( ! is_string( $data[ $field ] ?? null ) ) {
				return new WP_Error( 'shouse_bad_manifest', 'Manifest fields are invalid.' );
			}
		}
		$size = $data['size'] ?? 0;
		if ( ! is_int( $size ) || $size < ( 2 === $freshness['protocol'] ? 1 : 0 ) || $size > self::MAX_PACKAGE ) {
			return new WP_Error( 'shouse_bad_manifest', 'The signed package size is invalid.' );
		}
		$manifest  = [
			'version'      => $data['version'],
			'download_url' => $data['download_url'],
			'sha256'       => strtolower( $data['sha256'] ),
			'requires'     => $data['requires'],
			'requires_php' => $data['requires_php'],
			'tested'       => $data['tested'],
			'released'     => $data['released'],
			'homepage'     => $data['homepage'],
			'changelog'    => $data['changelog'],
			'size'         => $size,
			'_digest'      => hash( 'sha256', $body ),
		];
		$manifest += $freshness;
		$scheme    = wp_parse_url( $manifest['download_url'], PHP_URL_SCHEME );
		if ( ! preg_match( self::VERSION_FORMAT, $manifest['version'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $manifest['sha256'] )
			|| ! ( 'https' === $scheme || ( 'http' === $scheme && self::is_local() ) ) ) {
			return new WP_Error( 'shouse_bad_manifest', 'Manifest fields are invalid.' );
		}
		return self::accept( $manifest ) ? $manifest : new WP_Error( 'shouse_update_rollback', 'Release metadata would roll back an accepted release or could not be persisted.' );
	}

	/** @param array<string, mixed> $manifest Previously verified, complete release metadata. */
	private static function accept( array $manifest ): bool {
		if ( ! is_int( $manifest['expires_at'] ?? null ) || $manifest['expires_at'] <= time()
			|| ! is_int( $manifest['protocol'] ?? null ) || ! is_int( $manifest['generation'] ?? null )
			|| ! is_string( $manifest['_digest'] ?? null ) || ! is_string( $manifest['version'] ?? null )
			|| version_compare( $manifest['version'], SHOUSE_VERSION, '<' ) ) {
			return false;
		}
		return Signature::advance_floor(
			self::FLOOR_OPTION,
			[
				'protocol'   => $manifest['protocol'],
				'generation' => $manifest['generation'],
				'digest'     => $manifest['_digest'],
				'version'    => $manifest['version'],
			]
		);
	}

	/**
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Request arguments.
	 */
	private static function get_body( string $url, array $args ): string|WP_Error {
		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 404 === wp_remote_retrieve_response_code( $response ) ? 'shouse_update_not_found' : 'shouse_update_http', sprintf( 'HTTP %d for %s', wp_remote_retrieve_response_code( $response ), $url ) );
		}
		return wp_remote_retrieve_body( $response );
	}

	/** @return string[] */
	private static function public_keys(): array {
		if ( self::is_local() && defined( 'SHOUSE_UPDATE_PUBLIC_KEYS' ) && is_array( SHOUSE_UPDATE_PUBLIC_KEYS ) ) {
			return array_map( 'strval', SHOUSE_UPDATE_PUBLIC_KEYS );
		}
		return self::PUBLIC_KEYS;
	}

	private static function manifest_url(): string {
		if ( self::is_local() && defined( 'SHOUSE_UPDATE_URL' ) ) {
			return (string) SHOUSE_UPDATE_URL;
		}
		return self::MANIFEST_URL;
	}

	/** Local or development environment: test overrides are honoured only here. */
	public static function is_local(): bool {
		return in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
	}
}
