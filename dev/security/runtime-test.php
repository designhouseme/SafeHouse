<?php
/** Unit/integration assertions and state management for isolated real-request runtime regressions. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	exit( 1 );
}

use SafeHouse\Core\RuntimeMonitor;

global $wpdb;
$state_file = (string) getenv( 'SHOUSE_RUNTIME_FIXTURE_STATE' );
if ( ! str_starts_with( $state_file, '/tmp/shouse-runtime-' ) ) {
	WP_CLI::error( 'A private container-local fixture state path is required.' );
}
$class = new ReflectionClass( RuntimeMonitor::class );
// Helper WP-CLI invocations must not create observations around the genuine PHP processes.
$class->getProperty( 'finished' )->setValue( null, true );
$checks = 0;
$assert = static function ( bool $ok, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( 'PASS: ' . $message );
};
$gate = static function ( int $timestamp = 0 ) use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, (string) $timestamp, 'shouse_stability_write_after' ) );
	wp_cache_delete( 'shouse_stability_write_after', 'options' );
};
$rows = static fn(): array => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY slot', RuntimeMonitor::table() ), ARRAY_A );
$options = [ 'shouse_stability_session', 'shouse_stability_write_after', 'shouse_stability_db_version', 'shouse_stability_diagnostics' ];
$operation = $args[0] ?? '';

if ( 'restore' === $operation ) {
	if ( ! file_exists( $state_file ) ) {
		return;
	}
	$state = json_decode( (string) file_get_contents( $state_file ), true );
	if ( ! is_array( $state ) || ! isset( $state['rows'], $state['options'] ) ) {
		WP_CLI::error( 'Fixture backup is unreadable; refusing an incomplete restoration.' );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', RuntimeMonitor::table() ) );
	foreach ( $state['rows'] as $row ) {
		$wpdb->insert( RuntimeMonitor::table(), $row );
	}
	foreach ( $options as $name ) {
		delete_option( $name );
	}
	foreach ( $state['options'] as $row ) {
		$wpdb->insert( $wpdb->options, $row );
		wp_cache_delete( $row['option_name'], 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	unlink( $state_file );
	WP_CLI::success( 'Runtime fixture state restored.' );
	return;
}

if ( 'prepare' === $operation ) {
	RuntimeMonitor::install();
	$state = [
		'rows'    => $rows(),
		'options' => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name IN (%s, %s, %s, %s)', $wpdb->options, ...$options ), ARRAY_A ),
	];
	if ( false === file_put_contents( $state_file, wp_json_encode( $state ), LOCK_EX ) ) {
		WP_CLI::error( 'Could not back up disposable runtime state.' );
	}
	chmod( $state_file, 0600 );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', RuntimeMonitor::table() ) );
	delete_option( 'shouse_stability_session' );
	delete_option( 'shouse_stability_write_after' );
	delete_option( 'shouse_stability_db_version' );
	$gate_insert_blocked = 0;
	$gate_failure = static function ( string $sql ) use ( &$gate_insert_blocked ): string {
		if ( str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, "'shouse_stability_write_after'" ) ) {
			++$gate_insert_blocked;
			return 'INSERT INTO shouse_missing_runtime_gate_fixture (missing) VALUES (1)';
		}
		return $sql;
	};
	$error_policy = $wpdb->suppress_errors( true );
	add_filter( 'query', $gate_failure );
	try {
		RuntimeMonitor::install();
	} finally {
		remove_filter( 'query', $gate_failure );
		$wpdb->suppress_errors( $error_policy );
	}
	$assert( 1 === $gate_insert_blocked && false === get_option( 'shouse_stability_db_version' ), 'failed gate creation cannot mark runtime storage installed' );
	RuntimeMonitor::install();
	$assert( '1' === get_option( 'shouse_stability_db_version' ) && '0' === get_option( 'shouse_stability_write_after' ), 'a later healthy install creates the gate before marking storage ready' );
	update_option( 'shouse_stability_diagnostics', wp_json_encode( [ 'collected_at' => time(), 'complete' => true, 'cron' => [ 'oldest_overdue_at' => null ], 'actions' => [ 'oldest_overdue_at' => null ] ] ), false );
	$health_module = new SafeHouse\Modules\Stability();
	$assert( 'good' === $health_module->health()['status'] && RuntimeMonitor::storage_available(), 'fresh empty diagnostics report healthy available runtime storage' );
	// Deliberately leave the option cache stale: availability must probe the actual write gate.
	$wpdb->delete( $wpdb->options, [ 'option_name' => 'shouse_stability_write_after' ] );
	$assert( '0' === get_option( 'shouse_stability_write_after' ), 'fixture retains a stale cached gate after direct deletion' );
	$assert( 'recommended' === $health_module->health()['status'] && ! RuntimeMonitor::storage_available(), 'a missing gate is unavailable in Site Health despite a stale option cache' );
	wp_cache_delete( 'shouse_stability_write_after', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	add_option( 'shouse_stability_write_after', '0', '', false );
	$gate();
	$assert( RuntimeMonitor::record( 'slow', 'web', 'plugin:fixture', 10, 3100, 1000000 ), 'first observation passes the atomic write gate' );
	$assert( ! RuntimeMonitor::record( 'slow', 'web', 'plugin:fixture', 10, 3200, 2000000 ), 'a second observation within the gate is not written' );
	$gate();
	$assert( RuntimeMonitor::record( 'slow', 'web', 'plugin:fixture', 10, 4500, 3000000 ), 'later observations can update the same group' );
	$current = $rows();
	$assert( 1 === count( $current ) && 2 === (int) $current[0]['observations'] && 4500 === (int) $current[0]['elapsed_ms'] && 3000000 === (int) $current[0]['peak_bytes'], 'grouping retains sample counts and maxima' );
	$assert( 'plugin:example' === RuntimeMonitor::component( WP_PLUGIN_DIR . '/example/private/path.php' ) && '' === RuntimeMonitor::component( '/private/customer/secrets.php' ), 'component attribution retains only a component boundary' );
	$gate();
	RuntimeMonitor::record( 'fatal', 'PRIVATE_CONTEXT', 'https://private.example/?token=PRIVATE_COMPONENT', 12, 0, 0 );
	$current = wp_json_encode( $rows() );
	$assert( ! str_contains( $current, 'PRIVATE_' ) && ! str_contains( $current, 'https://' ), 'unknown context and components cannot persist URLs or arbitrary text' );
	$assert( ! RuntimeMonitor::record( 'PRIVATE_KIND', 'web', '', 0, 0, 0 ), 'unknown event kinds are rejected' );
	$gate();
	$failure = static function ( string $sql ): string {
		return str_starts_with( $sql, 'INSERT INTO `' . RuntimeMonitor::table() . '`' ) ? 'INSERT INTO shouse_missing_runtime_fixture (missing) VALUES (1)' : $sql;
	};
	add_filter( 'query', $failure );
	$previous_errors = $wpdb->suppress_errors;
	$assert( ! RuntimeMonitor::record( 'fatal', 'web', 'plugin:fixture', 99, 0, 0 ), 'an incident storage failure returns without crashing the request' );
	remove_filter( 'query', $failure );
	$assert( $previous_errors === $wpdb->suppress_errors, 'database error-display policy is restored after failure' );

	// Find one genuine fingerprint per slot in memory; exercise every fixed slot without large tables.
	$lines = [];
	for ( $line = 1; count( $lines ) < 256 && $line < 20000; ++$line ) {
		$hash = hash( 'sha256', implode( '|', [ 'fatal', 'web', 'plugin:fixture', $line, gmdate( 'Y-m-d' ) ] ) );
		$lines[ hexdec( substr( $hash, 0, 2 ) ) ] = $line;
	}
	$assert( 256 === count( $lines ), 'fixture covers every storage slot' );
	foreach ( $lines as $line ) {
		$gate();
		if ( ! RuntimeMonitor::record( 'fatal', 'web', 'plugin:fixture', $line, 0, 0 ) ) {
			WP_CLI::error( 'Could not populate a fixed observation slot.' );
		}
	}
	$assert( 256 === count( $rows() ), 'observation storage is bounded to 256 slots' );
	$gate();
	RuntimeMonitor::record( 'memory_exhausted', 'web', 'plugin:new', 99999, 0, 0 );
	$assert( 256 === count( $rows() ) && 100 === count( RuntimeMonitor::recent( 9999 ) ), 'new groups replace collisions and list reads remain bounded' );

	$http_args = [ 'headers' => [ 'Authorization' => 'PRIVATE_AUTH' ], 'body' => 'PRIVATE_HTTP_BODY' ];
	for ( $i = 0; $i < 25; ++$i ) {
		$url = 'https://private.example/PRIVATE_HTTP_URL/' . $i;
		$assert_args = RuntimeMonitor::http_start( $http_args, $url );
		if ( $assert_args !== $http_args ) {
			WP_CLI::error( 'HTTP observation modified request arguments.' );
		}
		RuntimeMonitor::http_end( new WP_Error( 'fixture', 'PRIVATE_HTTP_ERROR' ), 'response', 'fixture', $http_args, $url );
	}
	$gate();
	RuntimeMonitor::record( 'sample', 'web', '', 0, 1, 1 );
	$current = $rows();
	$samples = array_values( array_filter( $current, static fn( array $row ): bool => 'sample' === $row['kind'] ) );
	$assert( 1 === count( $samples ) && 20 === (int) $samples[0]['http_count'] && 20 === (int) $samples[0]['http_failures'], 'HTTP observations pass through arguments and stop at 20 calls' );
	$assert( ! str_contains( wp_json_encode( $current ), 'PRIVATE_' ), 'HTTP bodies, credentials, URLs and error messages are absent from storage' );
	WP_CLI::success( $checks . ' runtime storage checks passed.' );
	return;
}

if ( 'reset' === $operation ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', RuntimeMonitor::table() ) );
	$gate();
	if ( in_array( $args[1] ?? '', [ 'session', 'safe', 'disabled' ], true ) ) {
		update_option( 'shouse_stability_session', time() + 600, false );
	} elseif ( 'expired' === ( $args[1] ?? '' ) ) {
		update_option( 'shouse_stability_session', time() - 1, false );
	} else {
		delete_option( 'shouse_stability_session' );
	}
	return;
}

if ( 'verify' === $operation ) {
	$mode = $args[1] ?? '';
	$current = $rows();
	$expected = [ 'fatal' => 'fatal', 'memory' => 'memory_exhausted', 'timeout' => 'time_limit', 'slow' => 'slow' ];
	if ( isset( $expected[ $mode ] ) ) {
		$assert( 1 === count( $current ) && $expected[ $mode ] === $current[0]['kind'], 'real ' . $mode . ' request records its expected observation' );
		$assert( 'web' === $current[0]['context'], 'real ' . $mode . ' request uses WordPress bootstrap without WP-CLI emulation' );
	} elseif ( 'session' === $mode ) {
		$assert( count( $current ) <= 1 && ( ! $current || in_array( $current[0]['kind'], [ 'sample', 'slow', 'memory_pressure' ], true ) ), 'an enabled sampling session boots normally and respects sampling' );
	} elseif ( 'expired' === $mode ) {
		$assert( count( $current ) <= 1 && ( ! $current || in_array( $current[0]['kind'], [ 'slow', 'memory_pressure' ], true ) ), 'an expired session does not record detailed samples' );
	} else {
		$assert( [] === $current, $mode . ' does not record a request observation' );
	}
	if ( in_array( $mode, [ 'baseline', 'safe', 'disabled' ], true ) ) {
		$assert( '0' === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'shouse_stability_write_after' ) ), $mode . ' leaves the recording gate untouched' );
	}
	$assert( ! str_contains( wp_json_encode( $current ), 'PRIVATE_' ) && ! str_contains( wp_json_encode( $current ), '/tests/' ), 'real ' . $mode . ' request retains neither secrets nor absolute fixture paths' );
	return;
}

if ( 'verify-safe-cli' === $operation ) {
	$assert( SafeHouse\Core\SafeMode::active() && false === $class->getProperty( 'started' )->getValue(), 'safe-mode CLI boot keeps automatic runtime monitoring inactive' );
	$status = json_decode( (string) file_get_contents( $args[1] ), true );
	$scan = json_decode( (string) file_get_contents( $args[2] ), true );
	$assert( is_array( $status ) && isset( $status['diagnostics'], $status['incidents'], $status['queue'] ), 'stability status remains registered and usable in safe mode' );
	$assert( is_array( $scan ) && 'saved' === ( $scan['storage'] ?? '' ) && (int) ( $scan['collected_at'] ?? 0 ) >= time() - 30, 'explicit bounded stability scan remains usable in safe mode' );
	return;
}

WP_CLI::error( 'Unknown runtime fixture operation.' );
