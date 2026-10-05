<?php
/**
 * Detection of plugins and constants that already cover a WPHouse feature.
 *
 * Wordfence option keys were checked against Wordfence 8.1.4 and 9.0.2 (lib/wfConfig.php).
 * To run WPHouse features anyway, define( 'WPHOUSE_IGNORE_OVERLAPS', true ).
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Compat {

	public static function ignore_overlaps(): bool {
		return defined( 'WPHOUSE_IGNORE_OVERLAPS' ) && WPHOUSE_IGNORE_OVERLAPS;
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

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
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
