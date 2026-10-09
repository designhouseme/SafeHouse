<?php
/**
 * Honeypot regressions on a disposable local WordPress with SafeHouse enabled.
 * Run: wp eval-file /path/to/dev/security-honeypot-test.php
 *
 * Signed local fixtures exercise the public check and real form filters. They
 * intentionally permit same-browser replay during the stateless challenge TTL.
 */

use SafeHouse\Core\Honeypot;
use SafeHouse\Modules\Bots;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() || ! class_exists( Honeypot::class ) ) {
	throw new RuntimeException( 'Requires a disposable WP_ENVIRONMENT_TYPE=local WordPress with SafeHouse.' );
}

global $pagenow, $wp_filter, $wp_actions;
$old_post = $_POST;
$old_cookies = $_COOKIE;
$old_server = $_SERVER;
$old_pagenow = $pagenow;
$old_user = get_current_user_id();
$old_comment_action = $wp_actions['pre_comment_on_post'] ?? null;
$settings_backup = get_option( 'shouse_settings', [] );
$messages = [];
$failures = [];
$assert = static function ( bool $passed, string $label ) use ( &$messages, &$failures ): void {
	$messages[] = ( $passed ? 'ok   ' : 'FAIL ' ) . $label;
	if ( ! $passed ) {
		$failures[] = $label;
	}
};
$bot_error = static fn( mixed $result ): bool => $result instanceof WP_Error && in_array( 'shouse_bots', $result->get_error_codes(), true );
$reflect = new ReflectionClass( Honeypot::class );
$key_method = $reflect->getMethod( 'key' );
$key_method->setAccessible( true );
$key = $key_method->invoke( null );
$name_method = $reflect->getMethod( 'cookie_name' );
$name_method->setAccessible( true );
$cookie_name = $name_method->invoke( null );
$lifetime = $reflect->getConstant( 'LIFETIME' );
$cookie_seconds = $reflect->getConstant( 'COOKIE_SECONDS' );
$wait_ms = $reflect->getConstant( 'WAIT_MS' );
$now_ms = static fn(): int => (int) floor( microtime( true ) * 1000 );
$make_cookie = static function ( ?int $issued = null ) use ( $key ): string {
	$payload = '1.' . ( $issued ?? time() ) . '.' . bin2hex( random_bytes( 32 ) );
	return $payload . '.' . hash_hmac( 'sha256', 'cookie|' . $payload, $key );
};
$make_challenge = static function ( string $form = 'register', int $target = 0, ?int $issued = null, ?string $cookie = null ) use ( $key, $make_cookie, $now_ms, $wait_ms ): array {
	$cookie ??= $make_cookie();
	$payload = '1.' . $form . '.' . $target . '.' . ( $issued ?? $now_ms() - $wait_ms - 1000 ) . '.' . bin2hex( random_bytes( 16 ) );
	$token = $payload . '.' . hash_hmac( 'sha256', 'challenge|' . $payload . '|' . $cookie, $key );
	$trap = 'contact_' . substr( hash_hmac( 'sha256', 'trap|' . $payload . '|' . $cookie, $key ), 0, 20 );
	return [ 'post' => [ Honeypot::FIELD => $token, $trap => '' ], 'cookie' => $cookie, 'trap' => $trap ];
};
$load = static function ( array $fixture ) use ( $cookie_name ): void {
	$_POST = $fixture['post'];
	$_COOKIE = [ $cookie_name => $fixture['cookie'] ];
};
$flip = static fn( string $value ): string => substr( $value, 0, -1 ) . ( str_ends_with( $value, 'a' ) ? 'b' : 'a' );
$network_calls = 0;
$no_network = static function () use ( &$network_calls ): WP_Error {
	++$network_calls;
	return new WP_Error( 'fixture_network_disabled', 'No external network in honeypot regressions.' );
};
add_filter( 'pre_http_request', $no_network, PHP_INT_MAX );

// Preserve unrelated plugin callbacks and restore every hook after the fixture.
$hooks = [ 'registration_errors', 'woocommerce_process_registration_errors', 'lostpassword_post', 'pre_comment_approved', 'register_form', 'woocommerce_register_form', 'lostpassword_form', 'woocommerce_lostpassword_form', 'comment_form_after_fields', 'wp_ajax_shouse_challenge', 'wp_ajax_nopriv_shouse_challenge' ];
$hook_backup = [];
foreach ( $hooks as $hook ) {
	$hook_backup[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
	foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof Bots ) {
				remove_filter( $hook, $function, $priority );
			}
		}
	}
}

try {
	wp_set_current_user( 0 );
	$settings = $settings_backup;
	$settings['bots'] = [ 'honeypot' => true, 'turnstile_login' => false, 'turnstile_register' => false, 'turnstile_lostpassword' => false, 'turnstile_comments' => false, 'turnstile_checkout' => false ];
	update_option( 'shouse_settings', $settings );
	$bots = new Bots();
	$bots->boot();

	foreach ( [ [ 'register', 0 ], [ 'lostpassword', 0 ], [ 'comments', 123 ] ] as [ $form, $target ] ) {
		$fixture = $make_challenge( $form, $target );
		$load( $fixture );
		$assert( '' === Honeypot::check( $form, $target ), 'valid signed ' . $form . ' challenge passes' );
	}
	$valid = $make_challenge();
	$load( $valid );
	$assert( '' === Honeypot::check( 'register' ) && '' === Honeypot::check( 'register' ), 'same-browser replay inside TTL is intentionally accepted (stateless, not one-time)' );
	$second = $make_challenge( 'register', 0, null, $valid['cookie'] );
	$assert( $valid['trap'] !== $second['trap'], 'two challenges in one browser use different trap names' );
	$load( $second );
	$assert( '' === Honeypot::check( 'register' ), 'a second form in the same browser also passes' );
	unset( $_POST[ $second['trap'] ] );
	$_POST[ $valid['trap'] ] = '';
	$assert( 'trap' === Honeypot::check( 'register' ), 'previous challenge trap name cannot replace the current trap' );

	$load( $valid );
	unset( $_POST[ Honeypot::FIELD ] );
	$assert( 'missing' === Honeypot::check( 'register' ), 'missing challenge is rejected' );
	foreach ( [ 'empty' => '', 'array' => [ $valid['post'][ Honeypot::FIELD ] ], 'nested array' => [ [ 'x' ] ], 'null' => null, 'integer' => 123, 'boolean' => false, 'oversize' => str_repeat( 'x', 10000 ) ] as $label => $value ) {
		$load( $valid );
		$_POST[ Honeypot::FIELD ] = $value;
		$assert( '' !== Honeypot::check( 'register' ), $label . ' challenge is rejected without coercion' );
	}
	foreach ( [ 'empty' => '', 'array' => [ $valid['cookie'] ], 'null' => null, 'integer' => 123, 'oversize' => str_repeat( 'x', 10000 ), 'signature tamper' => $flip( $valid['cookie'] ) ] as $label => $value ) {
		$load( $valid );
		$_COOKIE[ $cookie_name ] = $value;
		$assert( 'browser' === Honeypot::check( 'register' ), $label . ' browser cookie is rejected' );
	}
	$load( $valid );
	$_COOKIE = [];
	$assert( 'browser' === Honeypot::check( 'register' ), 'missing browser cookie is rejected' );
	$load( $valid );
	$_COOKIE[ $cookie_name ] = $make_cookie();
	$assert( 'tampered' === Honeypot::check( 'register' ), 'valid challenge copied into another valid browser cookie is rejected' );

	foreach ( [ 'filled' => 'bot', 'zero string' => '0', 'whitespace' => ' ', 'array' => [ '' ], 'nested array' => [ [ '' ] ], 'null' => null, 'boolean' => false, 'oversize' => str_repeat( 'x', 10000 ) ] as $label => $value ) {
		$load( $valid );
		$_POST[ $valid['trap'] ] = $value;
		$assert( 'trap' === Honeypot::check( 'register' ), $label . ' trap is rejected' );
	}
	$load( $valid );
	unset( $_POST[ $valid['trap'] ] );
	$assert( 'trap' === Honeypot::check( 'register' ), 'omitting the rotating trap is rejected' );
	$load( $valid );
	$_POST = [ 'shouse_url' => '', 'shouse_proof' => substr( wp_hash( 'shouse-bots-proof' ), 0, 20 ) ];
	$assert( 'missing' === Honeypot::check( 'register' ), 'legacy static site-wide proof does not bypass the browser challenge' );

	$load( $valid );
	$_POST[ Honeypot::FIELD ] = $flip( $_POST[ Honeypot::FIELD ] );
	$assert( 'tampered' === Honeypot::check( 'register' ), 'changed challenge signature is rejected' );
	$load( $valid );
	$_POST[ Honeypot::FIELD ] = str_replace( '.register.', '.lostpassword.', $_POST[ Honeypot::FIELD ] );
	$assert( 'tampered' === Honeypot::check( 'lostpassword' ), 'changing the signed form name cannot transplant a challenge' );
	$load( $valid );
	$assert( 'context' === Honeypot::check( 'lostpassword' ), 'unmodified registration proof is rejected by password-reset context' );
	$comment = $make_challenge( 'comments', 123 );
	$load( $comment );
	$assert( 'context' === Honeypot::check( 'comments', 124 ), 'comment proof is bound to its target post' );
	$_POST[ Honeypot::FIELD ] = str_replace( '.comments.123.', '.comments.124.', $_POST[ Honeypot::FIELD ] );
	$assert( 'tampered' === Honeypot::check( 'comments', 124 ), 'changing the signed comment target is rejected' );
	foreach ( [ '.comments.0123.', '.comments.+123.', '.comments.9223372036854775808.' ] as $bad_target ) {
		$load( $comment );
		$_POST[ Honeypot::FIELD ] = str_replace( '.comments.123.', $bad_target, $_POST[ Honeypot::FIELD ] );
		$assert( '' !== Honeypot::check( 'comments', 123 ), 'noncanonical or overflowing target is rejected: ' . $bad_target );
	}
	foreach ( [ [ 'login', 0 ], [ 'checkout', 0 ], [ 'register', 123 ], [ 'comments', 0 ], [ 'comments', -1 ] ] as [ $form, $target ] ) {
		$load( $valid );
		$assert( 'context' === Honeypot::check( $form, $target ), 'invalid protected context is rejected: ' . $form . '/' . $target );
	}

	$load( $make_challenge( 'register', 0, $now_ms() - ( $lifetime + 2 ) * 1000 ) );
	$assert( 'expired' === Honeypot::check( 'register' ), 'correctly signed expired challenge is rejected' );
	$load( $make_challenge( 'register', 0, $now_ms() + 60000 ) );
	$assert( 'expired' === Honeypot::check( 'register' ), 'correctly signed future challenge is rejected' );
	$load( $make_challenge( 'register', 0, $now_ms() ) );
	$assert( 'too_fast' === Honeypot::check( 'register' ), 'new challenge cannot be submitted before the millisecond minimum age' );
	$load( $make_challenge( 'register', 0, $now_ms() - $wait_ms - 100 ) );
	$assert( '' === Honeypot::check( 'register' ), 'challenge just beyond the minimum age passes' );
	$load( $make_challenge( 'register', 0, time() - 2 ) );
	$assert( '' !== Honeypot::check( 'register' ), 'seconds timestamp cannot substitute for milliseconds' );
	$load( $make_challenge( 'register', 0, null, $make_cookie( time() - $cookie_seconds - 2 ) ) );
	$assert( 'browser' === Honeypot::check( 'register' ), 'fresh challenge bound to expired browser cookie is rejected' );
	$load( $make_challenge( 'register', 0, null, $make_cookie( time() + 60 ) ) );
	$assert( 'browser' === Honeypot::check( 'register' ), 'fresh challenge bound to future browser cookie is rejected' );

	// Exercise the filters used by WordPress/WooCommerce public form handlers.
	$pagenow = 'wp-login.php';
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = [];
	$assert( $bot_error( apply_filters( 'registration_errors', new WP_Error(), '', '' ) ), 'WordPress public registration rejects a bare POST' );
	$load( $make_challenge() );
	$assert( ! $bot_error( apply_filters( 'registration_errors', new WP_Error(), '', '' ) ), 'WordPress public registration accepts a valid challenge' );
	$pagenow = 'users.php';
	$_POST = [];
	$assert( ! $bot_error( apply_filters( 'registration_errors', new WP_Error(), '', '' ) ), 'internal WordPress user creation is not treated as a public form' );
	$pagenow = 'wp-login.php';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$assert( ! $bot_error( apply_filters( 'registration_errors', new WP_Error(), '', '' ) ), 'registration page rendering is not treated as a submission' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$pagenow = 'index.php';
	$assert( $bot_error( apply_filters( 'woocommerce_process_registration_errors', new WP_Error(), '', '', '' ) ), 'WooCommerce registration rejects missing challenge without relying on wp-login routing' );
	$load( $make_challenge() );
	$assert( ! $bot_error( apply_filters( 'woocommerce_process_registration_errors', new WP_Error(), '', '', '' ) ), 'WooCommerce registration accepts a valid registration challenge' );
	$load( $make_challenge( 'lostpassword' ) );
	$assert( $bot_error( apply_filters( 'woocommerce_process_registration_errors', new WP_Error(), '', '', '' ) ), 'WooCommerce registration refuses a password-reset challenge' );

	$pagenow = 'wp-login.php';
	$_POST = [];
	$errors = new WP_Error();
	do_action( 'lostpassword_post', $errors, false );
	$assert( $bot_error( $errors ), 'WordPress public password reset rejects missing challenge' );
	$load( $make_challenge( 'lostpassword' ) );
	$errors = new WP_Error();
	do_action( 'lostpassword_post', $errors, false );
	$assert( ! $bot_error( $errors ), 'WordPress public password reset accepts its own challenge' );
	$pagenow = 'users.php';
	$_POST = [];
	$errors = new WP_Error();
	do_action( 'lostpassword_post', $errors, false );
	$assert( ! $bot_error( $errors ), 'internal password reset is not blocked by the public-form check' );
	$pagenow = 'index.php';
	$_POST = [ 'wc_reset_password' => 'true' ];
	$errors = new WP_Error();
	do_action( 'lostpassword_post', $errors, false );
	$assert( $bot_error( $errors ), 'WooCommerce public password reset rejects missing challenge' );
	$load( $make_challenge( 'lostpassword' ) );
	$_POST['wc_reset_password'] = 'true';
	$errors = new WP_Error();
	do_action( 'lostpassword_post', $errors, false );
	$assert( ! $bot_error( $errors ), 'WooCommerce public password reset accepts a valid challenge' );

	unset( $wp_actions['pre_comment_on_post'] );
	$_POST = [];
	$assert( ! $bot_error( apply_filters( 'pre_comment_approved', 1, [] ) ), 'programmatic comment insertion outside the public handler is unaffected' );
	// Core sets this action before pre_comment_approved in the public handler.
	$wp_actions['pre_comment_on_post'] = 1;
	$_POST = [ 'comment_post_ID' => '123' ];
	$assert( $bot_error( apply_filters( 'pre_comment_approved', 1, [] ) ), 'public guest comment rejects a missing challenge' );
	$load( $make_challenge( 'comments', 123 ) );
	$_POST['comment_post_ID'] = '123';
	$assert( ! $bot_error( apply_filters( 'pre_comment_approved', 1, [] ) ), 'public guest comment accepts the challenge for its actual target' );
	$_POST['comment_post_ID'] = '124';
	$assert( $bot_error( apply_filters( 'pre_comment_approved', 1, [] ) ), 'comment handler refuses a valid challenge for another post' );
	$_POST['comment_post_ID'] = [ '123' ];
	$assert( $bot_error( apply_filters( 'pre_comment_approved', 1, [] ) ), 'array comment target is rejected by handler context' );
	$existing_error = new WP_Error( 'fixture_comment_error', 'Existing rejection.' );
	$assert( $existing_error === $bots->check_comment( $existing_error ), 'existing comment rejection is preserved' );

	$markup = Honeypot::markup( 'comments', 123 );
	$assert( str_contains( $markup, 'data-form="comments"' ) && str_contains( $markup, 'data-target="123"' ) && str_contains( $markup, 'name="' . Honeypot::FIELD . '" value=""' ), 'cacheable markup carries context and no live challenge' );
	$assert( '' === Honeypot::markup( 'login' ) && str_contains( Honeypot::markup( 'comments', 0 ), 'data-target="0"' ), 'unsupported forms are omitted; explicit comment forms get a placeholder for their own post ID' );
	$assert( 0 === $network_calls, 'local honeypot verification makes no external requests' );
} finally {
	$current = get_option( 'shouse_settings', [] );
	if ( array_key_exists( 'bots', $settings_backup ) ) {
		$current['bots'] = $settings_backup['bots'];
	} else {
		unset( $current['bots'] );
	}
	update_option( 'shouse_settings', $current );
	foreach ( $hook_backup as $hook => $value ) {
		if ( null === $value ) {
			unset( $wp_filter[ $hook ] );
		} else {
			$wp_filter[ $hook ] = $value;
		}
	}
	if ( null === $old_comment_action ) {
		unset( $wp_actions['pre_comment_on_post'] );
	} else {
		$wp_actions['pre_comment_on_post'] = $old_comment_action;
	}
	wp_set_current_user( $old_user );
	$_POST = $old_post;
	$_COOKIE = $old_cookies;
	$_SERVER = $old_server;
	$pagenow = $old_pagenow;
	remove_filter( 'pre_http_request', $no_network, PHP_INT_MAX );
}

echo 'WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . "\n" . implode( "\n", $messages ) . "\n" . count( $messages ) . ' checks, ' . count( $failures ) . " failures.\n";
if ( $failures ) {
	throw new RuntimeException( implode( '; ', $failures ) );
}
