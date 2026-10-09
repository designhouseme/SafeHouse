<?php
/**
 * Discovery and XML-RPC regressions. Run only on a disposable local WordPress:
 * wp eval-file /path/to/dev/security-hardening-test.php
 * This inspects header hooks because PHP CLI does not emit HTTP headers.
 */

use SafeHouse\Core\Compat;
use SafeHouse\Modules\Hardening;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable WP_ENVIRONMENT_TYPE=local WordPress.' );
}
if ( Compat::ignore_overlaps() ) {
	throw new RuntimeException( 'Disable SHOUSE_IGNORE_OVERLAPS to test integration exceptions.' );
}

global $wp_filter;
$backup = get_option( 'shouse_settings', [] );
$hook_names = [ 'site_status_tests', 'wp_head', 'template_redirect', 'xmlrpc_enabled', 'xmlrpc_methods', 'wp_headers', 'init', 'send_headers' ];
$saved_hooks = [];
foreach ( $hook_names as $hook ) {
	$saved_hooks[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
}
$discovery = [
	[ 'wp_head', 'rest_output_link_wp_head', 10 ],
	[ 'template_redirect', 'rest_output_link_header', 11 ],
	[ 'wp_head', 'rsd_link', 10 ],
	[ 'wp_head', 'wlwmanifest_link', 10 ],
	[ 'wp_head', 'wp_shortlink_wp_head', 10 ],
	[ 'template_redirect', 'wp_shortlink_header', 11 ],
];
$methods = [
	'pingback.ping' => 'pingback_ping',
	'pingback.extensions.getPingbacks' => 'pingback_extensions_getPingbacks',
	'wp.getUsersBlogs' => 'wp_getUsersBlogs',
	'metaWeblog.newPost' => 'mw_newPost',
	'jetpack.fixture' => 'integration_fixture',
];
$ordinary_methods = array_diff_key( $methods, array_flip( [ 'pingback.ping', 'pingback.extensions.getPingbacks' ] ) );
$headers = [ 'X-Pingback' => 'https://example.test/xmlrpc.php', 'x-pingback' => 'https://example.test/xmlrpc.php', 'X-Content-Type-Options' => 'nosniff', 'Link' => '</keep.css>; rel=preload', 'Cache-Control' => 'private' ];
$ordinary_headers = array_diff_key( $headers, array_flip( [ 'X-Pingback', 'x-pingback' ] ) );
$failures = [];
$messages = [];
$checks = 0;
$assert = static function ( bool $passed, string $label ) use ( &$failures, &$messages, &$checks ): void {
	++$checks;
	$messages[] = ( $passed ? 'ok   ' : 'FAIL ' ) . $label;
	if ( ! $passed ) { $failures[] = $label; }
};
$network_block = static fn() => new WP_Error( 'test_network_disabled', 'No external network in regression tests.' );
$mail_block = static fn() => true;
add_filter( 'pre_http_request', $network_block, PHP_INT_MAX );
add_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX );

$restore_hooks = static function () use ( $saved_hooks ): void {
	global $wp_filter;
	foreach ( $saved_hooks as $hook => $saved ) {
		if ( null === $saved ) { unset( $wp_filter[ $hook ] ); } else { $wp_filter[ $hook ] = clone $saved; }
	}
};
$boot = static function ( array $overrides ) use ( $restore_hooks, $hook_names, $discovery ): Hardening {
	global $wp_filter;
	$restore_hooks();
	// Replace only this module's hooks; unrelated integrations stay in the fixture.
	foreach ( $hook_names as $hook ) {
		foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && ( $fn[0] ?? null ) instanceof Hardening ) {
					remove_filter( $hook, $fn, $priority );
				}
			}
		}
	}
	remove_filter( 'xmlrpc_enabled', '__return_false' );
	// Recent core versions remove pingback.ping on local sites. Isolate this module's
	// toggle; the real core hook is restored after every scenario and in finally.
	remove_filter( 'xmlrpc_methods', 'wp_maybe_disable_xmlrpc_pingback_for_environment' );
	foreach ( $discovery as [ $hook, $callback, $priority ] ) {
		// wlwmanifest_link may be absent in future WordPress versions; never execute it here.
		add_action( $hook, $callback, $priority );
	}
	$module = new Hardening();
	$settings = get_option( 'shouse_settings', [] );
	$settings['hardening'] = array_replace( array_fill_keys( array_keys( $module->defaults() ), false ), $overrides );
	update_option( 'shouse_settings', $settings );
	$module->boot();
	return $module;
};

try {
	$defaults = ( new Hardening() )->defaults();
	$assert( false === $defaults['reduce_discovery'] && true === $defaults['disable_pingbacks'], 'discovery reduction is opt-in; pingback protection defaults on' );
	$module = $boot( [] );
	foreach ( $discovery as [ $hook, $callback, $priority ] ) {
		$assert( $priority === has_action( $hook, $callback ), 'disabled reduction preserves ' . $callback );
	}
	$assert( $methods === apply_filters( 'xmlrpc_methods', $methods ), 'disabled XML-RPC protections preserve methods' );
	$assert( $headers === apply_filters( 'wp_headers', $headers ), 'disabled pingback protection preserves headers' );

	$module = $boot( [ 'reduce_discovery' => true ] );
	foreach ( $discovery as [ $hook, $callback ] ) {
		$assert( false === has_action( $hook, $callback ), 'reduction removes ' . $callback );
	}
	$assert( $headers === apply_filters( 'wp_headers', $headers ), 'reduction preserves unrelated Link and security headers' );
	$assert( true === apply_filters( 'xmlrpc_enabled', true ), 'reduction does not disable authenticated XML-RPC' );
	$server = rest_get_server();
	$rest_result = $server->dispatch( new WP_REST_Request( 'GET', '/' ) );
	$assert( 200 === $rest_result->get_status() && ! empty( $rest_result->get_data()['routes'] ), 'real WordPress REST index still works with discovery removed' );
	foreach ( [ 'script_loader_src', 'style_loader_src' ] as $hook ) {
		$url = home_url( '/hardening-fixture.asset?ver=cache-bust-123' );
		$assert( $url === apply_filters( $hook, $url, 'hardening-fixture' ), 'asset version retained through ' . $hook );
	}

	$module = $boot( [ 'disable_pingbacks' => true ] );
	$assert( $ordinary_methods === apply_filters( 'xmlrpc_methods', $methods ), 'pingback-only protection preserves authenticated and integration methods' );
	$assert( $ordinary_headers === apply_filters( 'wp_headers', $headers ), 'X-Pingback removal is case-insensitive and keeps other headers' );
	$assert( true === apply_filters( 'xmlrpc_enabled', true ) && false === has_action( 'init', [ $module, 'deny_xmlrpc_request' ] ), 'pingback-only mode leaves authenticated XML-RPC enabled' );
	$assert( 10 === has_action( 'wp_head', 'rsd_link' ), 'pingback-only mode keeps publishing discovery' );

	if ( ! class_exists( 'Jetpack' ) && ! class_exists( 'WC_Payments' ) ) {
		$module = $boot( [ 'xmlrpc' => true, 'disable_pingbacks' => false ] );
		$assert( false === apply_filters( 'xmlrpc_enabled', true ), 'full XML-RPC protection still disables authenticated methods' );
		$assert( 0 === has_action( 'init', [ $module, 'deny_xmlrpc_request' ] ), 'full XML-RPC protection keeps the early 403 handler' );
		$assert( $ordinary_methods === apply_filters( 'xmlrpc_methods', $methods ) && $ordinary_headers === apply_filters( 'wp_headers', $headers ), 'full XML-RPC protection still removes pingbacks even if the separate toggle is off' );
		$assert( false === has_action( 'wp_head', 'rsd_link' ), 'full XML-RPC protection still removes RSD' );
	}

	// These aliases live only in the disposable CLI process. They simulate plugin detection,
	// not remote calls, payment execution or loading another plugin into the test site.
	foreach ( [ 'WC_Payments', 'Jetpack' ] as $integration ) {
		if ( ! class_exists( $integration ) ) {
			$fixture = new class {};
			class_alias( get_class( $fixture ), $integration );
		}
		$module = $boot( [ 'xmlrpc' => true, 'disable_pingbacks' => true ] );
		$assert( isset( $module->coverage()['xmlrpc'] ) && ! isset( $module->coverage()['disable_pingbacks'] ), $integration . ' XML-RPC exception does not cover pingbacks' );
		$assert( true === apply_filters( 'xmlrpc_enabled', true ) && false === has_action( 'init', [ $module, 'deny_xmlrpc_request' ] ), $integration . ' keeps authenticated XML-RPC' );
		$assert( $ordinary_methods === apply_filters( 'xmlrpc_methods', $methods ) && $ordinary_headers === apply_filters( 'wp_headers', $headers ), $integration . ' still loses only pingback methods and headers' );
		$module = $boot( [ 'xmlrpc' => true, 'disable_pingbacks' => false ] );
		$assert( $methods === apply_filters( 'xmlrpc_methods', $methods ), $integration . ' can opt out of separate pingback protection' );
	}
} finally {
	$current = get_option( 'shouse_settings', [] );
	if ( array_key_exists( 'hardening', $backup ) ) { $current['hardening'] = $backup['hardening']; } else { unset( $current['hardening'] ); }
	update_option( 'shouse_settings', $current );
	$restore_hooks();
	remove_filter( 'pre_http_request', $network_block, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX );
}

foreach ( $messages as $message ) { WP_CLI::log( $message ); }
if ( $failures ) { WP_CLI::error( count( $failures ) . ' of ' . $checks . ' hardening checks failed.' ); }
WP_CLI::success( $checks . ' hardening checks passed.' );
