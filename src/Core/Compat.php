<?php
/**
 * Detection of plugins and constants that already cover a SafeHouse feature.
 *
 * Wordfence option keys were checked against Wordfence 8.1.4 and 9.0.2 (lib/wfConfig.php).
 * To run SafeHouse features anyway, define( 'SHOUSE_IGNORE_OVERLAPS', true ).
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Compat {

	public static function ignore_overlaps(): bool {
		return defined( 'SHOUSE_IGNORE_OVERLAPS' ) && SHOUSE_IGNORE_OVERLAPS;
	}

	public static function wordfence_active(): bool {
		return defined( 'WORDFENCE_VERSION' ) && class_exists( 'wfConfig' );
	}

	/** True when Wordfence is active and the given wfConfig option is switched on. */
	public static function wordfence_on( string $key ): bool {
		if ( ! self::wordfence_active() ) {
			return false;
		}
		return (bool) \wfConfig::get( $key, false );
	}

	/**
	 * Wordfence Login Security reCAPTCHA is on (with keys) for login and registration, and also
	 * covers the WooCommerce forms when WooCommerce is active. Checked against Wordfence 9.0.2.
	 */
	public static function wordfence_login_captcha(): bool {
		if ( ! class_exists( '\WordfenceLS\Controller_CAPTCHA' ) || ! class_exists( '\WordfenceLS\Controller_Settings' ) ) {
			return false;
		}
		if ( ! \WordfenceLS\Controller_CAPTCHA::shared()->enabled() ) {
			return false;
		}
		return ! self::woocommerce_active() || \WordfenceLS\Controller_Settings::shared()->get_bool( 'enable-woocommerce-integration' );
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** The LiteSpeed Cache plugin, which manages the LiteSpeed server cache itself. */
	public static function litespeed_cache_active(): bool {
		return defined( 'LSCWP_V' );
	}

	/** True when a constant is defined and truthy. */
	public static function constant_on( string $name ): bool {
		return defined( $name ) && constant( $name );
	}

	/**
	 * Active plugin basenames (e.g. "wordfence/wordfence.php") without loading admin includes.
	 *
	 * @return string[]
	 */
	public static function active_plugins(): array {
		$active = get_option( 'active_plugins', [] );
		return is_array( $active ) ? array_values( array_map( 'strval', $active ) ) : [];
	}
}
