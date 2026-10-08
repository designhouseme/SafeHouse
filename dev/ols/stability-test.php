<?php
/** Real WooCommerce/Action Scheduler integration; local disposable sites only. */

use SafeHouse\Core\Queue;
use SafeHouse\Core\RuntimeMonitor;
use SafeHouse\Core\StabilityDiagnostics;
use SafeHouse\Plugin;

// Loaded first through WP-CLI --require, before WordPress. Block every HTTP/mail
// transport during bootstrap and shutdown as well as during the assertions.
if ( defined( 'WP_CLI' ) && WP_CLI && ! function_exists( 'add_filter' ) ) {
	define( 'SHOUSE_STABILITY_PILOT_BOOTSTRAP', true );
	$GLOBALS['shouse_stability_pilot_http'] = 0;
	foreach ( [
		'pre_http_request' => static function () {
			++$GLOBALS['shouse_stability_pilot_http'];
			return new WP_Error( 'stability_pilot_no_http' );
		},
		'pre_wp_mail' => static fn() => true,
		'action_scheduler_allow_async_request_runner' => static fn() => false,
	] as $hook => $callback ) {
		$GLOBALS['wp_filter'][ $hook ][ PHP_INT_MAX ][] = [ 'function' => $callback, 'accepted_args' => 0 ];
	}
	return;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'SHOUSE_STABILITY_PILOT_BOOTSTRAP' ) || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
	throw new RuntimeException( 'Use stability-test.sh on a disposable local site with visitor-triggered cron disabled.' );
}
if ( ! class_exists( 'WooCommerce', false ) || ! class_exists( 'ActionScheduler', false ) || ! did_action( 'action_scheduler_init' ) ) {
	throw new RuntimeException( 'Real WooCommerce and its initialized Action Scheduler are required.' );
}

global $wpdb;
$store       = ActionScheduler::store();
$module      = Plugin::instance()->module( 'stability' );
$checks      = 0;
$ids         = [];
$claim       = null;
$mail_id     = 0;
$mail_ids    = [];
$mail_calls  = [];
$mail_ok     = false;
$queries     = [];
$executions  = 0;
$group       = 'shouse-stability-pilot-' . wp_generate_uuid4();
$hook        = 'shouse_stability_pilot_' . str_replace( '-', '', wp_generate_uuid4() );
$private     = 'PILOT_PRIVATE_ARGUMENT_' . wp_generate_uuid4();
$payload     = [ 'private' => $private, 'url' => 'https://example.invalid/private?token=' . $private, 'padding' => str_repeat( 'x', 400 ) ];
$tables      = [ 'actions' => 'action_id', 'claims' => 'claim_id', 'groups' => 'group_id', 'logs' => 'log_id' ];
$before_opts = [];
$before_rows = [];
$hourly      = $GLOBALS['wp_filter']['shouse_hourly'] ?? null;
$http_before = (int) $GLOBALS['shouse_stability_pilot_http'];
$assert      = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	WP_CLI::log( 'PASS: ' . $label );
};
$snapshot = static function () use ( $wpdb, $tables ): array {
	$result = [];
	foreach ( $tables as $name => $key ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT 2001', $wpdb->prefix . 'actionscheduler_' . $name, $key ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 2000 ) {
			throw new RuntimeException( 'This fixture requires a small disposable Action Scheduler database.' );
		}
		$result[ $name ] = hash( 'sha256', serialize( $rows ) );
	}
	return $result;
};
$observe = static function ( string $sql ) use ( &$queries ): string {
	$queries[] = $sql;
	return $sql;
};
$capture = static function ( $result, array $mail ) use ( &$mail_calls, &$mail_ok ): bool {
	$mail_calls[] = $mail;
	return $mail_ok;
};
$ran = static function () use ( &$executions ): void {
	++$executions;
};

// A fresh WooCommerce installation temporarily uses HybridStore until its normal
// legacy migration completes. Keep that lifecycle real; never substitute a store.
if ( 'prepare' === ( $args[0] ?? '' ) ) {
	( new ReflectionProperty( RuntimeMonitor::class, 'finished' ) )->setValue( null, true );
	WP_CLI::log( 'INFO: WordPress ' . get_bloginfo( 'version' ) . '; WooCommerce ' . WC_VERSION . '; Action Scheduler ' . ActionScheduler_Versions::instance()->latest_version() . '; PHP ' . PHP_VERSION );
	if ( 'ActionScheduler_HybridStore' === get_class( $store ) ) {
		$previous = get_option( 'shouse_stability_diagnostics', false );
		try {
			$sample = StabilityDiagnostics::collect();
			$assert( 'unsupported' === $sample['actions']['status'] && ! $sample['complete'], 'a real transitional HybridStore is honestly reported unsupported until migration finishes' );
		} finally {
			false === $previous ? delete_option( 'shouse_stability_diagnostics' ) : update_option( 'shouse_stability_diagnostics', $previous, false );
		}
		$source = new ActionScheduler_wpPostStore();
		$assert( count( $source->query_actions( [ 'per_page' => 1001 ] ) ) < 1000, 'only a small disposable legacy queue may be migrated by this pilot' );
		( new Action_Scheduler\WP_CLI\Migration_Command() )->migrate( [], [ 'batch-size' => 100 ] );
	} else {
		$assert( 'ActionScheduler_DBStore' === get_class( $store ), 'the real Action Scheduler migration is already complete' );
	}
	return;
}

$assert( 'ActionScheduler_DBStore' === get_class( $store ), 'real WooCommerce uses the supported Action Scheduler DB store' );
$assert( false !== has_action( 'shouse_hourly', [ $module, 'scheduled_scan' ] ), 'Stability is registered on the hourly cron hook' );
$assert( '0' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE queue = %s', Queue::table(), 'mail' ) ), 'the disposable mail queue is empty before the pilot' );
$assert( ! get_option( 'shouse_queue_worker', false ), 'no other SafeHouse worker owns the disposable queue' );
$baseline = $snapshot();
foreach ( [ 'cron', 'shouse_stability_diagnostics', 'shouse_stability_heartbeat', 'shouse_stability_alerted', 'shouse_stability_write_after', 'shouse_queue_storage_error', 'shouse_queue_worker', 'shouse_queue_rate_' . hash( 'sha256', 'stability-alert' ) ] as $name ) {
	$before_opts[ $name ] = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A );
}
$incident_table = $wpdb->prefix . 'shouse_incidents';
$before_rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY slot LIMIT 257', $incident_table ), ARRAY_A );
$assert( '' === $wpdb->last_error && is_array( $before_rows ) && count( $before_rows ) <= 256, 'incident backup is readable and bounded to the fixed storage slots' );
// This long-running helper must not add its own shutdown observation after cleanup.
( new ReflectionProperty( RuntimeMonitor::class, 'finished' ) )->setValue( null, true );

try {
	add_filter( 'pre_wp_mail', $capture, PHP_INT_MAX, 2 );
	add_action( $hook, $ran );
	add_action( $hook . '_running', $ran );
	$now    = time();
	$ids[]  = as_schedule_single_action( $now - 7200, $hook, $payload, $group );
	$ids[]  = as_schedule_single_action( $now + HOUR_IN_SECONDS, $hook, [ 'future' => $private ], $group );
	$ids[]  = as_schedule_single_action( $now - 7100, $hook . '_failed', $payload, $group );
	$ids[]  = as_schedule_single_action( $now - 600, $hook . '_running', $payload, $group );
	$assert( 4 === count( array_filter( $ids ) ), 'real Action Scheduler APIs create isolated pending, failed and claimed fixtures' );
	$store->mark_failure( $ids[2] );
	$claim = $store->stake_claim( 1, new DateTime( 'now', new DateTimeZone( 'UTC' ) ), [ $hook . '_running' ], $group );
	$assert( [ $ids[3] ] === array_map( 'intval', $claim->get_actions() ), 'the real claim API claims only the fixture action' );
	$store->log_execution( $ids[3] );
	$wpdb->update( $wpdb->prefix . 'actionscheduler_claims', [ 'date_created_gmt' => gmdate( 'Y-m-d H:i:s', $now - 600 ) ], [ 'claim_id' => $claim->get_id() ] );
	$assert( true === wp_schedule_single_event( $now - 7200, $hook, [ $private ], true ), 'WordPress stores a separate overdue cron fixture' );
	$assert( strlen( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT extended_args FROM %i WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_actions', $ids[0] ) ) ) > 191, 'the real store exercises extended arguments rather than a simplified schema' );
	$before       = $snapshot();
	$cron_before  = get_option( 'cron' );
	$expected     = [];
	foreach ( [ 'pending' => 'pending', 'running' => 'in-progress', 'failed' => 'failed' ] as $label => $status ) {
		$expected[ $label ] = count( as_get_scheduled_actions( [ 'status' => $status, 'per_page' => 1001 ], 'ids' ) );
		$assert( $expected[ $label ] < 1000, 'the pilot ' . $label . ' queue fits an exact bounded comparison' );
	}
	add_filter( 'query', $observe, PHP_INT_MAX );
	$started = hrtime( true );
	$sample  = StabilityDiagnostics::collect();
	$elapsed = ( hrtime( true ) - $started ) / 1000000;
	remove_filter( 'query', $observe, PHP_INT_MAX );
	$assert( 'ok' === $sample['actions']['status'] && $sample['actions']['exact'] && $sample['complete'], 'the production adapter accepts the real WooCommerce schema and indexes' );
	foreach ( $expected as $label => $count ) {
		$assert( $count === $sample['actions'][ $label ]['count'], 'diagnostic ' . $label . ' count matches the bounded public API query' );
	}
	$assert( $sample['actions']['oldest_overdue_at'] > 0 && $sample['actions']['oldest_overdue_at'] <= $now - 7200 && $sample['actions']['oldest_claim_at'] > 0 && $sample['actions']['oldest_claim_at'] <= $now - 600, 'the adapter observes real overdue actions and claims without calling them stuck' );
	$assert( $sample['cron']['disabled'] && $sample['cron']['overdue_count'] >= 1 && $sample['cron']['oldest_overdue_at'] > 0 && $sample['cron']['oldest_overdue_at'] <= $now - 7200, 'disabled visitor cron and overdue work are reported separately' );
	$assert( ! str_contains( wp_json_encode( $sample ), $private ) && ! str_contains( wp_json_encode( $sample ), 'https://example.invalid' ), 'neither cron nor extended Action Scheduler payloads enter the saved snapshot' );
	$as_queries = array_filter( $queries, static fn( string $sql ): bool => str_contains( $sql, 'actionscheduler_' ) );
	$assert( count( $as_queries ) >= 7 && [] === array_filter( $as_queries, static fn( string $sql ): bool => ! preg_match( '/^(SELECT|SHOW) /', $sql ) || preg_match( '/\b(?:args|extended_args|COUNT|GROUP BY)\b/i', $sql ) ), 'real scheduler diagnostics only read metadata and never fetch arguments or aggregate the whole queue' );
	$assert( [] === array_filter( $as_queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT ' ) && ( ! str_contains( $sql, 'FORCE INDEX' ) || ! str_contains( $sql, 'LIMIT ' ) ) ), 'all real scheduler data reads retain explicit index and row bounds' );
	$assert( $before === $snapshot() && $cron_before === get_option( 'cron' ) && 0 === $executions, 'collecting preserves all real scheduler rows, claims, logs, arguments and cron events without running callbacks' );
	$assert( $sample === StabilityDiagnostics::cached(), 'the admin-facing cached snapshot matches the successful real scan' );
	WP_CLI::log( sprintf( 'INFO: one local explicit scheduler scan took %.2f ms across %d SQL statements; this is not a request-overhead benchmark.', $elapsed, count( $queries ) ) );

	delete_option( 'shouse_stability_alerted' );
	delete_option( 'shouse_queue_rate_' . hash( 'sha256', 'stability-alert' ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, '0', 'shouse_stability_write_after' ) );
	$assert( RuntimeMonitor::record( 'fatal', 'cron', 'plugin:stabilitypilot', 1, 1000, MB_IN_BYTES ), 'a local incident can trigger the real Stability alert path' );
	// Dispatch the real registered hourly callback in isolation: unrelated hourly
	// modules must not reconcile inventories or make network calls for this pilot.
	$GLOBALS['wp_filter']['shouse_hourly'] = new WP_Hook();
	add_action( 'shouse_hourly', [ $module, 'scheduled_scan' ] );
	do_action( 'shouse_hourly' );
	$heartbeat = (int) get_option( 'shouse_stability_heartbeat' );
	$assert( $heartbeat >= $now && $heartbeat === StabilityDiagnostics::cached()['cron']['heartbeat_at'], 'the actual hourly callback updates the heartbeat and persisted scheduler snapshot' );
	$mail_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE queue = %s LIMIT 2', Queue::table(), 'mail' ) );
	$mail_id  = 1 === count( $mail_ids ) ? (int) $mail_ids[0] : 0;
	$assert( $mail_id > 0 && [] === $mail_calls, 'the hourly callback queues its PHP failure alert without inline mail transport' );
	do_action( 'shouse_hourly' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE queue = %s', Queue::table(), 'mail' ) ), 'another hourly dispatch does not duplicate the same alert summary' );
	do_action( 'shouse_queue', 'mail' );
	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT attempts, paused, available_at FROM %i WHERE id = %d', Queue::table(), $mail_id ), ARRAY_A );
	$assert( 1 === count( $mail_calls ) && is_array( $job ) && 1 === (int) $job['attempts'] && 0 === (int) $job['paused'] && (int) $job['available_at'] > time(), 'the cron queue callback retains and backs off locally rejected mail' );
	$assert( ! str_contains( wp_json_encode( $mail_calls ), $private ) && str_contains( $mail_calls[0]['subject'], 'PHP failures recorded' ), 'the actual notification contains the summary without scheduler payloads' );
	$wpdb->update( Queue::table(), [ 'available_at' => 0 ], [ 'id' => $mail_id ] );
	$mail_ok = true;
	do_action( 'shouse_queue', 'mail' );
	$assert( 2 === count( $mail_calls ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', Queue::table(), $mail_id ) ), 'the following cron callback delivers through the local mock and acknowledges its own job' );
	$assert( $before === $snapshot() && 0 === $executions, 'hourly scans and SafeHouse delivery also leave WooCommerce work unchanged' );
	$assert( $http_before === (int) $GLOBALS['shouse_stability_pilot_http'], 'the pilot scan and delivery paths make no HTTP requests' );
} finally {
	remove_filter( 'query', $observe, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $capture, PHP_INT_MAX );
	remove_action( $hook, $ran );
	remove_action( $hook . '_running', $ran );
	if ( null === $hourly ) {
		unset( $GLOBALS['wp_filter']['shouse_hourly'] );
	} else {
		$GLOBALS['wp_filter']['shouse_hourly'] = $hourly;
	}
	foreach ( array_filter( $ids ) as $id ) {
		$store->delete_action( $id );
	}
	if ( null !== $claim ) {
		$store->release_claim( $claim );
	}
	$wpdb->delete( $wpdb->prefix . 'actionscheduler_groups', [ 'slug' => $group ] );
	foreach ( $mail_ids as $id ) {
		$wpdb->delete( Queue::table(), [ 'id' => (int) $id ] );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $incident_table ) );
	foreach ( $before_rows as $row ) {
		$wpdb->insert( $incident_table, $row );
	}
	foreach ( $before_opts as $name => $row ) {
		$wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
		if ( is_array( $row ) ) {
			$wpdb->insert( $wpdb->options, $row );
		}
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}
$assert( $baseline === $snapshot(), 'cleanup removes only fixture actions, claims, groups and logs and preserves pre-existing scheduler rows' );
$assert( $before_rows === (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY slot LIMIT 257', $incident_table ), ARRAY_A ), 'cleanup restores the previous bounded incident observations' );
$restored = true;
foreach ( $before_opts as $name => $row ) {
	$restored = $restored && $row === $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $name ), ARRAY_A );
}
$assert( $restored, 'cleanup restores cron, diagnostic options and delivery rate state byte-for-byte' );
WP_CLI::success( $checks . ' real WooCommerce/Action Scheduler stability pilot checks passed.' );
