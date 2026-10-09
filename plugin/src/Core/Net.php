<?php
/**
 * Client IP detection that cannot be spoofed with request headers.
 *
 * REMOTE_ADDR is used unless the request comes from a proxy we were told about:
 *   - Cloudflare: "Proxy in front of the site" on the settings page, or
 *     define( 'SHOUSE_TRUSTED_PROXIES', 'cloudflare' ). CF-Connecting-IP is believed only when the
 *     connection itself comes from a Cloudflare address (the TCP peer cannot be forged); a request
 *     that reaches the server directly is judged by REMOTE_ADDR alone.
 *   - Other proxies, in wp-config:
 *     define( 'SHOUSE_TRUSTED_PROXIES', [ '10.0.0.0/8' ] );
 *     define( 'SHOUSE_PROXY_HEADER', 'HTTP_X_REAL_IP' ); // default HTTP_X_FORWARDED_FOR
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Net {

	/** Cloudflare's edge ranges from https://www.cloudflare.com/ips/, checked 2026-10-06. Refresh with dev/cloudflare-ips.sh. */
	public const CLOUDFLARE_RANGES = [
		// cloudflare-ips:begin
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
		// cloudflare-ips:end
	];

	private const FORWARD_HEADERS = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP' ];

	/**
	 * Whether client_ip() is the visitor and not a proxy in front of the site: proxies are
	 * configured, or the request carries no forwarding header at all. A forged header only makes
	 * this false, which callers must treat as "unknown", never as a reason to trust anything.
	 */
	public static function knows_visitor_ip(): bool {
		if ( self::behind_cloudflare() ) {
			return ! self::from_cloudflare() || '' !== self::cloudflare_visitor();
		}
		if ( defined( 'SHOUSE_TRUSTED_PROXIES' ) && is_array( SHOUSE_TRUSTED_PROXIES ) && SHOUSE_TRUSTED_PROXIES ) {
			return true;
		}
		foreach ( self::FORWARD_HEADERS as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function client_ip(): string {
		$remote = self::remote_addr();
		if ( '' !== $remote && self::behind_cloudflare() ) {
			$visitor = self::from_cloudflare( $remote ) ? self::cloudflare_visitor() : '';
			return '' !== $visitor ? $visitor : $remote;
		}
		if ( '' === $remote || ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$header = defined( 'SHOUSE_PROXY_HEADER' ) ? (string) SHOUSE_PROXY_HEADER : 'HTTP_X_FORWARDED_FOR';
		if ( empty( $_SERVER[ $header ] ) ) {
			return $remote;
		}

		// Walk the chain from the right and return the first address that is not one of our proxies.
		$chain = array_reverse( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) ) );
		foreach ( $chain as $candidate ) {
			$candidate = self::valid_ip( $candidate );
			if ( '' === $candidate ) {
				return $remote;
			}
			if ( ! self::is_trusted_proxy( $candidate ) ) {
				return $candidate;
			}
		}
		return $remote;
	}

	/**
	 * A conservative address for anonymous quotas, or null when the visitor is unknown.
	 * Unlike log attribution, this never falls back to a known shared proxy address.
	 * Callers must hash the result before persistence. Real IPv6 visitors share a /64;
	 * IPv4-mapped IPv6 is first normalized to IPv4 so alternate spelling cannot reset a quota.
	 */
	public static function quota_subject(): ?string {
		// Validate raw strings rather than sanitizing malformed input into a valid address.
		$remote = self::quota_ip( $_SERVER['REMOTE_ADDR'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict raw IP validation; do not repair malformed input.
		if ( '' === $remote ) {
			return null;
		}

		$cloudflare = self::behind_cloudflare();
		if ( $cloudflare && self::from_cloudflare( $remote ) ) {
			$visitor = self::quota_ip( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Strict raw single-IP validation.
			return self::quota_address( $visitor );
		}

		if ( ! $cloudflare && self::is_trusted_proxy( $remote ) ) {
			$header = defined( 'SHOUSE_PROXY_HEADER' ) ? SHOUSE_PROXY_HEADER : 'HTTP_X_FORWARDED_FOR';
			if ( ! is_string( $header ) || ! preg_match( '/^HTTP_[A-Z0-9_]{1,80}$/D', $header ) ) {
				return null;
			}
			$value = $_SERVER[ $header ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Bounded, strictly validated raw IP list; never rendered.
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
			// Appending proxies may retain an arbitrary client-supplied left prefix. Inspect
			// only the bounded right suffix; that prefix cannot disable a trustworthy IP quota.
			$truncated = strlen( $value ) > 2048;
			$parts     = explode( ',', substr( $value, -2048 ) );
			$visited   = 0;
			for ( $index = count( $parts ) - 1; $index >= 0 && $visited < 32; --$index, ++$visited ) {
				if ( 0 === $index && $truncated ) {
					// A cut-off token must never become valid just because its prefix was removed.
					return null;
				}
				$candidate = self::quota_ip( trim( $parts[ $index ], ' ' ) );
				if ( '' === $candidate ) {
					return null;
				}
				if ( ! self::is_trusted_proxy( $candidate ) ) {
					return self::quota_address( $candidate );
				}
			}
			return null;
		}

		// Forged forwarding headers cannot opt a direct public connection out of quotas.
		return self::quota_address( $remote );
	}

	/** Parse and canonicalize a single address without accepting ports, lists or mapped aliases. */
	private static function quota_ip( mixed $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 45 || '' === self::valid_ip( $value ) ) {
			return '';
		}
		$packed = inet_pton( $value );
		if ( false === $packed ) {
			return '';
		}
		if ( 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$packed = substr( $packed, 12 );
		}
		$canonical = inet_ntop( $packed );
		return false === $canonical ? '' : $canonical;
	}

	/** Refuse shared/unknown address space and group genuine IPv6 hosts by their /64 prefix. */
	private static function quota_address( string $ip ): ?string {
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) || self::from_cloudflare( $ip ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		if ( 4 === strlen( $packed ) ) {
			// PHP's private/reserved flags do not cover all shared or special-use IPv4 space.
			foreach ( [ '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4' ] as $range ) {
				if ( self::in_range( $ip, $range ) ) {
					return null;
				}
			}
			return $ip;
		}
		if ( ! self::in_range( $ip, '2000::/3' ) || self::in_range( $ip, '2001:db8::/32' ) ) {
			return null;
		}
		$prefix = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );
		return false === $prefix ? null : $prefix . '/64';
	}

	/** The site sits behind Cloudflare: the settings page says so, or SHOUSE_TRUSTED_PROXIES is 'cloudflare'. */
	public static function behind_cloudflare(): bool {
		if ( defined( 'SHOUSE_TRUSTED_PROXIES' ) ) {
			return 'cloudflare' === SHOUSE_TRUSTED_PROXIES;
		}
		$settings = get_option( Settings::OPTION );
		return is_array( $settings ) && 'cloudflare' === ( $settings['general']['proxy'] ?? '' );
	}

	/** The connection (REMOTE_ADDR, or the given address) is a Cloudflare edge server. */
	public static function from_cloudflare( ?string $ip = null ): bool {
		$ip ??= self::remote_addr();
		foreach ( self::CLOUDFLARE_RANGES as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function site_health_test( array $tests ): array {
		if ( self::from_cloudflare() || self::behind_cloudflare() ) {
			$tests['direct']['shouse_proxy'] = [
				'label' => __( 'SafeHouse and Cloudflare', 'shouse' ),
				'test'  => [ self::class, 'site_health_result' ],
			];
		}
		return $tests;
	}

	/** @return array<string, mixed> */
	public static function site_health_result(): array {
		if ( self::from_cloudflare() && ! self::behind_cloudflare() ) {
			$status = 'critical';
			$label  = __( 'Requests come through Cloudflare, but SafeHouse is not set up for it', 'shouse' );
			$text   = __( 'Every visitor looks like a Cloudflare server: the activity log shows Cloudflare addresses and login limits cannot block by address. In SafeHouse → General, set "Proxy in front of the site" to Cloudflare.', 'shouse' );
		} elseif ( self::from_cloudflare() ) {
			$status = 'good';
			$label  = __( 'SafeHouse sees visitors\' real addresses behind Cloudflare', 'shouse' );
			$text   = __( 'The visitor address comes from Cloudflare\'s CF-Connecting-IP header, which is trusted only on connections from Cloudflare\'s own servers.', 'shouse' );
		} else {
			$status = 'recommended';
			$label  = __( 'SafeHouse is set up for Cloudflare, but this request did not come through it', 'shouse' );
			$text   = __( 'That is fine when you reach the server directly. If the site no longer uses Cloudflare, set "Proxy in front of the site" back to none.', 'shouse' );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Security', 'shouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'test'        => 'shouse_proxy',
		];
	}

	private static function remote_addr(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
	}

	private static function cloudflare_visitor(): string {
		return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
	}

	private static function is_trusted_proxy( string $ip ): bool {
		if ( ! defined( 'SHOUSE_TRUSTED_PROXIES' ) || ! is_array( SHOUSE_TRUSTED_PROXIES ) ) {
			return false;
		}
		foreach ( SHOUSE_TRUSTED_PROXIES as $range ) {
			if ( self::in_range( $ip, (string) $range ) ) {
				return true;
			}
		}
		return false;
	}

	public static function in_range( string $ip, string $range ): bool {
		if ( ! self::valid_range( $range ) ) {
			return false;
		}
		[ $subnet, $bits ] = array_pad( explode( '/', $range, 2 ), 2, null );
		$ip_bin            = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		$subnet_bin        = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$max_bits = strlen( $ip_bin ) * 8;
		$bits     = null === $bits ? $max_bits : (int) $bits;
		$bytes    = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		$remainder = $bits % 8;
		if ( 0 === $remainder ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $remainder ) ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	/** Accept a single IP or an explicit decimal CIDR prefix; never widen malformed input. */
	public static function valid_range( string $range ): bool {
		[ $subnet, $bits ] = array_pad( explode( '/', $range, 2 ), 2, null );
		if ( '' === self::valid_ip( $subnet ) ) {
			return false;
		}
		$max_bits = false !== filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
		return null === $bits || ( 1 === preg_match( '/^(?:0|[1-9][0-9]{0,2})$/D', $bits ) && (int) $bits <= $max_bits );
	}

	private static function valid_ip( string $ip ): string {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
