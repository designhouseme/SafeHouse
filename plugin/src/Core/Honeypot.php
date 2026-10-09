<?php
/**
 * Stateless form challenges, bound to a short-lived browser cookie and one form context.
 *
 * Cached pages contain only placeholders. A same-origin request issues a fresh challenge;
 * the server checks its signature, age and rotating empty trap when the form is submitted.
 * This raises the cost of simple scripted submissions; it is not proof of a human and a
 * challenge can be reused by its browser during its lifetime. Nothing is written to the DB.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Honeypot {

	public const FIELD = 'shouse_challenge';

	private const FORMS          = [ 'register', 'lostpassword', 'comments' ];
	private const LIFETIME       = 1200;
	private const COOKIE_SECONDS = 86400;
	private const WAIT_MS        = 1000;

	public static function boot(): void {
		add_action( 'wp_ajax_shouse_challenge', [ self::class, 'endpoint' ] );
		add_action( 'wp_ajax_nopriv_shouse_challenge', [ self::class, 'endpoint' ] );
	}

	/** No cookie, timestamp or usable proof is included in cacheable HTML. */
	public static function markup( string $form, int $target = 0 ): string {
		// comment_form() may receive an explicit post while the global post is unset. Its hidden
		// comment_post_ID, read by JavaScript, is authoritative; zero is only a placeholder here.
		if ( ! self::valid_context( $form, $target ) && ! ( 'comments' === $form && 0 === $target ) ) {
			return '';
		}
		$trap = 'contact_' . bin2hex( random_bytes( 10 ) );
		return '<div class="shouse-hp" data-form="' . esc_attr( $form ) . '" data-target="' . esc_attr( (string) $target ) . '" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">'
			. '<label>' . esc_html__( 'Leave this field empty', 'shouse' ) . ' <input type="text" name="' . esc_attr( $trap ) . '" data-shouse-trap value="" tabindex="-1" autocomplete="off"></label>'
			. '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="">'
			. '</div>'
			. '<p class="shouse-hp-status" role="status" aria-live="polite" hidden></p>'
			. '<noscript><p>' . esc_html__( 'JavaScript and cookies are required to submit this form. Please enable them and reload the page.', 'shouse' ) . '</p></noscript>';
	}

	/**
	 * Public, deliberately nonce-free bootstrap: page-cache lifetimes must not expire forms.
	 * A custom header and Origin/Fetch Metadata checks prevent cross-site simple requests.
	 */
	public static function endpoint(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Vary: Cookie, Origin' );
		if ( 'POST' !== self::server_value( 'REQUEST_METHOD', 8 ) ) {
			header( 'Allow: POST' );
			wp_send_json_error( [ 'reason' => 'method' ], 405 );
		}
		if ( '1' !== self::server_value( 'HTTP_X_SHOUSE_FORM', 8 ) || ! self::request_origin_allowed() ) {
			wp_send_json_error( [ 'reason' => 'origin' ], 403 );
		}
		$form       = self::post_value( 'form', 20 );
		$raw_target = self::post_value( 'target', 20 );
		$target     = null === $raw_target ? null : self::decimal( $raw_target );
		if ( null === $form || null === $target || ! self::valid_context( $form, $target ) ) {
			wp_send_json_error( [ 'reason' => 'context' ], 400 );
		}
		$browser = self::browser();
		$now     = time();
		if ( null === $browser || $browser['issued'] + self::COOKIE_SECONDS - $now <= self::LIFETIME ) {
			$payload = '1.' . $now . '.' . bin2hex( random_bytes( 32 ) );
			$cookie  = $payload . '.' . hash_hmac( 'sha256', 'cookie|' . $payload, self::key() );
			if ( headers_sent() || ! setcookie(
				self::cookie_name(),
				$cookie,
				[
					'expires'  => $now + self::COOKIE_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			) ) {
				wp_send_json_error( [ 'reason' => 'browser' ], 503 );
			}
		} else {
			$cookie = $browser['value'];
		}
		$payload = '1.' . $form . '.' . $target . '.' . self::milliseconds() . '.' . bin2hex( random_bytes( 16 ) );
		wp_send_json_success(
			[
				'challenge' => $payload . '.' . self::sign_challenge( $payload, $cookie ),
				'trap'      => self::trap( $payload, $cookie ),
				'expiresIn' => self::LIFETIME,
				'wait'      => self::WAIT_MS,
			]
		);
	}

	/** Empty string on success; otherwise a finite, non-sensitive reason suitable for logs. */
	public static function check( string $form, int $target = 0 ): string {
		if ( ! self::valid_context( $form, $target ) ) {
			return 'context';
		}
		$challenge = self::post_value( self::FIELD, 256 );
		if ( null === $challenge || '' === $challenge ) {
			return 'missing';
		}
		if ( ! preg_match( '/\A1\.(register|lostpassword|comments)\.(0|[1-9][0-9]{0,18})\.([1-9][0-9]{12,15})\.([a-f0-9]{32})\.([a-f0-9]{64})\z/', $challenge, $parts ) ) {
			return 'tampered';
		}
		if ( $parts[1] !== $form || self::decimal( $parts[2] ) !== $target ) {
			return 'context';
		}
		$browser = self::browser();
		if ( null === $browser ) {
			return 'browser';
		}
		$payload = substr( $challenge, 0, -65 );
		if ( ! hash_equals( self::sign_challenge( $payload, $browser['value'] ), $parts[5] ) ) {
			return 'tampered';
		}
		$age = self::milliseconds() - (int) $parts[3];
		if ( $age < 0 || $age > self::LIFETIME * 1000 ) {
			return 'expired';
		}
		if ( $age < self::WAIT_MS ) {
			return 'too_fast';
		}
		if ( '' !== self::post_value( self::trap( $payload, $browser['value'] ), 1 ) ) {
			return 'trap';
		}
		return '';
	}

	private static function valid_context( string $form, int $target ): bool {
		return in_array( $form, self::FORMS, true ) && ( 'comments' === $form ? $target > 0 : 0 === $target );
	}

	/** Reject overflow and non-canonical integers before a cast can change their meaning. */
	private static function decimal( string $value ): ?int {
		if ( ! preg_match( '/\A(?:0|[1-9][0-9]{0,18})\z/', $value ) || (string) (int) $value !== $value ) {
			return null;
		}
		return (int) $value;
	}

	private static function milliseconds(): int {
		return (int) floor( microtime( true ) * 1000 );
	}

	/** A separate cookie per installation, including path-based multisite blogs. */
	private static function cookie_name(): string {
		return 'shouse_browser_' . substr( hash( 'sha256', get_current_blog_id() . '|' . home_url( '/' ) ), 0, 16 );
	}

	private static function key(): string {
		return hash_hmac( 'sha256', 'shouse-honeypot-v1|' . get_current_blog_id() . '|' . home_url( '/' ), wp_salt( 'nonce' ) );
	}

	/** @return array{value: string, issued: int}|null Validated browser binding. */
	private static function browser(): ?array {
		$name = self::cookie_name();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- bounded string, strict format and HMAC below; sanitization would change signed bytes.
		$raw = $_COOKIE[ $name ] ?? null;
		// phpcs:enable
		if ( ! is_string( $raw ) || strlen( $raw ) > 160 ) {
			return null;
		}
		$cookie = wp_unslash( $raw );
		if ( ! preg_match( '/\A1\.([1-9][0-9]{9,11})\.([a-f0-9]{64})\.([a-f0-9]{64})\z/', $cookie, $parts ) ) {
			return null;
		}
		$issued = (int) $parts[1];
		$age    = time() - $issued;
		if ( $age < 0 || $age > self::COOKIE_SECONDS || ! hash_equals( hash_hmac( 'sha256', 'cookie|' . substr( $cookie, 0, -65 ), self::key() ), $parts[3] ) ) {
			return null;
		}
		return [
			'value'  => $cookie,
			'issued' => $issued,
		];
	}

	private static function sign_challenge( string $payload, string $cookie ): string {
		return hash_hmac( 'sha256', 'challenge|' . $payload . '|' . $cookie, self::key() );
	}

	private static function trap( string $payload, string $cookie ): string {
		return 'contact_' . substr( hash_hmac( 'sha256', 'trap|' . $payload . '|' . $cookie, self::key() ), 0, 20 );
	}

	/** No recursive unslashing or processing of attacker-controlled arrays. */
	private static function post_value( string $name, int $maximum ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- public challenge protocol; each bounded scalar is validated by its caller, not stored.
		$value = $_POST[ $name ] ?? null;
		// phpcs:enable
		return is_string( $value ) && strlen( $value ) <= $maximum ? wp_unslash( $value ) : null;
	}

	private static function server_value( string $name, int $maximum ): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- bounded scalar, exact comparison or URL validation by caller.
		$value = $_SERVER[ $name ] ?? null;
		return is_string( $value ) && strlen( $value ) <= $maximum ? wp_unslash( $value ) : null;
	}

	private static function request_origin_allowed(): bool {
		$fetch_site = self::server_value( 'HTTP_SEC_FETCH_SITE', 32 );
		if ( isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) && ( null === $fetch_site || 'cross-site' === strtolower( trim( $fetch_site ) ) ) ) {
			return false;
		}
		if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			return true;
		}
		$origin = self::server_value( 'HTTP_ORIGIN', 512 );
		if ( null === $origin || ! preg_match( '/\Ahttps?:\/\/(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::[0-9]{1,5})?\z/i', $origin ) ) {
			return false;
		}
		$origin = self::origin( $origin );
		return '' !== $origin && in_array( $origin, [ self::origin( home_url( '/' ) ), self::origin( site_url( '/' ) ) ], true );
	}

	/** Normalize default ports so equivalent, configured origins compare equally. */
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}
		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
	}
}
