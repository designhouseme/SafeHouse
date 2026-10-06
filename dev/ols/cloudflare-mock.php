<?php
/**
 * Cloudflare API stand-in for dev/ols/cloudflare-test.sh: records every call in /data/requests.jsonl
 * and answers success, or an API error when /data/mode says "error".
 *
 * @package WPHouse
 */

// phpcs:ignoreFile -- test server, not part of the plugin.

$mode  = is_file( '/data/mode' ) ? trim( (string) file_get_contents( '/data/mode' ) ) : 'ok';
$entry = [
	'path' => parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ),
	'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
	'body' => json_decode( (string) file_get_contents( 'php://input' ), true ),
];
file_put_contents( '/data/requests.jsonl', json_encode( $entry, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );
header( 'Content-Type: application/json' );
if ( 'error' === $mode ) {
	http_response_code( 400 );
	echo json_encode( [ 'success' => false, 'errors' => [ [ 'code' => 10000, 'message' => 'Authentication error' ] ] ] );
	return true;
}
echo json_encode( [ 'success' => true, 'errors' => [], 'result' => [ 'id' => 'mock' ] ] );
return true;
