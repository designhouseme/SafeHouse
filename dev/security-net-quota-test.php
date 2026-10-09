<?php
/**
 * Anonymous quota address regressions. Separate processes are required for proxy constants:
 * wp eval-file /path/to/dev/security-net-quota-test.php direct
 * wp eval-file /path/to/dev/security-net-quota-test.php generic
 * wp eval-file /path/to/dev/security-net-quota-test.php generic-real
 * wp eval-file /path/to/dev/security-net-quota-test.php cloudflare
 * wp eval-file /path/to/dev/security-net-quota-test.php cloudflare-option
 * Run only on a disposable local WordPress; no requests or messages are sent.
 */

use SafeHouse\Core\Net;
use SafeHouse\Core\Settings;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable WP_ENVIRONMENT_TYPE=local WordPress.' );
}
if ( defined( 'SHOUSE_TRUSTED_PROXIES' ) || defined( 'SHOUSE_PROXY_HEADER' ) ) {
	throw new RuntimeException( 'Run in a fresh process without proxy constants in wp-config.php.' );
}
$mode = $args[0] ?? 'direct';
if ( ! in_array( $mode, [ 'direct', 'generic', 'generic-real', 'cloudflare', 'cloudflare-option' ], true ) ) {
	throw new RuntimeException( 'Unknown quota network fixture mode.' );
}
if ( in_array( $mode, [ 'generic', 'generic-real' ], true ) ) {
	define( 'SHOUSE_TRUSTED_PROXIES', [ '10.0.0.0/8', '2001:db8:ffff::/48' ] );
	if ( 'generic-real' === $mode ) {
		define( 'SHOUSE_PROXY_HEADER', 'HTTP_X_REAL_IP' );
	}
} elseif ( 'cloudflare' === $mode ) {
	define( 'SHOUSE_TRUSTED_PROXIES', 'cloudflare' );
}

$server_backup = $_SERVER;
$settings_filter = static fn() => [ 'general' => [ 'proxy' => 'cloudflare-option' === $mode ? 'cloudflare' : 'none' ] ];
add_filter( 'pre_option_' . Settings::OPTION, $settings_filter, PHP_INT_MAX );
$checks = 0;
$failures = [];
$messages = [];
$check = static function ( array $server, ?string $expected, string $label ) use ( &$checks, &$failures, &$messages ): void {
	$_SERVER = $server;
	$actual = Net::quota_subject();
	++$checks;
	$messages[] = ( $actual === $expected ? 'ok   ' : 'FAIL ' ) . $label;
	if ( $actual !== $expected ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', received ' . var_export( $actual, true );
	}
};

try {
	$check( [ 'REMOTE_ADDR' => '8.8.8.8' ], '8.8.8.8', 'direct public IPv4' );
	foreach ( [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP' ] as $header ) {
		foreach ( [ '9.9.9.9', 'invalid', [ '9.9.9.9' ], str_repeat( 'x', 4096 ) ] as $value ) {
			$check( [ 'REMOTE_ADDR' => '8.8.8.8', $header => $value ], '8.8.8.8', 'direct connection ignores forged ' . $header );
		}
	}
	$check( [ 'REMOTE_ADDR' => '::ffff:8.8.8.8' ], '8.8.8.8', 'mapped IPv4 shares the IPv4 quota' );
	$check( [ 'REMOTE_ADDR' => '::ffff:0808:0808' ], '8.8.8.8', 'hex mapped IPv4 shares the IPv4 quota' );
	$check( [ 'REMOTE_ADDR' => '2001:4860:abcd:12::1' ], '2001:4860:abcd:12::/64', 'IPv6 becomes a network prefix' );
	$check( [ 'REMOTE_ADDR' => '2001:4860:ABCD:0012:ffff:FFFF:ffff:ffff' ], '2001:4860:abcd:12::/64', 'IPv6 aliases and interface rotation share /64' );
	$check( [ 'REMOTE_ADDR' => '2001:4860:abcd:13::1' ], '2001:4860:abcd:13::/64', 'adjacent IPv6 /64 stays separate' );
	foreach ( [ null, '', [], 123, 'not-an-ip', '8.8.8.8:443', '8.8.8.8,9.9.9.9', '<b>8.8.8.8</b>', "8.8.8.8\n", ' 8.8.8.8', '008.008.008.008', '[2001:4860::1]' ] as $remote ) {
		$check( [ 'REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '8.8.8.8' ], null, 'malformed peer cannot become a visitor' );
	}
	$check( [], null, 'missing peer is unknown' );
	foreach ( [ '0.0.0.0', '10.1.2.3', '127.0.0.1', '169.254.1.1', '172.16.1.1', '192.168.1.1', '100.64.1.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1', '::ffff:192.168.1.1' ] as $remote ) {
		$check( [ 'REMOTE_ADDR' => $remote ], null, 'private/shared/special-use peer is not a visitor: ' . $remote );
	}
	$check( [ 'REMOTE_ADDR' => '173.245.48.1' ], null, 'Cloudflare peer without verified visitor has no shared quota' );
	$check( [ 'REMOTE_ADDR' => '::ffff:173.245.48.1' ], null, 'mapped Cloudflare peer without verified visitor has no shared quota' );

	if ( in_array( $mode, [ 'generic', 'generic-real' ], true ) ) {
		$header = 'generic-real' === $mode ? 'HTTP_X_REAL_IP' : 'HTTP_X_FORWARDED_FOR';
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '8.8.8.8' ], '8.8.8.8', 'configured proxy supplies a valid public visitor' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '9.9.9.9, 8.8.8.8, 10.0.0.2' ], '8.8.8.8', 'first untrusted hop is selected from the right' );
		$check( [ 'REMOTE_ADDR' => '::ffff:10.0.0.1', $header => '::ffff:8.8.8.8, ::ffff:10.0.0.2' ], '8.8.8.8', 'mapped proxy and visitor use IPv4 trust and quota' );
		$check( [ 'REMOTE_ADDR' => '2001:db8:ffff::1', $header => '2001:4860:abcd:12::1234' ], '2001:4860:abcd:12::/64', 'configured IPv6 proxy supplies grouped public visitor' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '8.8.8.8, 172.16.0.1' ], null, 'unconfigured private hop stops trust traversal' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '8.8.8.8, 173.245.48.1' ], null, 'unconfigured Cloudflare hop is not a shared visitor quota' );
		foreach ( [ null, '', [], true, 'garbage', '8.8.8.8,', '8.8.8.8,garbage,10.0.0.2', "8.8.8.8\r\n", "8.8.8.8\0", '8.8.8.8:80', 'for=8.8.8.8', '10.0.0.2, 10.0.0.3', str_repeat( ' ', 2049 ) ] as $value ) {
			$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => $value ], null, 'missing/malformed right suffix or all-trusted chain is unknown' );
		}
		foreach ( [ '', 'garbage', "garbage\r\n\0\x7f", str_repeat( 'x', 4096 ), implode( ',', array_fill( 0, 128, '9.9.9.9' ) ) ] as $prefix ) {
			$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => $prefix . ',8.8.8.8,10.0.0.2' ], '8.8.8.8', 'untrusted left prefix cannot disable the verified visitor quota' );
		}
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => str_repeat( ' ', 4096 ) . '8.8.8.8' ], null, 'truncated first token cannot become a valid IP after trimming' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => str_repeat( ' ', 4096 ) . '8.8.8.8,10.0.0.2' ], null, 'truncated first token remains unknown behind a trusted suffix' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '8.8.8.8,' . implode( ',', array_fill( 0, 31, '10.0.0.2' ) ) ], '8.8.8.8', 'bounded chain accepts the 32-hop limit' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '8.8.8.8,' . implode( ',', array_fill( 0, 32, '10.0.0.2' ) ) ], null, 'trust traversal stops after 32 inspected hops' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', $header => '8.8.8.8', 'HTTP_CF_CONNECTING_IP' => '9.9.9.9' ], '8.8.8.8', 'generic proxy ignores unconfigured Cloudflare header' );
		$check( [ 'REMOTE_ADDR' => '172.16.0.1', $header => '8.8.8.8' ], null, 'unconfigured private proxy cannot assert visitor identity' );
	} elseif ( in_array( $mode, [ 'cloudflare', 'cloudflare-option' ], true ) ) {
		$check( [ 'REMOTE_ADDR' => '173.245.48.1', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8' ], '8.8.8.8', 'configured Cloudflare visitor is used' );
		$check( [ 'REMOTE_ADDR' => '::ffff:173.245.48.1', 'HTTP_CF_CONNECTING_IP' => '::ffff:8.8.8.8' ], '8.8.8.8', 'Cloudflare supports normalized mapped addresses' );
		$check( [ 'REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '2001:4860:abcd:12::8' ], '2001:4860:abcd:12::/64', 'Cloudflare IPv6 visitor uses /64' );
		$check( [ 'REMOTE_ADDR' => '173.245.48.1', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8' ], null, 'Cloudflare never falls back to X-Forwarded-For' );
		foreach ( [ null, [], false, '', 'invalid', '8.8.8.8,9.9.9.9', '<b>8.8.8.8</b>', "8.8.8.8\r\n", '192.168.1.1', '173.245.48.2', str_repeat( '8', 2049 ) ] as $value ) {
			$check( [ 'REMOTE_ADDR' => '173.245.48.1', 'HTTP_CF_CONNECTING_IP' => $value ], null, 'invalid Cloudflare visitor does not consume a shared proxy bucket' );
		}
	} else {
		$check( [ 'REMOTE_ADDR' => '173.245.48.1', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8' ], null, 'unconfigured Cloudflare cannot assert visitor identity' );
		$check( [ 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '8.8.8.8' ], null, 'unconfigured private proxy cannot assert visitor identity' );
	}
} finally {
	$_SERVER = $server_backup;
	remove_filter( 'pre_option_' . Settings::OPTION, $settings_filter, PHP_INT_MAX );
}

foreach ( $messages as $message ) { WP_CLI::log( $message ); }
if ( $failures ) { WP_CLI::error( implode( "\n", $failures ) ); }
WP_CLI::success( $checks . ' quota network checks passed (' . $mode . ').' );
