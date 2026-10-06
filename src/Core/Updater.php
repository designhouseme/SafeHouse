<?php
/**
 * Self-hosted updates with signed manifests.
 *
 * Flow: core asks the `update_plugins_{host}` filter (thanks to the Update URI header, the plugin
 * is never looked up on wordpress.org, so a lookalike slug there cannot hijack it). We fetch
 * manifest.json and manifest.json.sig, verify the Ed25519 signature over the exact bytes with
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

	/** Base64 Ed25519 public keys. Keep the previous key here for one release when rotating. */
	private const PUBLIC_KEYS = [
		'a96AtREE2wwy9c3iXhw8ewmRm2weOS5UkOHuIsU11eU=', // 2026-10-05
	];

	private const CACHE_KEY      = 'shouse_update_manifest';
	private const CACHE_TTL      = 3 * HOUR_IN_SECONDS;
	private const ERROR_TTL      = HOUR_IN_SECONDS;
	private const MAX_MANIFEST   = 64 * 1024;
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

	/** A git working copy must never be overwritten by an update. Release zips contain no .git. */
	public static function is_dev_checkout(): bool {
		return file_exists( dirname( SHOUSE_FILE ) . '/.git' );
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

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $package, 120 );
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
	 * @return array{version: string, download_url: string, sha256: string, requires: string, requires_php: string, tested: string, released: string, homepage: string, changelog: string}|null
	 */
	public static function manifest(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false === $cached ) {
			$cached = self::fetch();
			set_site_transient( self::CACHE_KEY, $cached, is_wp_error( $cached ) ? self::ERROR_TTL : self::CACHE_TTL );
		}
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * @return array{version: string, download_url: string, sha256: string, requires: string, requires_php: string, tested: string, released: string, homepage: string, changelog: string}|WP_Error
	 */
	private static function fetch(): array|WP_Error {
		$url  = self::manifest_url();
		$args = [
			'timeout'             => 10,
			'redirection'         => 2,
			'limit_response_size' => self::MAX_MANIFEST,
		];

		$body = self::get_body( $url, $args );
		$sig  = self::get_body( $url . '.sig', $args );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		if ( ! Signature::verify( $body, $sig, self::public_keys() ) ) {
			Log::add( 'update_rejected', 'SafeHouse update manifest has an invalid signature', [ 'url' => $url ], 'critical' );
			return new WP_Error( 'shouse_bad_signature', 'Manifest signature is invalid.' );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || 'shouse' !== ( $data['slug'] ?? '' ) ) {
			return new WP_Error( 'shouse_bad_manifest', 'Manifest is not for SafeHouse.' );
		}
		$manifest = [
			'version'      => (string) ( $data['version'] ?? '' ),
			'download_url' => (string) ( $data['download_url'] ?? '' ),
			'sha256'       => strtolower( (string) ( $data['sha256'] ?? '' ) ),
			'requires'     => (string) ( $data['requires'] ?? '' ),
			'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			'tested'       => (string) ( $data['tested'] ?? '' ),
			'released'     => (string) ( $data['released'] ?? '' ),
			'homepage'     => (string) ( $data['homepage'] ?? 'https://designhouse.me/' ),
			'changelog'    => (string) ( $data['changelog'] ?? '' ),
		];
		$scheme   = wp_parse_url( $manifest['download_url'], PHP_URL_SCHEME );
		if ( ! preg_match( self::VERSION_FORMAT, $manifest['version'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $manifest['sha256'] )
			|| ! ( 'https' === $scheme || ( 'http' === $scheme && self::is_local() ) ) ) {
			return new WP_Error( 'shouse_bad_manifest', 'Manifest fields are invalid.' );
		}
		return $manifest;
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
			return new WP_Error( 'shouse_update_http', sprintf( 'HTTP %d for %s', wp_remote_retrieve_response_code( $response ), $url ) );
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
