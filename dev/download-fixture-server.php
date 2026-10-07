<?php
/** Local HTTP transport fixture, run only in an isolated test network. */
// phpcs:ignoreFile

$server = stream_socket_server( 'tcp://0.0.0.0:8080', $errno, $error );
if ( false === $server ) throw new RuntimeException( $error );
$write = static function ( $socket, string $bytes ): bool {
	while ( '' !== $bytes ) {
		$n = @fwrite( $socket, $bytes );
		if ( false === $n || 0 === $n ) return false;
		$bytes = substr( $bytes, $n );
	}
	return true;
};
while ( $socket = @stream_socket_accept( $server, -1 ) ) {
	stream_set_timeout( $socket, 5 );
	$line = fgets( $socket );
	$path = is_string( $line ) ? ( explode( ' ', $line )[1] ?? '' ) : '';
	while ( false !== ( $line = fgets( $socket ) ) && "\r\n" !== $line ) {}
	if ( ! in_array( $path, [ '/valid', '/oversize-chunked', '/oversize-close', '/oversize-length' ], true ) ) {
		$write( $socket, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n" );
		fclose( $socket );
		continue;
	}
	$chunked = in_array( $path, [ '/valid', '/oversize-chunked' ], true );
	$size = '/valid' === $path ? 1024 : 1024 * 1024;
	$headers = "HTTP/1.1 200 OK\r\nContent-Type: application/zip\r\nContent-Disposition: attachment; filename=shouse-transport-sentinel.txt\r\nConnection: close\r\n";
	if ( $chunked ) $headers .= "Transfer-Encoding: chunked\r\n";
	if ( '/oversize-length' === $path ) $headers .= "Content-Length: $size\r\n";
	$write( $socket, $headers . "\r\n" );
	for ( $sent = 0; $sent < $size; $sent += strlen( $block ) ) {
		$block = str_repeat( 'Z', min( 16384, $size - $sent ) );
		if ( ! $write( $socket, $chunked ? dechex( strlen( $block ) ) . "\r\n$block\r\n" : $block ) ) break;
	}
	if ( $chunked ) $write( $socket, "0\r\n\r\n" );
	fclose( $socket );
}
