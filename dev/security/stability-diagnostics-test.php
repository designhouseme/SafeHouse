<?php
/** Disposable WordPress tests: diagnostics observe queues without mutating or loading their payloads. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	exit( 1 );
}

use SafeHouse\Core\StabilityDiagnostics;

global $wpdb;
$checks        = 0;
$original_cron = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'cron' ) );
$original      = get_option( 'shouse_stability_diagnostics', false );
$heartbeat     = get_option( 'shouse_stability_heartbeat', false );
$actions_table = $wpdb->prefix . 'actionscheduler_actions';
$claims_table  = $wpdb->prefix . 'actionscheduler_claims';
$tables_made   = false;
$queries       = [];
$http_calls    = 0;
$assert        = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	WP_CLI::log( 'PASS: ' . $label );
};
$write_cron = static function ( string $raw ) use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $raw, 'cron' ) );
	wp_cache_delete( 'cron', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
};
$events = static function ( int $count ): array {
	$data = [ 'version' => 2 ];
	for ( $i = 0; $i < $count; ++$i ) {
		$data[ time() - 3600 + $i ]['shouse_fixture_hook'][ md5( (string) $i ) ] = [ 'schedule' => false, 'args' => [ 'PRIVATE_PAYLOAD_DO_NOT_LOG', $i ] ];
	}
	return $data;
};
$observe = static function ( string $sql ) use ( &$queries ): string {
	$queries[] = $sql;
	return $sql;
};
$block_http = static function () use ( &$http_calls ): WP_Error {
	++$http_calls;
	return new WP_Error( 'fixture_no_http' );
};
$collect = static function () use ( &$queries, $observe ): array {
	$queries = [];
	add_filter( 'query', $observe, PHP_INT_MAX );
	try {
		return StabilityDiagnostics::collect();
	} finally {
		remove_filter( 'query', $observe, PHP_INT_MAX );
	}
};

class ShouseDiagnosticsWakeupFixture {
	public function __wakeup(): void {
		$GLOBALS['shouse_diagnostics_wakeup'] = true;
	}
}

try {
	add_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	update_option( 'shouse_stability_heartbeat', time() - 30, false );
	$write_cron( serialize( $events( 3 ) ) );
	$before = $wpdb->get_var( $wpdb->prepare( 'SELECT SHA2(option_value, 256) FROM %i WHERE option_name = %s', $wpdb->options, 'cron' ) );
	$sample = $collect();
	$assert( 'ok' === $sample['cron']['status'] && 3 === $sample['cron']['event_count'] && $sample['cron']['exact'], 'small cron inventory is exact' );
	$assert( 3 === $sample['cron']['overdue_count'] && $sample['cron']['oldest_overdue_at'] <= time() - 3590, 'overdue count and oldest timestamp are observed' );
	$assert( $sample['cron']['heartbeat_at'] > 0 && $sample['cron']['disabled'] === ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), 'heartbeat and disabled visitor trigger are facts, not an invented failure' );
	$assert( ! str_contains( wp_json_encode( $sample ), 'PRIVATE_PAYLOAD_DO_NOT_LOG' ), 'cron arguments are never returned' );
	$assert( $sample === StabilityDiagnostics::cached(), 'UI reads the persisted bounded snapshot' );
	$assert( $before === $wpdb->get_var( $wpdb->prepare( 'SELECT SHA2(option_value, 256) FROM %i WHERE option_name = %s', $wpdb->options, 'cron' ) ), 'diagnostics do not modify cron' );
	if ( ! class_exists( 'ActionScheduler', false ) ) {
		$assert( 'missing' === $sample['actions']['status'], 'Action Scheduler absence is distinct from storage failure' );
	}
	$sample['collected_at'] = time() - 60;
	update_option( 'shouse_stability_diagnostics', wp_json_encode( $sample ), false );
	$write_cron( serialize( $events( 5 ) ) );
	$sample = $collect();
	$assert( 'growing' === $sample['cron']['trend']['direction'] && 2 === $sample['cron']['trend']['delta'], 'two exact samples expose count growth without claiming throughput' );
	$write_cron( serialize( $events( 1000 ) ) );
	$sample = $collect();
	$assert( 1000 === $sample['cron']['event_count'] && $sample['cron']['exact'], 'exactly the event cap remains an exact inventory' );
	$write_cron( serialize( $events( 1001 ) ) );
	$sample = $collect();
	$assert( 1000 === $sample['cron']['event_count'] && ! $sample['cron']['exact'] && $sample['cron']['truncated'] && ! $sample['complete'], 'larger cron is a bounded lower bound, not a full count' );
	$assert( null === $sample['cron']['trend'], 'a truncated sample does not manufacture a queue trend' );
	$write_cron( str_repeat( 'X', 1048577 ) );
	$sample = $collect();
	$assert( 'oversized' === $sample['cron']['status'] && 1048577 === $sample['cron']['serialized_bytes'] && ! $sample['cron']['exact'], 'oversized cron is reported without deserialization' );
	$assert( 1 === count( array_filter( $queries, static fn( string $sql ): bool => str_contains( $sql, 'CASE WHEN LENGTH(option_value)' ) ) ), 'size check and conditional read share one race-free query' );
	$write_cron( 'not a serialized cron array' );
	$sample = $collect();
	$assert( 'invalid' === $sample['cron']['status'] && ! $sample['complete'], 'malformed storage never looks like a healthy empty queue' );
	$object_events = $events( 1 );
	$object_events[ time() ]['object_fixture']['object'] = [ 'schedule' => false, 'args' => [ new ShouseDiagnosticsWakeupFixture() ] ];
	$write_cron( serialize( $object_events ) );
	$collect();
	$assert( empty( $GLOBALS['shouse_diagnostics_wakeup'] ), 'cron argument objects cannot run wakeup methods' );
	$write_cron( serialize( $events( 1 ) ) );
	$fail_read = static function ( string $sql ): string {
		return str_contains( $sql, 'CASE WHEN LENGTH(option_value)' ) ? 'SELECT * FROM shouse_missing_diagnostics_fixture' : $sql;
	};
	add_filter( 'query', $fail_read );
	$sample = $collect();
	remove_filter( 'query', $fail_read );
	$assert( 'unavailable' === $sample['cron']['status'] && ! $sample['cron']['exact'], 'database read failure is unavailable, never zero healthy jobs' );

	// An isolated schema fixture exercises the exact adapter without fetching/installing a dependency.
	if ( ! class_exists( 'ActionScheduler', false ) && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions_table ) ) ) && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $claims_table ) ) ) ) {
		class ActionScheduler_DBStore {}
		class ActionScheduler {
			public static function store(): object {
				return new ActionScheduler_DBStore();
			}
		}
		$GLOBALS['wp_actions']['action_scheduler_init'] = 1;
		$tables_made = true;
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (action_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, hook varchar(191) NOT NULL, status varchar(20) NOT NULL, scheduled_date_gmt datetime NOT NULL, args longtext, KEY status_scheduled_date_gmt (status, scheduled_date_gmt))', $actions_table ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (claim_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, date_created_gmt datetime NOT NULL, KEY date_created_gmt (date_created_gmt))', $claims_table ) );
		$sample = $collect();
		$assert( 'ok' === $sample['actions']['status'] && 0 === $sample['actions']['pending']['count'] && $sample['actions']['exact'], 'supported empty Action Scheduler storage is readable' );
		$values = [];
		for ( $i = 0; $i < 1002; ++$i ) {
			$values[] = $wpdb->prepare( '(%s,%s,%s,%s)', 'private_hook', 'pending', gmdate( 'Y-m-d H:i:s', time() - 7200 + $i ), 'SECRET_ACTION_ARGUMENT' );
		}
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (hook,status,scheduled_date_gmt,args) VALUES ', $actions_table ) . implode( ',', $values ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (date_created_gmt) VALUES (%s)', $claims_table, gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		$sample = $collect();
		$assert( 'ok' === $sample['actions']['status'] && 1000 === $sample['actions']['pending']['count'] && $sample['actions']['pending']['truncated'] && ! $sample['actions']['exact'], 'Action Scheduler counts stop at the cap and are labelled lower bounds' );
		$assert( $sample['actions']['oldest_overdue_at'] <= time() - 7190 && $sample['actions']['oldest_claim_at'] <= time() - 590, 'indexed lookups report oldest pending action and claim without claiming they are stuck' );
		$assert( ! str_contains( wp_json_encode( $sample ), 'SECRET_ACTION_ARGUMENT' ), 'Action Scheduler arguments are never selected or returned' );
		$action_queries = array_filter( $queries, static fn( string $sql ): bool => str_contains( $sql, 'actionscheduler_' ) );
		$assert( [] === array_filter( $action_queries, static fn( string $sql ): bool => ! preg_match( '/^(SELECT|SHOW) /', $sql ) || str_contains( $sql, 'COUNT(' ) || str_contains( $sql, 'GROUP BY' ) ), 'Action Scheduler diagnostics perform no writes or unbounded aggregates' );
		$assert( [] === array_filter( $action_queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT ' ) && ( ! str_contains( $sql, 'LIMIT ' ) || ! str_contains( $sql, 'FORCE INDEX' ) ) ), 'Action Scheduler data queries have explicit index and row bounds' );
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX status_scheduled_date_gmt', $actions_table ) );
		$sample = $collect();
		$assert( 'unavailable' === $sample['actions']['status'] && ! $sample['complete'], 'missing required index disables the adapter instead of scanning a queue' );
	}
	$assert( 0 === $http_calls, 'collecting diagnostics makes no HTTP requests' );
	WP_CLI::success( $checks . ' scheduler diagnostics checks passed.' );
} finally {
	remove_filter( 'query', $observe, PHP_INT_MAX );
	if ( isset( $fail_read ) ) {
		remove_filter( 'query', $fail_read );
	}
	remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
	if ( null !== $original_cron ) {
		$write_cron( $original_cron );
	}
	foreach ( [ 'shouse_stability_diagnostics' => $original, 'shouse_stability_heartbeat' => $heartbeat ] as $name => $value ) {
		false === $value ? delete_option( $name ) : update_option( $name, $value, false );
	}
	if ( $tables_made ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i, %i', $actions_table, $claims_table ) );
		unset( $GLOBALS['wp_actions']['action_scheduler_init'] );
	}
}
