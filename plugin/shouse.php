<?php
/**
 * Plugin Name:          SafeHouse
 * Plugin URI:           https://designhouse.me/
 * Description:          One plugin instead of a dozen small ones: hardening, install lockdown, change and vulnerability alerts, plugin health and everyday tweaks. Works alongside Wordfence, WooCommerce and the usual payment gateways.
 * Version:              0.1.0
 * Requires at least:    6.6
 * Requires PHP:         8.1
 * Tested up to:         7.1
 * Author:               Design House
 * Author URI:           https://designhouse.me/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          shouse
 * Domain Path:          /languages
 * Update URI:           https://updates.designhouse.me/shouse/
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 *
 * @package SafeHouse
 */

defined( 'ABSPATH' ) || exit;

const SHOUSE_VERSION = '0.1.0';
const SHOUSE_FILE    = __FILE__;

require __DIR__ . '/src/legacy.php'; // Old WPHOUSE_* constants from wp-config.php keep working.

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) { // @phpstan-ignore if.alwaysFalse (guards installs on older PHP)
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'SafeHouse requires PHP 8.1 or newer. The plugin is inactive.', 'shouse' ) . '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SafeHouse\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, [ SafeHouse\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ SafeHouse\Plugin::class, 'deactivate' ] );

// SafeHouse never reads or writes orders, so it is compatible with HPOS and the block checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', [ SafeHouse\Plugin::class, 'boot' ] );
