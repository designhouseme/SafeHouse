<?php
/**
 * Plugin Name:          WPHouse
 * Plugin URI:           https://designhouse.me/
 * Description:          Lean, audited replacements for small utility plugins: hardening, install lockdown, change alerts, plugin health and everyday tweaks. Runs alongside Wordfence and WooCommerce.
 * Version:              0.1.0
 * Requires at least:    6.6
 * Requires PHP:         8.1
 * Author:               Design House
 * Author URI:           https://designhouse.me/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wphouse
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.9
 *
 * @package WPHouse
 */

defined( 'ABSPATH' ) || exit;

const WPHOUSE_VERSION = '0.1.0';
const WPHOUSE_FILE    = __FILE__;

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) { // @phpstan-ignore if.alwaysFalse (guards installs on older PHP)
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WPHouse requires PHP 8.1 or newer. The plugin is inactive.', 'wphouse' ) . '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'WPHouse\\' ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 8 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, [ WPHouse\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ WPHouse\Plugin::class, 'deactivate' ] );

// WPHouse never reads or writes orders, so it is compatible with HPOS and the block checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', [ WPHouse\Plugin::class, 'boot' ] );
