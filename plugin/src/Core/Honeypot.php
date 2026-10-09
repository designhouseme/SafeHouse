<?php
/**
 * One-use form challenges, bound to a short-lived browser cookie and one form context.
 *
 * Cached pages contain only placeholders. A same-origin request issues a fresh challenge;
 * the server checks its signature, age and rotating empty trap when the form is submitted.
 * Atomic, bounded storage prevents replay and limits issuance and submissions. This raises
 * the cost of scripted submissions; it is not proof of a human or distributed flood control.
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
	private const RATE_SECONDS   = 600;
	private const SUBMIT_LIMITS  = [
		'register'     => 5,
		'lostpassword' => 5,
		'comments'     => 10,
	];

	public static function boot(): void {
		FormGuard::maybe_install();
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
		$renew   = null === $browser || $browser['issued'] + self::COOKIE_SECONDS - $now <= self::LIFETIME;
		if ( $renew ) {
			$payload = '1.' . $now . '.' . bin2hex( random_bytes( 32 ) );
			$cookie  = $payload . '.' . hash_hmac( 'sha256', 'cookie|' . $payload, self::key() );
		} else {
			$cookie = $browser['value'];
		}
		$retry_at = null;
		$reason   = self::budget( $form, 'issue', $cookie, $retry_at );
		if ( '' !== $reason ) {
			self::unavailable( $reason, $retry_at );
		}
		if ( $renew ) {
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
		}
		$payload = '2.' . $form . '.' . $target . '.' . self::milliseconds() . '.' . bin2hex( random_bytes( 16 ) );
		$ticket  = FormGuard::issue( $form, self::fingerprint( $payload, $cookie ), $now + self::LIFETIME );
		if ( null === $ticket['slot'] ) {
			self::unavailable( $ticket['reason'] );
		}
		$payload .= '.' . $ticket['slot'];
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
	public static function check( string $form, int $target = 0, ?object $operation = null ): string {
		/** @var \WeakMap<object, array<string, string>>|null $results */
		static $results = null;
		if ( ! self::valid_context( $form, $target ) ) {
			return 'context';
		}
		$challenge = self::post_value( self::FIELD, 256 );
		if ( null === $challenge || '' === $challenge ) {
			return 'missing';
		}
		if ( ! preg_match( '/\A2\.(register|lostpassword|comments)\.(0|[1-9][0-9]{0,18})\.([1-9][0-9]{12,15})\.([a-f0-9]{32})\.(0|[1-9][0-9]{0,4})\.([a-f0-9]{64})\z/', $challenge, $parts ) ) {
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
		if ( ! hash_equals( self::sign_challenge( $payload, $browser['value'] ), $parts[6] ) ) {
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
		// Only the same explicit handler operation may share a verdict. Revalidate signed inputs
		// and the trap above even for a repeated hook; separate requests/operations consume once.
		$key    = hash( 'sha256', $challenge . '|' . $browser['value'] );
		$cached = [];
		if ( null !== $operation ) {
			$results ??= new \WeakMap();
			$cached    = $results[ $operation ] ?? [];
			if ( isset( $cached[ $key ] ) ) {
				return $cached[ $key ];
			}
		}
		$prefix = substr( $payload, 0, -( strlen( $parts[5] ) + 1 ) );
		$reason = FormGuard::consume( $form, (int) $parts[5], self::fingerprint( $prefix, $browser['value'] ) );
		if ( '' === $reason ) {
			$reason = self::budget( $form, 'submit', $browser['value'] );
		}
		if ( null !== $operation ) {
			$cached[ $key ]        = $reason;
			$results[ $operation ] = $cached;
		}
		return $reason;
	}

	/** Domain-separated hashes keep raw cookies and addresses out of persistent counters. */
	private static function budget( string $form, string $stage, string $cookie, ?int &$retry_at = null ): string {
		$limit   = 'issue' === $stage ? 20 : self::SUBMIT_LIMITS[ $form ];
		$subject = hash_hmac( 'sha256', 'budget|browser|' . $cookie, self::key() );
		$reason  = FormGuard::budget( $form, $stage, 'browser', $subject, $limit, self::RATE_SECONDS, $retry_at );
		if ( '' !== $reason ) {
			return $reason;
		}
		$ip = Net::quota_subject();
		if ( null === $ip ) {
			return '';
		}
		// Shared networks get substantially broader limits; unknown proxy addresses are never
		// put in one bucket. Cookie resets cannot reset a correctly configured IP budget.
		$limit   = 'issue' === $stage ? 200 : self::SUBMIT_LIMITS[ $form ] * 20;
		$subject = hash_hmac( 'sha256', 'budget|ip|' . $ip, self::key() );
		return FormGuard::budget( $form, $stage, 'ip', $subject, $limit, self::RATE_SECONDS, $retry_at );
	}

	private static function fingerprint( string $payload, string $cookie ): string {
		return hash_hmac( 'sha256', 'ticket|' . $payload . '|' . $cookie, self::key() );
	}

	private static function unavailable( string $reason, ?int $retry_at = null ): never {
		$limited = 'rate_limited' === $reason;
		// Use the refused row's deadline: the window can roll over while the request finishes.
		$retry = $limited ? max( 1, ( $retry_at ?? 0 ) - time() ) : 60;
		header( 'Retry-After: ' . $retry );
		wp_send_json_error(
			[
				'reason'     => $reason,
				'retryAfter' => $retry,
			],
			$limited ? 429 : 503
		);
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
