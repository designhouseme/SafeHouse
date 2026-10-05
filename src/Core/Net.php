<?php
/**
 * Client IP detection that cannot be spoofed with request headers.
 *
 * REMOTE_ADDR is used unless the request comes from a proxy listed in wp-config:
 *   define( 'WPHOUSE_TRUSTED_PROXIES', [ '10.0.0.0/8', '173.245.48.0/20' ] );
 *   define( 'WPHOUSE_PROXY_HEADER', 'HTTP_CF_CONNECTING_IP' ); // default HTTP_X_FORWARDED_FOR
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Net {

	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( '' === $remote || ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}

		$header = defined( 'WPHOUSE_PROXY_HEADER' ) ? (string) WPHOUSE_PROXY_HEADER : 'HTTP_X_FORWARDED_FOR';
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

	private static function is_trusted_proxy( string $ip ): bool {
		if ( ! defined( 'WPHOUSE_TRUSTED_PROXIES' ) || ! is_array( WPHOUSE_TRUSTED_PROXIES ) ) {
			return false;
		}
		foreach ( WPHOUSE_TRUSTED_PROXIES as $range ) {
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
