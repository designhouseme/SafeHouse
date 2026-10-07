<?php
/** Real WooCommerce integration on a disposable local WordPress. No external payments or mail. */
use SafeHouse\Core\Turnstile;
use SafeHouse\Modules\Bots;
use SafeHouse\Modules\Omnibus;
use SafeHouse\Plugin;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Requires a disposable local WordPress with WooCommerce.' );
}
global $wpdb;
$backup = get_option( 'shouse_settings', [] );
$settings = $backup;
$settings['bots'] = [ 'honeypot' => false, 'turnstile_login' => true, 'turnstile_checkout' => true, 'when_unavailable' => 'block' ];
update_option( 'shouse_settings', $settings );
defined( 'SHOUSE_TURNSTILE_SITE_KEY' ) || define( 'SHOUSE_TURNSTILE_SITE_KEY', 'local-woo-fixture-site' );
defined( 'SHOUSE_TURNSTILE_SECRET_KEY' ) || define( 'SHOUSE_TURNSTILE_SECRET_KEY', 'local-woo-fixture-secret' );
$bots = new Bots();
$bots->boot();
$messages = [];
$failures = [];
$assert = static function ( bool $passed, string $name, mixed $detail = null ) use ( &$messages, &$failures ): void {
	$messages[] = ( $passed ? 'ok   ' : 'FAIL ' ) . $name . ( ! $passed ? ' ' . wp_json_encode( $detail ) : '' );
	if ( ! $passed ) { $failures[] = $name; }
};
$calls = 0;
$seen = [];
$http = static function ( $pre, $args, $url ) use ( &$calls, &$seen ) {
	if ( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' !== $url ) { return new WP_Error( 'fixture_network_disabled', 'External requests are disabled.' ); }
	++$calls;
	$token = $args['body']['response'];
	$success = ! isset( $seen[ $token ] ) && ! str_starts_with( $token, 'invalid' );
	$seen[ $token ] = true;
	return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( [ 'success' => $success, 'action' => 'shouse_checkout', 'hostname' => wp_parse_url( home_url(), PHP_URL_HOST ), 'error-codes' => $success ? [] : [ 'timeout-or-duplicate' ] ] ) ];
};
$mail = static fn() => true;
add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', $mail, PHP_INT_MAX );
$user_id = wp_create_user( 'wooreg' . wp_rand( 100000, 999999 ), 'Local-Woo-only-98!', 'wooreg' . wp_rand( 100000, 999999 ) . '@example.test' );
if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
$old_user = get_current_user_id();
wp_set_current_user( $user_id );
$products = [];
$orders = [];
$processed = [];
$order_probe = static function ( $order ) use ( &$processed, &$orders ): void { $processed[] = $order->get_id(); $orders[] = $order->get_id(); };
add_action( 'woocommerce_store_api_checkout_order_processed', $order_probe );
try {
	wc_load_cart();
	WC()->cart->empty_cart();
	$free = new WC_Product_Simple();
	$free->set_name( 'Local free checkout fixture' );
	$free->set_status( 'publish' );
	$free->set_virtual( true );
	$free->set_regular_price( '0' );
	$products[] = $free->save();
	$paid = new WC_Product_Simple();
	$paid->set_name( 'Local order-pay fixture' );
	$paid->set_status( 'publish' );
	$paid->set_virtual( true );
	$paid->set_regular_price( '10' );
	$products[] = $paid->save();
	$billing = [ 'first_name' => 'Local', 'last_name' => 'Fixture', 'address_1' => 'Test 1', 'address_2' => '', 'city' => 'Warsaw', 'state' => '', 'postcode' => '00-001', 'country' => 'PL', 'email' => get_userdata( $user_id )->user_email, 'phone' => '500000000' ];
	$order = wc_create_order( [ 'customer_id' => $user_id ] );
	$order->add_product( $paid, 1 );
	$order->set_address( $billing, 'billing' );
	$order->set_status( 'pending' );
	$order->calculate_totals();
	$order->save();
	$orders[] = $order->get_id();
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateways['cod']->enabled = 'yes';
	$gateways['cod']->settings['enabled'] = 'yes';
	$gateways['cod']->settings['enable_for_virtual'] = 'yes';
	WC()->cart->add_to_cart( $free->get_id(), 1 );
	WC()->cart->calculate_totals();
	$nonce = wp_create_nonce( 'wc_store_api' );
	$headers = [ 'Nonce' => $nonce, Turnstile::HEADER => 'real-woo-batch-shared' ];
	$checkout_body = [ 'billing_address' => $billing, 'shipping_address' => array_diff_key( $billing, [ 'email' => true, 'phone' => true ] ), 'payment_method' => '', 'create_account' => false ];
	$pay_body = [ 'billing_address' => $billing, 'payment_method' => 'cod', 'key' => $order->get_order_key(), 'billing_email' => $billing['email'] ];
	$batch = new WP_REST_Request( 'POST', '/wc/store/v1/batch' );
	$batch->set_body_params( [ 'requests' => [
		[ 'method' => 'POST', 'path' => '/wc/store/v1/checkout', 'headers' => $headers, 'body' => $checkout_body ],
		[ 'method' => 'POST', 'path' => '/wc/store/v1/checkout', 'headers' => $headers, 'body' => $checkout_body ],
		[ 'method' => 'POST', 'path' => '/wc/store/v1/checkout/' . $order->get_id(), 'headers' => $headers, 'body' => $pay_body ],
	] ] );
	$result = rest_get_server()->dispatch( $batch )->get_data();
	$statuses = array_column( $result['responses'] ?? [], 'status' );
	$assert( [ 200, 403, 403 ] === $statuses, 'real Store API batch permits one checkout and rejects token replay on checkout and order-pay', $result );
	$assert( 3 === $calls && 1 === count( $processed ), 'each real batch operation verifies independently; only one order reaches processing', [ $calls, $processed ] );
	$assert( 'pending' === wc_get_order( $order->get_id() )->get_status(), 'replayed token does not change the existing order' );
	$pay = new WP_REST_Request( 'POST', '/wc/store/v1/checkout/' . $order->get_id() );
	$pay->set_header( 'Nonce', $nonce );
	$pay->set_header( Turnstile::HEADER, 'real-woo-order-pay-fresh' );
	$pay->set_body_params( $pay_body );
	$paid_response = rest_get_server()->dispatch( $pay );
	$assert( 200 === $paid_response->get_status() && 'success' === ( $paid_response->get_data()['payment_result']['payment_status'] ?? '' ), 'fresh token allows real order-pay with the local cash-on-delivery gateway', $paid_response->get_data() );
	$assert( 4 === $calls && 2 === count( $processed ), 'order-pay performs its own successful verification' );
	$_POST[ Turnstile::FIELD ] = 'real-woo-classic-single';
	$errors = new WP_Error();
	$before = $calls;
	do_action( 'woocommerce_after_checkout_validation', [], $errors );
	do_action( 'woocommerce_after_checkout_validation', [], $errors );
	$assert( ! $errors->has_errors() && $calls === $before + 1, 'classic Woo checkout validation hooks share only their own operation result' );
	$again = new WP_Error();
	do_action( 'woocommerce_after_checkout_validation', [], $again );
	$assert( in_array( 'shouse_bots', $again->get_error_codes(), true ), 'another classic checkout cannot consume that result' );
} finally {
	if ( WC()->cart ) { WC()->cart->empty_cart(); }
	foreach ( array_unique( $orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	foreach ( $products as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	wp_set_current_user( $old_user );
	wp_delete_user( $user_id );
	$current = get_option( 'shouse_settings', [] );
	if ( array_key_exists( 'bots', $backup ) ) { $current['bots'] = $backup['bots']; } else { unset( $current['bots'] ); }
	update_option( 'shouse_settings', $current );
	remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $mail, PHP_INT_MAX );
	remove_action( 'woocommerce_store_api_checkout_order_processed', $order_probe );
}
echo 'WooCommerce ' . WC_VERSION . '; WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . "\n" . implode( "\n", $messages ) . "\n" . count( $messages ) . ' checks, ' . count( $failures ) . " failures.\n";
if ( $failures ) { throw new RuntimeException( implode( '; ', $failures ) ); }
