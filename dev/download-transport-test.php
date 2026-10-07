<?php
/** wp eval-file; start download-fixture-server.php as shouse-download-fixture in an isolated network. */
// phpcs:ignoreFile

use SafeHouse\Core\Updater;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! Updater::is_local() ) throw new RuntimeException( 'Disposable local WordPress required.' );
$host = 'shouse-download-fixture';
$base = 'http://' . $host . ':8080';
if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) ) define( 'WP_ACCESSIBLE_HOSTS', $host );
$allowed = static fn( string $url ): bool => str_starts_with( $url, $base . '/' );
$pre = static fn( $value, $args, $url ) => $allowed( $url ) ? false : $value;
$external = static fn( $value, $hostname ) => $hostname === $host ? true : $value;
$ports = static fn( $values, $hostname ) => $hostname === $host ? array_merge( $values, [ 8080 ] ) : $values;
$observed = [];
$debug = static function ( $response, $context, $class, $args, $url ) use ( &$observed, $allowed ): void {
	if ( ! $allowed( $url ) || empty( $args['filename'] ) ) return;
	clearstatcache( true, $args['filename'] );
	$observed[ $url ] = [ 'bytes' => filesize( $args['filename'] ), 'file' => $args['filename'], 'limit' => $args['limit_response_size'],
		'length' => is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'content-length' ) ];
};
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void {
	if ( ! $ok ) throw new RuntimeException( $message );
	++$checks; WP_CLI::log( 'PASS ' . $message );
};
$victim = sys_get_temp_dir() . '/shouse-transport-sentinel.txt';
if ( file_exists( $victim ) ) throw new RuntimeException( 'Fixture sentinel already exists.' );
file_put_contents( $victim, 'PRESERVE EXISTING FILE' );
add_filter( 'pre_http_request', $pre, 1000, 3 );
add_filter( 'http_request_host_is_external', $external, 1000, 2 );
add_filter( 'http_allowed_safe_ports', $ports, 1000, 2 );
add_action( 'http_api_debug', $debug, 1000, 5 );
try {
	$download = new ReflectionMethod( Updater::class, 'download' );
	$file = $download->invoke( null, $base . '/valid', 1024 );
	if ( is_wp_error( $file ) ) throw new RuntimeException( $file->get_error_message() );
	$check( is_string( $file ) && basename( $file ) === 'package.zip' && file_get_contents( $file ) === str_repeat( 'Z', 1024 ), 'real HTTP chunked download accepts the exact signed size' );
	$check( '' === $observed[ $base . '/valid' ]['length'], 'valid transport response has no Content-Length' );
	$check( 0700 === ( fileperms( dirname( $file ) ) & 0777 ), 'download directory is private' );
	foreach ( [ '/oversize-chunked', '/oversize-close', '/oversize-length' ] as $path ) {
		$result = $download->invoke( null, $base . $path, 1024 );
		$received = $observed[ $base . $path ] ?? [];
		$check( is_wp_error( $result ) && 'shouse_package_size' === $result->get_error_code(), 'real HTTP rejects oversize ' . $path );
		$check( 1025 === ( $received['bytes'] ?? null ) && 1025 === $received['limit'], 'real transport writes only signed size + 1 for ' . $path );
		$check( ! file_exists( $received['file'] ) && ! is_dir( dirname( $received['file'] ) ), 'oversize private file and directory removed for ' . $path );
	}
	$check( 'PRESERVE EXISTING FILE' === file_get_contents( $victim ), 'real Content-Disposition never renames or deletes the existing sentinel' );
	WP_CLI::success( "$checks real HTTP transport checks passed." );
} finally {
	remove_filter( 'pre_http_request', $pre, 1000 );
	remove_filter( 'http_request_host_is_external', $external, 1000 );
	remove_filter( 'http_allowed_safe_ports', $ports, 1000 );
	remove_action( 'http_api_debug', $debug, 1000 );
	if ( file_exists( $victim ) ) unlink( $victim );
}
