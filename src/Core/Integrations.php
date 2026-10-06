<?php
/**
 * Plugins SafeHouse is built to run alongside, shown in the Integrations card on the settings page.
 * dev/cve-watch.php reads PLUGINS as well: every plugin listed here is watched for new vulnerabilities.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Integrations {

	/** Plugin folder (the wordpress.org slug where there is one) => group. */
	public const PLUGINS = [
		'wordfence'                   => 'wordfence',
		'woocommerce'                 => 'woocommerce',
		'platnosci-online-blue-media' => 'payments',
		'pay-wp'                      => 'payments',
		'przelewy24'                  => 'payments',
		'woo-payu-payment-gateway'    => 'payments',
		'imoje'                       => 'payments',
		'pay-by-paynow-pl'            => 'payments',
		'woocommerce-gateway-stripe'  => 'payments',
		'woo-stripe-payment'          => 'payments',
		'woocommerce-paypal-payments' => 'payments',
		'woocommerce-payments'        => 'payments',
		'redis-cache'                 => 'redis',
		'elementor'                   => 'elementor',
		'elementor-pro'               => 'elementor',
	];

	/**
	 * Installed integrations by group, in PLUGINS order.
	 *
	 * @return array<string, list<array{name: string, active: bool}>>
	 */
	public static function detected(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = array_flip( Compat::active_plugins() );
		$found  = [];
		foreach ( get_plugins() as $file => $data ) {
			$folder = str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
			if ( isset( self::PLUGINS[ $folder ] ) ) {
				$found[ $folder ] = [
					'name'   => (string) $data['Name'],
					'active' => isset( $active[ $file ] ),
				];
			}
		}
		$groups = [];
		foreach ( self::PLUGINS as $folder => $group ) {
			if ( isset( $found[ $folder ] ) ) {
				$groups[ $group ][] = $found[ $folder ];
			}
		}
		return $groups;
	}

	/**
	 * Persistent object cache in use: "redis" with its connection state when the drop-in is Redis
	 * Object Cache's, "other" for any other drop-in, "none" without one.
	 *
	 * @return array{type: string, connected: bool|null}
	 */
	public static function object_cache(): array {
		if ( ! wp_using_ext_object_cache() ) {
			return [
				'type'      => 'none',
				'connected' => null,
			];
		}
		global $wp_object_cache;
		if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'redis_status' ) ) {
			return [
				'type'      => 'redis',
				'connected' => (bool) $wp_object_cache->redis_status(),
			];
		}
		return [
			'type'      => 'other',
			'connected' => null,
		];
	}
}
