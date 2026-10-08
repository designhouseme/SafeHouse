<?php
/** Real SQL/worker concurrency regression. Invoke seed, three workers, then verify. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { exit( 1 ); }
use SafeHouse\Core\Queue;
global $wpdb;
$mode = $args[0] ?? '';
Queue::handler( 'race', static function ( array $payload ) use ( $wpdb ) {
	$wpdb->query( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = 'shouse_fixture_active'" );
	$active = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'shouse_fixture_active'" );
	if ( $active > 1 ) {
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = 'shouse_fixture_overlap'" );
	}
	usleep( 10000 );
	$key = 'shouse_fixture_job_' . (int) $payload['id'];
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s", $key ) );
	$wpdb->query( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) - 1 WHERE option_name = 'shouse_fixture_active'" );
	return true;
} );
if ( 'seed' === $mode ) {
	for ( $i = 1; $i <= 12; ++$i ) {
		add_option( 'shouse_fixture_job_' . $i, 0, '', false );
		if ( ! Queue::add( 'race', [ 'id' => $i ] ) ) { throw new RuntimeException( 'Could not seed queue.' ); }
	}
	add_option( 'shouse_fixture_winners', 0, '', false );
	add_option( 'shouse_fixture_active', 0, '', false );
	add_option( 'shouse_fixture_overlap', 0, '', false );
} elseif ( 'work' === $mode ) {
	if ( Queue::reserve( 'fixture-race-rate', 60 ) ) {
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = 'shouse_fixture_winners'" );
	}
	Queue::run( 'race', 20 );
} elseif ( 'verify' === $mode ) {
	$counts = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", 'shouse_fixture_job_%' ) );
	$pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE queue = %s', Queue::table(), 'race' ) );
	$winners = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'shouse_fixture_winners'" );
	$overlap = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'shouse_fixture_overlap'" );
	$active = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'shouse_fixture_active'" );
	if ( 12 !== count( $counts ) || [ 1 ] !== array_values( array_unique( array_map( 'intval', $counts ) ) ) || 0 !== $pending || 1 !== $winners || 0 !== $overlap || 0 !== $active ) {
		throw new RuntimeException( 'Concurrent delivery lost or duplicated work, or shared rate reservation had multiple winners.' );
	}
	WP_CLI::success( 'Three real workers delivered all 12 jobs once with one active worker and one atomic rate slot.' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 'shouse_fixture_%' ) );
} else { throw new RuntimeException( 'Unknown queue race phase.' ); }
