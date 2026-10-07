<?php
/**
 * Authentication regressions. Run only on a disposable local WordPress:
 * wp eval-file /path/to/dev/security-auth-test.php
 * Cloudflare, mail and second-factor providers are replaced by local fixtures.
 */

use SafeHouse\Core\Net;
use SafeHouse\Core\Turnstile;
use SafeHouse\Modules\Bots;
use SafeHouse\Modules\LoginLimits;
use SafeHouse\Plugin;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable WP_ENVIRONMENT_TYPE=local WordPress.' );
}

global $wpdb, $pagenow;
$backup = get_option( 'shouse_settings', [] );
$settings = $backup;
$settings['login_limits'] = [ 'ip_attempts' => 3, 'ip_window' => 15, 'ip_lockout' => 1, 'account_attempts' => 3, 'allowlist' => '', 'notify' => false ];
$settings['bots'] = [ 'honeypot' => false, 'turnstile_login' => true, 'turnstile_checkout' => true, 'when_unavailable' => 'block' ];
update_option( 'shouse_settings', $settings );
$limits = Plugin::instance()->module( 'login_limits' );
if ( ! Plugin::instance()->is_running( 'login_limits' ) ) {
	$limits->boot();
}
$old_server = $_SERVER;
$old_cookies = $_COOKIE;
$old_post = $_POST;
$old_pagenow = $pagenow;
$old_user = get_current_user_id();
$login = 'authreg' . wp_rand( 100000, 999999 );
$password = 'Local-regression-only-92!';
$id = wp_create_user( $login, $password, $login . '@example.test' );
if ( is_wp_error( $id ) ) {
	throw new RuntimeException( $id->get_error_message() );
}
$user = get_user_by( 'id', $id );
$failures = [];
$checks = 0;
$messages = [];
$assert = static function ( bool $passed, string $label ) use ( &$failures, &$checks, &$messages ): void {
	++$checks;
	if ( ! $passed ) {
		$failures[] = $label;
	}
	$messages[] = ( $passed ? 'ok   ' : 'FAIL ' ) . $label;
};
$code = static fn( mixed $result, string $expected ): bool => $result instanceof WP_Error && in_array( $expected, $result->get_error_codes(), true );
$hashes = 0;
$hash_probe = static function ( bool $valid ) use ( &$hashes ): bool { ++$hashes; return $valid; };
add_filter( 'check_password', $hash_probe );
$mail_block = static fn() => true;
add_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX );
$network_block = static fn() => new WP_Error( 'test_network_disabled', 'No external network in regression tests.' );
add_filter( 'pre_http_request', $network_block, PHP_INT_MAX );
$http_fixture = null;

try {
	LoginLimits::unlock();
	$_COOKIE = [];
	$_SERVER['REMOTE_ADDR'] = '203.0.113.201';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
	$assert( wp_authenticate( $login, $password ) instanceof WP_User, 'ordinary login succeeds' );
	$assert( wp_authenticate( $user->user_email, $password ) instanceof WP_User, 'email login succeeds' );
	foreach ( [ $login, $user->user_email, $login . "\u{200B}" ] as $n => $identity ) {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 210 + $n );
		wp_authenticate( $identity, 'incorrect' );
	}
	$_SERVER['REMOTE_ADDR'] = '203.0.113.220';
	foreach ( [ $login, $user->user_email, $login . "\u{200B}", $login . "\u{200D}", $login . "\u{FEFF}" ] as $n => $identity ) {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 10 + $n );
		$hashes = 0;
		$assert( $code( wp_authenticate( $identity, $password ), LoginLimits::PAUSED_CODE ), 'canonical pause: ' . bin2hex( $identity ) );
		$assert( 0 === $hashes, 'paused identity does no password hashing' );
	}
	$assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LoginLimits::table() . " WHERE kind='user'" ), 'login/email/Unicode share exactly one account counter' );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
	$expiry = time() + 3600;
	foreach ( [ 'shouse_device_', 'wphouse_device_' ] as $prefix ) {
		$name = $prefix . substr( hash_hmac( 'sha256', 'name|' . strtolower( $login ), wp_salt( 'auth' ) ), 0, 20 );
		$_COOKIE = [ $name => $expiry . '.' . hash_hmac( 'sha256', $name . '|' . $expiry, wp_salt( 'auth' ) ) ];
		$assert( wp_authenticate( $user->user_email, $password ) instanceof WP_User, 'legacy trusted device works across login/email: ' . $prefix );
	}
	$name_method = new ReflectionMethod( LoginLimits::class, 'cookie_name' );
	$name_method->setAccessible( true );
	$name = $name_method->invoke( null, $user );
	$_COOKIE = [ $name => $expiry . '.' . hash_hmac( 'sha256', $name . '|' . $expiry, wp_salt( 'auth' ) ) ];
	$assert( wp_authenticate( $login . "\u{200B}", $password ) instanceof WP_User, 'canonical trusted device works with an equivalent Unicode login' );
	$_COOKIE = [];
	LoginLimits::unlock( $user->user_email );
	$assert( wp_authenticate( $login, $password ) instanceof WP_User, 'unlock by email clears canonical pause' );
	$legacy_key = 'u:' . substr( hash_hmac( 'sha256', 'account|' . strtolower( $login ), wp_salt( 'auth' ) ), 0, 40 );
	$wpdb->insert( LoginLimits::table(), [ 'kind' => 'user', 'subject' => $legacy_key, 'fails' => 0, 'window_start' => gmdate( 'Y-m-d H:i:s' ), 'lockouts' => 1, 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
	$assert( $code( wp_authenticate( $user->user_email, $password ), LoginLimits::PAUSED_CODE ), 'active legacy pause survives identity migration and applies to email' );
	LoginLimits::unlock( $user->user_email );
	$assert( wp_authenticate( $login, $password ) instanceof WP_User, 'unlock by email also clears active legacy pause' );

	LoginLimits::unlock();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.230';
	for ( $i = 0; $i < 3; ++$i ) { wp_authenticate( $login, 'incorrect' ); }
	$hashes = 0;
	for ( $i = 0; $i < 5; ++$i ) { $result = wp_authenticate( $login, $password ); }
	$assert( $code( $result, LoginLimits::ERROR_CODE ) && 0 === $hashes, 'five locked requests are refused with zero password hashes' );
	LoginLimits::unlock();
	add_filter( 'application_password_is_api_request', '__return_true' );
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
	$app = WP_Application_Passwords::create_new_application_password( $id, [ 'name' => 'Local regression' ] );
	$assert( ! is_wp_error( $app ), 'application password fixture created' );
	$assert( wp_authenticate_application_password( null, $login, $app[0] ) instanceof WP_User, 'valid application password works before lock' );
	for ( $i = 0; $i < 3; ++$i ) { wp_authenticate_application_password( null, $login, 'incorrect' ); }
	$query = $wpdb->prepare( 'SELECT fails, lockouts, locked_until FROM %i WHERE kind=%s AND subject=%s', LoginLimits::table(), 'ip', $_SERVER['REMOTE_ADDR'] );
	$before = $wpdb->get_row( $query, ARRAY_A );
	$reads = 0;
	$metadata_probe = static function ( $value, $object_id, $key ) use ( &$reads ) { if ( '_application_passwords' === $key ) { ++$reads; } return $value; };
	add_filter( 'get_user_metadata', $metadata_probe, 10, 3 );
	for ( $i = 0; $i < 9; ++$i ) { $result = wp_authenticate_application_password( null, $login, 'incorrect' ); }
	$assert( $before === $wpdb->get_row( $query, ARRAY_A ), 'invalid application passwords do not change active lockout or counters' );
	$assert( $code( $result, LoginLimits::ERROR_CODE ), 'invalid application password during lock reports locked' );
	$assert( $code( wp_authenticate_application_password( null, $login, $app[0] ), LoginLimits::ERROR_CODE ), 'valid application password cannot bypass lock' );
	$assert( 0 === $reads, 'locked application password requests never fetch hashes' );
	remove_filter( 'get_user_metadata', $metadata_probe );
	remove_filter( 'application_password_is_api_request', '__return_true' );
	remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	LoginLimits::unlock();

	foreach ( [ '203.0.113.7/abc', '203.0.113.7/', '203.0.113.7/-1', '203.0.113.7/+1', '203.0.113.7/33', '203.0.113.7/1.5', '203.0.113.7/1/2', '203.0.113.7/00', '::/129', '::/-1', '::/abc', '::/' ] as $range ) {
		$assert( ! Net::valid_range( $range ) && ! Net::in_range( str_contains( $range, ':' ) ? '2001:db8::1' : '198.51.100.22', $range ), 'invalid CIDR denied: ' . $range );
	}
	foreach ( [ [ '198.51.100.22', '203.0.113.7/0' ], [ '203.0.113.7', '203.0.113.7/32' ], [ '2001:db8::1', '::/0' ], [ '2001:db8::1', '2001:db8::/64' ], [ '2001:db8::1', '2001:db8::1/128' ] ] as [ $ip, $range ] ) {
		$assert( Net::valid_range( $range ) && Net::in_range( $ip, $range ), 'valid CIDR matches: ' . $range );
	}
	$assert( ! Net::in_range( '198.51.100.22', '203.0.113.7/32' ) && ! Net::in_range( '203.0.113.7', '::/0' ), 'CIDR mismatches and different address families rejected' );
	$GLOBALS['wp_settings_errors'] = [];
	$clean = $limits->sanitize( [ 'allowlist' => "203.0.113.7\n198.51.100.1/abc" ], [ 'allowlist' => '203.0.113.10' ] );
	$assert( '203.0.113.10' === $clean['allowlist'] && count( get_settings_errors( 'shouse_settings' ) ) > 0, 'invalid allowlist keeps previous value and reports settings error' );
	$clean = $limits->sanitize( [ 'allowlist' => str_repeat( "203.0.113.1\n", 190 ) . '0.0.0.0/32' ], [ 'allowlist' => '203.0.113.10' ] );
	$assert( '203.0.113.10' === $clean['allowlist'], 'overlength allowlist cannot truncate a CIDR prefix' );

	defined( 'SHOUSE_TURNSTILE_SITE_KEY' ) || define( 'SHOUSE_TURNSTILE_SITE_KEY', 'local-fixture-site-key' );
	defined( 'SHOUSE_TURNSTILE_SECRET_KEY' ) || define( 'SHOUSE_TURNSTILE_SECRET_KEY', 'local-fixture-secret-key' );
	$seen = [];
	$requests = 0;
	$http_fixture = static function ( $pre, $args, $url ) use ( &$seen, &$requests ) {
		if ( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' !== $url ) { return $pre; }
		++$requests;
		$token = $args['body']['response'];
		$success = ! isset( $seen[ $token ] ) && ! str_starts_with( $token, 'invalid' );
		$seen[ $token ] = true;
		$action = str_starts_with( $token, 'login' ) || str_starts_with( $token, 'invalid' ) ? 'shouse_login' : 'shouse_checkout';
		return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => wp_json_encode( [ 'success' => $success, 'action' => $action, 'hostname' => wp_parse_url( home_url(), PHP_URL_HOST ), 'error-codes' => $success ? [] : [ 'timeout-or-duplicate' ] ] ) ];
	};
	add_filter( 'pre_http_request', $http_fixture, PHP_INT_MAX, 3 );
	$bots = new Bots();
	$bots->boot();
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$request->set_header( Turnstile::HEADER, 'checkout-fixture-1' );
	$start = $requests;
	$assert( null === $bots->check_store_api( null, [], $request ) && null === $bots->check_store_api( null, [], $request ) && $requests === $start + 1, 'repeated hooks for one REST operation share verification' );
	$request2 = new WP_REST_Request( 'POST', '/wc/store/v1/checkout/123' );
	$request2->set_header( Turnstile::HEADER, 'checkout-fixture-1' );
	$assert( $code( $bots->check_store_api( null, [], $request2 ), 'shouse_bots' ) && $requests === $start + 2, 'separate order-pay subrequest cannot reuse successful token' );
	$request3 = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$request3->set_header( Turnstile::HEADER, 'checkout-fixture-1' );
	$assert( $code( $bots->check_store_api( null, [], $request3 ), 'shouse_bots' ), 'separate checkout subrequest cannot reuse successful token' );
	$server = rest_get_server();
	$callbacks = 0;
	$batch_handler = [
		'methods' => 'POST',
		'permission_callback' => '__return_true',
		'allow_batch' => [ 'v1' => true ],
		'callback' => static function () use ( &$callbacks ) { ++$callbacks; return [ 'fixture' => 'accepted' ]; },
	];
	// Real WordPress batch dispatcher; local handlers stand in for order creation/payment.
	register_rest_route( 'wc/store/v99', '/checkout', $batch_handler );
	register_rest_route( 'wc/store/v99', '/checkout/(?P<id>[0-9]+)', $batch_handler );
	$batch = new WP_REST_Request( 'POST', '/batch/v1' );
	$batch->set_body_params( [ 'requests' => [
		[ 'method' => 'POST', 'path' => '/wc/store/v99/checkout', 'headers' => [ Turnstile::HEADER => 'checkout-batch-fixture' ] ],
		[ 'method' => 'POST', 'path' => '/wc/store/v99/checkout/123', 'headers' => [ Turnstile::HEADER => 'checkout-batch-fixture' ] ],
		[ 'method' => 'POST', 'path' => '/wc/store/v99/checkout', 'headers' => [ Turnstile::HEADER => 'checkout-batch-fresh' ] ],
	] ] );
	$start = $requests;
	$batch_result = $server->dispatch( $batch )->get_data();
	$statuses = array_column( $batch_result['responses'] ?? [], 'status' );
	$assert( [ 200, 403, 200 ] === $statuses && 2 === $callbacks && $requests === $start + 3, 'real WP REST batch checks each checkout/order-pay operation separately' );
	$assert( Turnstile::PASSED === Turnstile::verify( 'checkout-unscoped', 'shouse_checkout' ) && Turnstile::FAILED === Turnstile::verify( 'checkout-unscoped', 'shouse_checkout' ), 'unscoped verification never caches a successful token' );
	$_POST[ Turnstile::FIELD ] = 'checkout-classic-fixture';
	$errors = new WP_Error();
	$start = $requests;
	$bots->check_classic_checkout( [], $errors );
	$bots->check_classic_checkout( [], $errors );
	$assert( ! $errors->has_errors() && $requests === $start + 1, 'two classic checkout hooks on one operation share verification' );
	$other_errors = new WP_Error();
	$bots->check_classic_checkout( [], $other_errors );
	$assert( $code( $other_errors, 'shouse_bots' ), 'another classic checkout operation cannot share verification' );

	$pagenow = 'wp-login.php';
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST[ Turnstile::FIELD ] = 'invalid-login';
	$hashes = 0;
	$assert( $code( wp_authenticate( $login, $password ), 'shouse_bots' ) && 0 === $hashes, 'failed CAPTCHA stops password hashing' );
	$_POST[ Turnstile::FIELD ] = 'login-single-operation';
	$start = $requests;
	$assert( wp_authenticate( $login, $password ) instanceof WP_User && $requests === $start + 1, 'early and late login checks share one verification' );
	$assert( $code( wp_authenticate( $login, $password ), 'shouse_bots' ) && $requests === $start + 2, 'another authenticate operation cannot reuse login token' );
	LoginLimits::unlock();
	$two_factor = static fn( $value ) => $value instanceof WP_User ? new WP_Error( 'fixture_second_factor', 'Second factor required.' ) : $value;
	add_filter( 'authenticate', $two_factor, 40 );
	$_POST[ Turnstile::FIELD ] = 'login-two-factor';
	$assert( $code( wp_authenticate( $login, $password ), 'fixture_second_factor' ), 'second factor authentication error remains authoritative' );
	remove_filter( 'authenticate', $two_factor, 40 );
	$two_factor_before = static fn( $value ) => new WP_Error( 'fixture_second_factor_before', 'Second factor required.' );
	add_filter( 'wp_authenticate_user', $two_factor_before, 10 );
	$_POST[ Turnstile::FIELD ] = 'login-two-factor-before';
	$hashes = 0;
	$assert( $code( wp_authenticate( $login, $password ), 'fixture_second_factor_before' ) && 0 === $hashes, 'pre-password second-factor error is preserved' );
	remove_filter( 'wp_authenticate_user', $two_factor_before, 10 );
	LoginLimits::unlock();
	$pagenow = 'index.php';
	$_POST[ Turnstile::FIELD ] = 'login-woo-fixture';
	$errors = apply_filters( 'woocommerce_process_login_errors', new WP_Error() );
	$result = $errors->has_errors() ? $errors : wp_signon( [ 'user_login' => $login, 'user_password' => $password, 'remember' => false ] );
	$assert( $result instanceof WP_User, 'Woo login error hook followed by wp_signon succeeds' );
	$_POST[ Turnstile::FIELD ] = 'invalid-woo-fixture';
	$hashes = 0;
	$errors = apply_filters( 'woocommerce_process_login_errors', new WP_Error() );
	$assert( $code( $errors, 'shouse_bots' ) && 0 === $hashes, 'Woo login error hook rejects CAPTCHA before wp_signon' );
} finally {
	LoginLimits::unlock();
	wp_delete_user( $id );
	$current = get_option( 'shouse_settings', [] );
	foreach ( [ 'login_limits', 'bots' ] as $section ) {
		if ( array_key_exists( $section, $backup ) ) { $current[ $section ] = $backup[ $section ]; } else { unset( $current[ $section ] ); }
	}
	update_option( 'shouse_settings', $current );
	$_SERVER = $old_server;
	$_COOKIE = $old_cookies;
	$_POST = $old_post;
	$pagenow = $old_pagenow;
	wp_set_current_user( $old_user );
	remove_filter( 'check_password', $hash_probe );
	remove_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $network_block, PHP_INT_MAX );
	if ( $http_fixture ) { remove_filter( 'pre_http_request', $http_fixture, PHP_INT_MAX ); }
}
echo implode( "\n", $messages ) . "\n{$checks} checks, " . count( $failures ) . " failures.\n";
if ( $failures ) { throw new RuntimeException( implode( '; ', $failures ) ); }
