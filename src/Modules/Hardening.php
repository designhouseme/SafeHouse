<?php
/**
 * Passive hardening: small, safe defaults that do not change how the site works for visitors.
 * Each part is skipped when Wordfence (or wp-config) already provides it.
 *
 * Deliberately absent: a CSP (it breaks payment iframes unless tuned per site), stripping
 * ?ver= from assets (breaks cache busting) and any .htaccess writes.
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use WP_Error;
use WP_REST_Request;
use WP_REST_Users_Controller;
use WPHouse\Core\AbstractModule;
use WPHouse\Core\Compat;

defined( 'ABSPATH' ) || exit;

final class Hardening extends AbstractModule {

	private const ENUMERATION_ERRORS = [ 'invalid_username', 'invalid_email', 'incorrect_password' ];

	public function id(): string {
		return 'hardening';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [
			'file_editor'      => true,
			'user_enumeration' => true,
			'login_errors'     => true,
			'hide_version'     => true,
			'xmlrpc'           => true,
			'headers'          => true,
			'hsts'             => false,
		];
	}

	public function coverage(): array {
		$covered = [];
		if ( Compat::constant_on( 'DISALLOW_FILE_EDIT' ) || Compat::constant_on( 'DISALLOW_FILE_MODS' ) ) {
			$covered['file_editor'] = 'wp-config.php';
		}
		if ( Compat::wordfence_on( 'loginSec_disableAuthorScan' ) ) {
			$covered['user_enumeration'] = 'Wordfence';
		}
		if ( Compat::wordfence_on( 'loginSec_maskLoginErrors' ) ) {
			$covered['login_errors'] = 'Wordfence';
		}
		if ( Compat::wordfence_on( 'other_hideWPVersion' ) ) {
			$covered['hide_version'] = 'Wordfence';
		}
		if ( self::xmlrpc_needed() ) {
			$covered['xmlrpc'] = class_exists( 'Jetpack' ) ? 'Jetpack (needs XML-RPC)' : 'WooPayments (needs XML-RPC)';
		}
		return $covered;
	}

	public function label(): string {
		return __( 'Hardening', 'wphouse' );
	}

	public function description(): string {
		return __( 'Safe defaults: no code editor in wp-admin, no username discovery, generic login errors, no version disclosure, XML-RPC off and basic security headers.', 'wphouse' );
	}

	public function fields(): array {
		return [
			'file_editor'      => [
				'type'  => 'toggle',
				'label' => __( 'Disable the theme/plugin file editor', 'wphouse' ),
			],
			'user_enumeration' => [
				'type'  => 'toggle',
				'label' => __( 'Block username discovery', 'wphouse' ),
				'help'  => __( 'For visitors who are not logged in: ?author=N scans, the REST users endpoint, the users sitemap and author data in oEmbed.', 'wphouse' ),
			],
			'login_errors'     => [
				'type'  => 'toggle',
				'label' => __( 'Generic login errors', 'wphouse' ),
				'help'  => __( 'One message for a wrong username or a wrong password, on wp-login.php and the WooCommerce login form.', 'wphouse' ),
			],
			'hide_version'     => [
				'type'  => 'toggle',
				'label' => __( 'Hide the WordPress version', 'wphouse' ),
			],
			'xmlrpc'           => [
				'type'  => 'toggle',
				'label' => __( 'Disable XML-RPC', 'wphouse' ),
				'help'  => __( 'xmlrpc.php answers 403. The WordPress mobile app and old desktop editors stop working with this site. Skipped automatically when Jetpack or WooPayments is active.', 'wphouse' ),
			],
			'headers'          => [
				'type'  => 'toggle',
				'label' => __( 'Basic security headers', 'wphouse' ),
				'help'  => __( 'X-Content-Type-Options, Referrer-Policy and X-Frame-Options (same origin). Pages served from a full-page cache may not get them.', 'wphouse' ),
			],
			'hsts'             => [
				'type'  => 'toggle',
				'label' => __( 'HSTS (1 year, this domain only)', 'wphouse' ),
				'help'  => __( 'Only on HTTPS. Browsers will refuse plain HTTP for a year, so turn it on only when HTTPS works everywhere on this domain.', 'wphouse' ),
			],
		];
	}

	public function boot(): void {
		if ( $this->feature_on( 'file_editor' ) ) {
			add_filter( 'map_meta_cap', [ $this, 'block_file_editor' ], 10, 2 );
		}
		if ( $this->feature_on( 'user_enumeration' ) ) {
			// Before redirect_canonical(), which would turn ?author=1 into /author/<username>/.
			add_action( 'template_redirect', [ $this, 'block_author_scan' ], 1 );
			add_filter( 'rest_request_before_callbacks', [ $this, 'block_rest_users' ], 10, 3 );
			add_filter( 'wp_sitemaps_add_provider', [ $this, 'drop_users_sitemap' ], 10, 2 );
			add_filter( 'oembed_response_data', [ $this, 'strip_oembed_author' ] );
		}
		if ( $this->feature_on( 'login_errors' ) ) {
			add_filter( 'authenticate', [ $this, 'generic_login_error' ], 99 );
		}
		if ( $this->feature_on( 'hide_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( $this->feature_on( 'xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', [ $this, 'drop_pingback_methods' ] );
			add_filter( 'wp_headers', [ $this, 'drop_pingback_header' ] );
			remove_action( 'wp_head', 'rsd_link' );
			add_action( 'init', [ $this, 'deny_xmlrpc_request' ], 0 );
		}
		if ( $this->feature_on( 'headers' ) || $this->feature_on( 'hsts' ) ) {
			add_action( 'send_headers', [ $this, 'send_security_headers' ] );
		}
	}

	private static function xmlrpc_needed(): bool {
		return class_exists( 'Jetpack' ) || class_exists( 'WC_Payments' );
	}

	/**
	 * @param string[] $caps Primitive caps.
	 * @param string   $cap  Requested cap.
	 * @return string[]
	 */
	public function block_file_editor( array $caps, string $cap ): array {
		return in_array( $cap, [ 'edit_plugins', 'edit_themes', 'edit_files' ], true ) ? [ 'do_not_allow' ] : $caps;
	}

	public function block_author_scan(): void {
		if ( is_user_logged_in() || ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
			return;
		}
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	/**
	 * Match on the controller, not the route string: REST routes are matched case-insensitively.
	 *
	 * @param mixed                                $response Response so far.
	 * @param array<string, mixed>                 $handler  Matched route handler.
	 * @param WP_REST_Request<array<string, mixed>> $request  Request.
	 */
	public function block_rest_users( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		$callback = $handler['callback'] ?? null;
		if ( ! is_user_logged_in() && is_array( $callback ) && ( $callback[0] ?? null ) instanceof WP_REST_Users_Controller ) {
			return new WP_Error( 'rest_user_cannot_view', __( 'Sorry, you are not allowed to list users.', 'wphouse' ), [ 'status' => 401 ] );
		}
		return $response;
	}

	public function drop_users_sitemap( mixed $provider, string $name ): mixed {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * @param array<string, mixed> $data oEmbed response data.
	 * @return array<string, mixed>
	 */
	public function strip_oembed_author( array $data ): array {
		unset( $data['author_name'], $data['author_url'] );
		return $data;
	}

	/**
	 * Replace "unknown user" and "wrong password" with one message. Every other error
	 * (lockouts, captcha, 2FA from Wordfence or others) passes through untouched.
	 */
	public function generic_login_error( mixed $user ): mixed {
		if ( ! $user instanceof WP_Error ) {
			return $user;
		}
		$codes = $user->get_error_codes();
		if ( ! $codes || array_diff( $codes, self::ENUMERATION_ERRORS ) ) {
			return $user;
		}
		return new WP_Error(
			'incorrect_password',
			__( '<strong>Error:</strong> The username, email address or password is incorrect.', 'wphouse' )
		);
	}

	/**
	 * @param array<string, mixed> $methods XML-RPC methods.
	 * @return array<string, mixed>
	 */
	public function drop_pingback_methods( array $methods ): array {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	/**
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, string>
	 */
	public function drop_pingback_header( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public function deny_xmlrpc_request(): void {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return;
		}
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'XML-RPC is disabled on this site.';
		exit;
	}

	public function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		$sent = array_map( static fn( $line ) => strtolower( strtok( $line, ':' ) ), headers_list() );
		$set  = static function ( string $name, string $value ) use ( $sent ): void {
			if ( ! in_array( strtolower( $name ), $sent, true ) ) {
				header( $name . ': ' . $value );
			}
		};
		if ( $this->feature_on( 'headers' ) ) {
			$set( 'X-Content-Type-Options', 'nosniff' );
			$set( 'Referrer-Policy', 'strict-origin-when-cross-origin' );
			$set( 'X-Frame-Options', 'SAMEORIGIN' );
		}
		if ( $this->feature_on( 'hsts' ) && is_ssl() ) {
			$set( 'Strict-Transport-Security', 'max-age=31536000' );
		}
	}
}
