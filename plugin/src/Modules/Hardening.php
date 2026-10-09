<?php
/**
 * Passive hardening: small, safe defaults that do not change how the site works for visitors.
 * Each part is skipped when Wordfence (or wp-config) already provides it.
 *
 * Deliberately absent: a CSP (it breaks payment iframes unless tuned per site), stripping
 * ?ver= from assets (breaks cache busting) and any .htaccess writes.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_Error;
use WP_REST_Request;
use WP_REST_Users_Controller;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;

defined( 'ABSPATH' ) || exit;

final class Hardening extends AbstractModule {

	private const ENUMERATION_ERRORS = [ 'invalid_username', 'invalid_email', 'incorrect_password' ];
	// Any of these turns a self-registered account into a site manager. Editors and shop managers
	// have some of them too, which is the point: a name check would miss them.
	private const ADMIN_CAPS = [ 'manage_options', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'edit_themes', 'edit_files', 'update_core', 'unfiltered_html', 'manage_woocommerce' ];

	public function id(): string {
		return 'hardening';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [
			'file_editor'       => true,
			'user_enumeration'  => true,
			'safe_default_role' => true,
			'login_errors'      => true,
			'hide_version'      => true,
			'reduce_discovery'  => false,
			'xmlrpc'            => true,
			'disable_pingbacks' => true,
			'headers'           => true,
			'hsts'              => false,
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
		// Wordfence masks wp-login.php only; the WooCommerce My Account form still tells unknown
		// users from wrong passwords (checked with Wordfence 9.0.2 + WooCommerce 11.1.2), so we keep
		// ours on whenever WooCommerce is active.
		if ( Compat::wordfence_on( 'loginSec_maskLoginErrors' ) && ! Compat::woocommerce_active() ) {
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
		return __( 'Hardening', 'shouse' );
	}

	public function description(): string {
		return __( 'Safe defaults: no code editor in wp-admin, no username discovery, no admin-level role for new accounts, generic login errors, no version disclosure, XML-RPC off and basic security headers. Adds a registration check to Site Health.', 'shouse' );
	}

	public function fields(): array {
		return [
			'file_editor'       => [
				'type'  => 'toggle',
				'label' => __( 'Disable the theme/plugin file editor', 'shouse' ),
			],
			'user_enumeration'  => [
				'type'  => 'toggle',
				'label' => __( 'Block username discovery', 'shouse' ),
				'help'  => __( 'For visitors who are not logged in: ?author=N scans, the REST users endpoint, the users sitemap and author data in oEmbed.', 'shouse' ),
			],
			'safe_default_role' => [
				'type'  => 'toggle',
				'label' => __( 'Keep new accounts out of admin-level roles', 'shouse' ),
				'help'  => __( 'If the default role for new accounts can manage users, plugins, options, the shop or raw HTML (for example after a database attack), new accounts get Subscriber instead.', 'shouse' ),
			],
			'login_errors'      => [
				'type'  => 'toggle',
				'label' => __( 'Generic login errors', 'shouse' ),
				'help'  => __( 'One message for a wrong username or a wrong password, on wp-login.php and the WooCommerce login form.', 'shouse' ),
			],
			'hide_version'      => [
				'type'  => 'toggle',
				'label' => __( 'Hide the WordPress version', 'shouse' ),
			],
			'reduce_discovery'  => [
				'type'  => 'toggle',
				'label' => __( 'Reduce passive WordPress discovery', 'shouse' ),
				'help'  => __( 'Remove REST API discovery links, RSD, Windows Live Writer and shortlinks from page metadata and headers. REST endpoints and asset versions keep working. Some publishing clients may need manual configuration; this does not hide WordPress from a determined scanner.', 'shouse' ),
			],
			'xmlrpc'            => [
				'type'  => 'toggle',
				'label' => __( 'Disable XML-RPC', 'shouse' ),
				'help'  => __( 'xmlrpc.php answers 403. The WordPress mobile app and old desktop editors stop working with this site. Skipped automatically when Jetpack or WooPayments is active.', 'shouse' ),
			],
			'disable_pingbacks' => [
				'type'  => 'toggle',
				'label' => __( 'Disable XML-RPC pingbacks', 'shouse' ),
				'help'  => __( 'Remove pingback methods and the X-Pingback header, including when Jetpack or WooPayments needs authenticated XML-RPC. Other XML-RPC methods are unchanged.', 'shouse' ),
			],
			'headers'           => [
				'type'  => 'toggle',
				'label' => __( 'Basic security headers', 'shouse' ),
				'help'  => __( 'X-Content-Type-Options, Referrer-Policy and X-Frame-Options (same origin). Pages served from a full-page cache may not get them.', 'shouse' ),
			],
			'hsts'              => [
				'type'  => 'toggle',
				'label' => __( 'HSTS (1 year, this domain only)', 'shouse' ),
				'help'  => __( 'Only on HTTPS. Browsers will refuse plain HTTP for a year, so turn it on only when HTTPS works everywhere on this domain.', 'shouse' ),
			],
		];
	}

	public function boot(): void {
		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( $this->feature_on( 'safe_default_role' ) ) {
			add_filter( 'option_default_role', [ $this, 'safe_default_role' ] );
		}
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
		if ( $this->feature_on( 'reduce_discovery' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		$disable_xmlrpc = $this->feature_on( 'xmlrpc' );
		if ( $disable_xmlrpc || $this->feature_on( 'disable_pingbacks' ) ) {
			add_filter( 'xmlrpc_methods', [ $this, 'drop_pingback_methods' ] );
			add_filter( 'wp_headers', [ $this, 'drop_pingback_header' ] );
		}
		if ( $disable_xmlrpc ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
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
			return new WP_Error( 'rest_user_cannot_view', __( 'Sorry, you are not allowed to list users.', 'shouse' ), [ 'status' => 401 ] );
		}
		return $response;
	}

	/**
	 * Applied on read: a value planted straight in the database never reaches wp_insert_user().
	 * Settings → General and WP-CLI see the real value, otherwise they would show Subscriber and
	 * update_option() would refuse to save Subscriber as "unchanged", so it could not be repaired.
	 */
	public function safe_default_role( mixed $role ): mixed {
		global $pagenow;
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( is_admin() && in_array( $pagenow, [ 'options-general.php', 'options.php' ], true ) ) ) {
			return $role;
		}
		return is_string( $role ) && ! self::is_admin_role( $role ) ? $role : 'subscriber';
	}

	public static function is_admin_role( string $role ): bool {
		$object = get_role( $role );
		if ( ! $object ) {
			return false;
		}
		foreach ( self::ADMIN_CAPS as $cap ) {
			if ( $object->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['shouse_registration'] = [
			'label' => __( 'SafeHouse registration check', 'shouse' ),
			'test'  => [ $this, 'site_health_result' ],
		];
		return $tests;
	}

	/** @return array<string, mixed> */
	public function site_health_result(): array {
		remove_filter( 'option_default_role', [ $this, 'safe_default_role' ] );
		$role = (string) get_option( 'default_role' );
		if ( $this->feature_on( 'safe_default_role' ) ) {
			add_filter( 'option_default_role', [ $this, 'safe_default_role' ] );
		}
		$names = wp_roles()->get_names();
		$name  = isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;

		if ( self::is_admin_role( $role ) ) {
			$status      = 'critical';
			$label       = __( 'New accounts are set to get a role that can manage the site', 'shouse' );
			$description = sprintf(
				/* translators: %s: role name, e.g. Administrator. */
				__( 'The default role for new accounts is %s, which can manage users, plugins, settings or the shop, or post raw HTML. Attackers set this so that they can register their own administrator. Change it in Settings → General and check who changed it.', 'shouse' ),
				$name
			);
			if ( $this->feature_on( 'safe_default_role' ) ) {
				$description .= ' ' . __( 'Until then SafeHouse gives new accounts the Subscriber role.', 'shouse' );
			}
		} elseif ( get_option( 'users_can_register' ) ) {
			$status      = 'recommended';
			$label       = __( 'Anyone can register an account', 'shouse' );
			$description = sprintf(
				/* translators: %s: role name, e.g. Subscriber. */
				__( 'The "Anyone can register" setting is on, with %s as the role for new accounts. Bots use open registration for spam accounts and to probe plugins that trust any logged-in user. If the site does not need WordPress accounts, turn it off in Settings → General. WooCommerce customer accounts have their own setting and keep working.', 'shouse' ),
				$name
			);
		} else {
			$status      = 'good';
			$label       = __( 'Registration is closed', 'shouse' );
			$description = __( 'Only administrators can create WordPress accounts.', 'shouse' );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Security', 'shouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => 'good' === $status ? '' : '<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Open general settings', 'shouse' ) . '</a>',
			'test'        => 'shouse_registration',
		];
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
			__( '<strong>Error:</strong> The username, email address or password is incorrect.', 'shouse' )
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
		foreach ( array_keys( $headers ) as $name ) {
			if ( 0 === strcasecmp( $name, 'X-Pingback' ) ) {
				unset( $headers[ $name ] );
			}
		}
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
