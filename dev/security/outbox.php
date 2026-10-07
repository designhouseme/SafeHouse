<?php
/** Local WordPress integration tests: durable delivery, failure recovery and capability inventory. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	exit( 1 );
}
use SafeHouse\Core\Notify;
use SafeHouse\Core\Queue;
use SafeHouse\Modules\Cloudflare;
use SafeHouse\Modules\Watch;

$checks = 0;
$assert = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	WP_CLI::log( 'PASS: ' . $label );
};
global $wpdb;
$table = Queue::table();
$tag = 'shouse-outbox-' . wp_generate_uuid4();
$old_state = get_option( 'shouse_watch_state' );
$mail_ok = false;
$mail_calls = 0;
$mail_filter = static function ( $pre, $mail ) use ( &$mail_ok, &$mail_calls, $tag ) {
	if ( ! str_contains( $mail['subject'] . $mail['message'], $tag ) ) { return $pre; }
	++$mail_calls; return $mail_ok;
};
add_filter( 'pre_wp_mail', $mail_filter, 2000, 2 );
$watch = new Watch();
$user_id = 0;
$role_name = 'shouse_test_sensitive';
$rows = static fn() => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE payload LIKE %s ORDER BY id', $table, '%' . $wpdb->esc_like( $tag ) . '%' ), ARRAY_A );
$due = static function () use ( $wpdb, $table, $tag ) { $wpdb->query( $wpdb->prepare( 'UPDATE %i SET available_at = 0, locked_until = 0 WHERE payload LIKE %s', $table, '%' . $wpdb->esc_like( $tag ) . '%' ) ); };
try {
	$assert( Notify::send( $tag, [ 'Local delivery fixture' ] ), 'failed mail transport still durably accepts an alert' );
	Queue::run( 'mail', 20 ); // Earlier fixture alerts may be ahead of this one on a reused local site.
	$pending = $rows();
	$assert( 1 === count( $pending ) && 1 === (int) $pending[0]['attempts'], 'failed alert retained with retry count' );
	$assert( (int) $pending[0]['available_at'] > time(), 'failed alert waits before retry' );
	$calls = $mail_calls;
	Queue::run( 'mail' );
	$assert( $calls === $mail_calls, 'worker does not flood the failed transport' );
	$assert( 'recommended' === Queue::health()['status'], 'Site Health shows pending delivery failure' );
	$mail_ok = true;
	$due();
	Queue::run( 'mail' );
	$assert( [] === $rows(), 'transport recovery removes acknowledged alert' );

	$once = 0;
	Queue::handler( 'fixture', static function ( array $payload ) use ( &$once, $tag ) {
		++$once;
		if ( 1 === $once ) { Queue::add( 'fixture', [ 'tag' => $tag ] ); }
		return true;
	} );
	Queue::add( 'fixture', [ 'tag' => $tag ] );
	Queue::run( 'fixture' );
	$assert( 2 === $once && [] === $rows(), 'same payload queued during delivery is not lost' );
	Queue::add( 'fixture', [ 'tag' => $tag ] );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET locked_until = %d WHERE queue = %s', $table, time() + 600, 'fixture' ) );
	Queue::run( 'fixture' );
	$assert( 2 === $once, 'another worker cannot take a live lease' );
	$due();
	Queue::run( 'fixture' );
	$assert( 3 === $once && [] === $rows(), 'crashed worker lease is recoverable' );
	$assert( Queue::reserve( $tag, 30 ) && ! Queue::reserve( $tag, 30 ), 'rate slot has one atomic winner' );

	$watch->accept();
	$baseline = get_option( 'shouse_watch_state' );
	$baseline['config_hash'] = 'changed-' . $tag;
	update_option( 'shouse_watch_state', $baseline, false );
	$block_insert = static fn( $sql ) => str_contains( $sql, 'INSERT INTO `' . $table . '`' ) ? str_replace( '`' . $table . '`', '`missing_shouse_queue_fixture`', $sql ) : $sql;
	$old_errors = $wpdb->suppress_errors( true );
	add_filter( 'query', $block_insert );
	$changes = $watch->check();
	remove_filter( 'query', $block_insert );
	$wpdb->suppress_errors( $old_errors );
	$assert( in_array( 'wp-config.php changed', $changes, true ), 'inventory change found during storage outage' );
	$assert( $baseline === get_option( 'shouse_watch_state' ), 'failed enqueue does not advance inventory baseline' );
	$mail_ok = false;
	$watch->check();
	$assert( $baseline !== get_option( 'shouse_watch_state' ), 'durable retry allows inventory baseline to advance' );

	$user_id = wp_insert_user( [ 'user_login' => $tag, 'user_email' => $tag . '@example.test', 'user_pass' => wp_generate_password( 40 ), 'role' => 'subscriber' ] );
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
	$watch->accept();
	$user = new WP_User( $user_id );
	$user->add_cap( 'install_plugins' );
	$state = $watch->snapshot();
	$assert( in_array( 'install_plugins', $state['privileges'][ $user_id ]['caps'], true ), 'individual dangerous capability detected' );
	$assert( (bool) array_filter( $rows(), static fn( $r ) => str_contains( $r['payload'], 'Privileges granted' ) ), 'individual grant produced a durable alert' );
	add_role( $role_name, 'Fixture sensitive role', [ 'read' => true, 'promote_users' => true ] );
	$user->set_role( $role_name );
	$assert( in_array( 'promote_users', $watch->snapshot()['privileges'][ $user_id ]['caps'], true ), 'custom role capabilities detected' );
	get_role( $role_name )->add_cap( 'manage_options' );
	$assert( in_array( 'manage_options', $watch->snapshot()['privileges'][ $user_id ]['caps'], true ), 'role definition escalation detected' );
	$user->add_cap( 'manage_options', false );
	$assert( ! in_array( 'manage_options', $watch->snapshot()['privileges'][ $user_id ]['caps'], true ), 'explicit user denial overrides role grant' );

	defined( 'SHOUSE_CLOUDFLARE_TOKEN' ) || define( 'SHOUSE_CLOUDFLARE_TOKEN', 'local-fixture-token' );
	defined( 'SHOUSE_CLOUDFLARE_ZONE' ) || define( 'SHOUSE_CLOUDFLARE_ZONE', str_repeat( 'a', 32 ) );
	$cf = new Cloudflare();
	$cf->boot();
	$transport = 'timeout';
	$http_calls = 0;
	$enqueue_during = false;
	$http = static function ( $pre, $args, $url ) use ( &$transport, &$http_calls, &$enqueue_during, $tag, $assert ) {
		if ( ! str_contains( $url, '/purge_cache' ) ) { return $pre; }
		++$http_calls;
		if ( $enqueue_during ) {
			$enqueue_during = false;
			Queue::add( 'cloudflare', [ 'zone' => SHOUSE_CLOUDFLARE_ZONE, 'batch' => [ '*' => true, home_url( '/' . $tag . '-during' ) => true ] ] );
		}
		$assert( 0 === $args['redirection'], 'authenticated purge forbids redirects' );
		if ( 'timeout' === $transport ) { return new WP_Error( 'timeout', 'Fixture timeout' ); }
		return [ 'headers' => [], 'body' => wp_json_encode( [ 'success' => 'success' === $transport ] ), 'response' => [ 'code' => 'success' === $transport ? 200 : (int) $transport ] ];
	};
	add_filter( 'pre_http_request', $http, 2000, 3 );
	$rate_key = 'shouse_queue_rate_' . hash( 'sha256', 'cloudflare:' . SHOUSE_CLOUDFLARE_ZONE );
	$free_slot = static function () use ( $wpdb, $rate_key ) { $wpdb->delete( $wpdb->options, [ 'option_name' => $rate_key ] ); };
	$free_slot();
	$assert( '' === $cf->purge( [ home_url( '/' . $tag ) => true ] ), 'purge is durably accepted before timeout' );
	$purges = static fn() => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE queue = %s AND payload LIKE %s', $table, 'cloudflare', '%' . $wpdb->esc_like( $tag ) . '%' ), ARRAY_A );
	$assert( 1 === count( $purges() ), 'timeout keeps the purge queued' );
	foreach ( [ '429', '500', 'success' ] as $transport ) {
		$due(); $free_slot(); Queue::run( 'cloudflare' );
		$assert( ( 'success' === $transport ? 0 : 1 ) === count( $purges() ), 'purge retry after ' . $transport );
	}
	Queue::add( 'cloudflare', [ 'zone' => SHOUSE_CLOUDFLARE_ZONE, 'batch' => [ '*' => true, home_url( '/' . $tag . '-before-one' ) => true ] ] );
	Queue::add( 'cloudflare', [ 'zone' => SHOUSE_CLOUDFLARE_ZONE, 'batch' => [ '*' => true, home_url( '/' . $tag . '-before-two' ) => true ] ] );
	$enqueue_during = true;
	$free_slot(); Queue::run( 'cloudflare', 1 );
	$remaining = $purges();
	$assert( 1 === count( $remaining ) && str_contains( $remaining[0]['payload'], '-during' ), 'whole-zone acknowledgement covers old jobs and retains changes arriving during delivery' );
	$free_slot(); $due(); Queue::run( 'cloudflare', 1 );
	$assert( [] === $purges(), 'the concurrent change receives its own later purge' );
	remove_filter( 'pre_http_request', $http, 2000 );
	WP_CLI::success( $checks . ' durable-delivery and privilege checks passed.' );
} finally {
	$mail_ok = true;
	if ( $user_id && ! is_wp_error( $user_id ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user_id ); }
	remove_role( $role_name );
	update_option( 'shouse_watch_state', $old_state, false );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE payload LIKE %s', $table, '%' . $wpdb->esc_like( $tag ) . '%' ) );
	remove_filter( 'pre_wp_mail', $mail_filter, 2000 );
}
