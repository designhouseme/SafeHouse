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
		[ $subnet, $bits ] = array_pad( explode( '/', $range, 2 ), 2, null );
		$ip_bin            = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
		$subnet_bin        = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$max_bits = strlen( $ip_bin ) * 8;
		$bits     = null === $bits ? $max_bits : max( 0, min( $max_bits, (int) $bits ) );
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

	private static function valid_ip( string $ip ): string {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
