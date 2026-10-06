<?php
/**
 * Cloudflare Turnstile: keys from wp-config.php, the widget placeholder and token verification.
 *
 *   define( 'WPHOUSE_TURNSTILE_SITE_KEY', '0x4AAAA…' );
 *   define( 'WPHOUSE_TURNSTILE_SECRET_KEY', '0x4AAAA…' );
 *
 * Cloudflare's test keys (1x0000…, 2x0000…, 3x0000…) always give the same answer, so they are
 * refused on production sites: a staging wp-config copied to production must not switch the
 * check off quietly.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Turnstile {

	public const FIELD       = 'cf-turnstile-response';
	public const HEADER      = 'X-WPHouse-Turnstile';
	public const SCRIPT_URL  = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=wphouseTurnstileReady';
	public const PASSED      = 'passed';
	public const FAILED      = 'failed';
	public const UNAVAILABLE = 'unavailable';

	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	// A broken secret is our configuration, not the visitor. Treated like an outage, so that a typo
	// in wp-config follows the "when unavailable" setting instead of locking everyone out of wp-login.
	private const CONFIG_ERRORS = [ 'missing-input-secret', 'invalid-input-secret', 'internal-error' ];

	public static function site_key(): string {
		return defined( 'WPHOUSE_TURNSTILE_SITE_KEY' ) ? trim( (string) WPHOUSE_TURNSTILE_SITE_KEY ) : '';
	}

	private static function secret_key(): string {
		return defined( 'WPHOUSE_TURNSTILE_SECRET_KEY' ) ? trim( (string) WPHOUSE_TURNSTILE_SECRET_KEY ) : '';
	}

	private static function is_test_key( string $key ): bool {
		return (bool) preg_match( '/^[123]x0{15,}/', $key );
	}

	private static function uses_test_keys(): bool {
		return self::is_test_key( self::site_key() ) || self::is_test_key( self::secret_key() );
	}

	public static function configured(): bool {
		return '' === self::problem();
	}

	/** Why Turnstile is off, untranslated key: '' (on), 'missing' or 'test_keys'. */
	public static function problem(): string {
		if ( '' === self::site_key() || '' === self::secret_key() ) {
			return 'missing';
		}
		if ( self::uses_test_keys() && 'production' === wp_get_environment_type() ) {
			return 'test_keys';
		}
		return '';
	}

	/** Empty placeholder that assets/bots.js turns into a widget. Action names one form, so a token cannot be replayed on another. */
	public static function widget( string $action ): string {
		return '<div class="wphouse-turnstile" data-sitekey="' . esc_attr( self::site_key() ) . '" data-action="' . esc_attr( $action ) . '"></div>';
	}

	/**
	 * Check a token with Cloudflare. Each token is single-use, so the result is kept for the request.
	 *
	 * @return string self::PASSED, self::FAILED or self::UNAVAILABLE.
	 */
	public static function verify( string $token, string $action ): string {
		static $results = [];
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return self::FAILED;
		}
		$key = $action . '|' . $token;
		if ( ! isset( $results[ $key ] ) ) {
			$results[ $key ] = self::ask_cloudflare( $token, $action );
		}
		return $results[ $key ];
	}

	private static function ask_cloudflare( string $token, string $action ): string {
		$body = [
			'secret'   => self::secret_key(),
			'response' => $token,
		];
		// remoteip is optional. Behind Cloudflare or another proxy without WPHOUSE_TRUSTED_PROXIES,
		// client_ip() is the proxy, and a wrong address is worse than none.
		if ( Net::knows_visitor_ip() ) {
			$body['remoteip'] = Net::client_ip();
		}
		$response = wp_remote_post(
			self::VERIFY_URL,
			[
				'timeout' => 5,
				'body'    => $body,
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return self::UNAVAILABLE;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return self::UNAVAILABLE;
		}
		if ( empty( $data['success'] ) ) {
			return array_intersect( (array) ( $data['error-codes'] ?? [] ), self::CONFIG_ERRORS ) ? self::UNAVAILABLE : self::FAILED;
		}
		// Test keys answer with a fixed hostname and action; real keys must match this form and site.
		if ( ! self::uses_test_keys() && ( ( $data['action'] ?? '' ) !== $action || ! self::own_host( (string) ( $data['hostname'] ?? '' ) ) ) ) {
			return self::FAILED;
		}
		return self::PASSED;
	}

	private static function own_host( string $hostname ): bool {
		$hosts = array_filter( [ wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) ], 'is_string' );
		return in_array( strtolower( $hostname ), array_map( 'strtolower', $hosts ), true );
	}
}
