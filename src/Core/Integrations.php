<?php
/**
 * Plugins WPHouse is built to run alongside, shown in the Integrations card on the settings page.
 * dev/cve-watch.php reads PLUGINS as well: every plugin listed here is watched for new vulnerabilities.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

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
}
