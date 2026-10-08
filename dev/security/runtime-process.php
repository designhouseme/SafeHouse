<?php
/** Real PHP request processes, run only by runtime-test.sh inside its disposable container. */
if ( '1' !== getenv( 'SHOUSE_RUNTIME_FIXTURE' ) || ! in_array( $argv[1] ?? '', [ 'fatal', 'baseline', 'memory', 'timeout', 'slow', 'session', 'expired', 'safe', 'disabled' ], true ) ) {
	exit( 2 );
}

$mode = $argv[1];
define( 'SHOUSE_MODULES', [ 'stability' => ! in_array( $mode, [ 'baseline', 'disabled' ], true ) ] );
if ( 'safe' === $mode ) {
	define( 'SHOUSE_SAFE_MODE', true );
}
$_SERVER['HTTP_HOST']       = 'wordpress';
$_SERVER['SERVER_NAME']     = 'wordpress';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/runtime-fixture?token=PRIVATE_RUNTIME_URL_TOKEN';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_POST['password']          = 'PRIVATE_RUNTIME_POST_PASSWORD';

// Real bootstrap probe: ordinary plugin loading must still precede pluggable.php.
$GLOBALS['wp_filter']['plugin_loaded'][PHP_INT_MAX][] = [
	'function'      => static function ( string $file ): void {
		if ( 'shouse.php' === basename( $file ) ) {
			$class = new ReflectionClass( SafeHouse\Core\RuntimeMonitor::class );
			$GLOBALS['shouse_runtime_early'] = [
				'pluggable' => function_exists( 'wp_rand' ),
				'started'   => $class->getProperty( 'started' )->getValue(),
			];
		}
	},
	'accepted_args' => 1,
];
require '/var/www/html/wp-load.php';
if ( ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	exit( 2 );
}

if ( in_array( $mode, [ 'session', 'expired', 'safe', 'disabled' ], true ) ) {
	$early = $GLOBALS['shouse_runtime_early'] ?? [];
	$expected = ! in_array( $mode, [ 'safe', 'disabled' ], true );
	if ( ( $early['pluggable'] ?? true ) || ( $early['started'] ?? null ) !== $expected ) {
		fwrite( STDERR, 'Early bootstrap state did not match the scenario.' );
		exit( 3 );
	}
	if ( 'session' !== $mode && false !== has_filter( 'http_request_args', [ SafeHouse\Core\RuntimeMonitor::class, 'http_start' ] ) ) {
		fwrite( STDERR, 'Inactive or expired diagnostics installed HTTP sampling hooks.' );
		exit( 4 );
	}
	echo 'BOOTSTRAP_OK';
	return;
}

switch ( $mode ) {
	case 'fatal':
	case 'baseline':
		trigger_error( 'PRIVATE_RUNTIME_FATAL_MESSAGE password=PRIVATE_RUNTIME_POST_PASSWORD', E_USER_ERROR );
		break;
	case 'memory':
		// Leave enough for normal bootstrap, then fail one real allocation under a finite limit.
		$limit = memory_get_usage( true ) + 12 * 1024 * 1024;
		ini_set( 'memory_limit', (string) $limit );
		$blocks = [];
		while ( true ) {
			$blocks[] = str_repeat( 'M', 1024 * 1024 );
		}
	case 'timeout':
		set_time_limit( 1 );
		while ( true ) {
			hash( 'sha256', 'bounded-by-PHP-and-an-external-process-deadline' );
		}
	case 'slow':
		usleep( 3000000 );
		echo 'SLOW_REQUEST_FINISHED';
		break;
}
