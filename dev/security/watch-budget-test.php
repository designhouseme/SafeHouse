<?php
/** Local integration: bounded Watch inventory, targeted changes and overlapping reconciliation. */
// phpcs:ignoreFile -- local-only failure injection and fixture setup.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	exit( 1 );
}
use SafeHouse\Core\Queue;
use SafeHouse\Modules\Watch;
use SafeHouse\Plugin;

global $wpdb;
$watch = Plugin::instance()->module( 'watch' );
if ( ! $watch instanceof Watch ) { throw new RuntimeException( 'Watch must be enabled for this fixture.' ); }
$tag = 'shouse-watch-' . wp_generate_uuid4();
$role = $tag . '-role';
$meta_key = $wpdb->get_blog_prefix() . 'capabilities';
$checks = 0;
$all_users = 0;
$single_users = 0;
$state_writes = 0;
$delay = false;
$inject = null;
$ids = [];
$old_options = [];
foreach ( [ 'shouse_watch_state', 'shouse_watch_scan', 'shouse_watch_lock', 'shouse_watch_epoch', 'cron' ] as $name ) {
	$old_options[ $name ] = get_option( $name );
}
$old_errors = $wpdb->suppress_errors( true );
$assert = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	WP_CLI::log( 'PASS: ' . $label );
};
$query = static function ( string $sql ) use ( &$all_users, &$single_users, &$state_writes, &$delay, &$inject ): string {
	if ( str_starts_with( $sql, 'SELECT u.ID, u.user_login, m.meta_value FROM ' ) && str_contains( $sql, 'u.ID >' ) ) {
		++$all_users;
		if ( $delay ) { usleep( 3000000 ); }
	}
	if ( str_starts_with( $sql, 'SELECT u.ID, u.user_login, m.meta_value FROM ' ) && ! str_contains( $sql, 'u.ID >' ) ) { ++$single_users; }
	if ( preg_match( '/^(UPDATE|INSERT)/', $sql ) && str_contains( $sql, "'shouse_watch_state'" ) ) { ++$state_writes; }
	if ( is_callable( $inject ) ) { $sql = $inject( $sql ); }
	return $sql;
};
$rows = static fn() => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT payload FROM %i WHERE payload LIKE %s ORDER BY id', Queue::table(), '%' . $wpdb->esc_like( $tag ) . '%' ), ARRAY_A );
$payloads = static fn() => implode( "\n", array_column( $rows(), 'payload' ) );
$finish = static function ( bool $accept = false ) use ( $watch ): array {
	$changes = [];
	for ( $i = 0; $i < 20; ++$i ) {
		$result = $accept ? $watch->accept() : $watch->check();
		if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
		if ( is_array( $result ) ) { $changes = array_merge( $changes, $result ); }
		if ( ! get_option( 'shouse_watch_scan' ) ) { return $changes; }
	}
	$job = get_option( 'shouse_watch_scan', [] );
	$lock = get_option( 'shouse_watch_lock', [] );
	throw new RuntimeException( sprintf( 'Fixture inventory failed to finish: cursor=%d upper=%d memory=%d limit=%s claim_remaining=%d.', (int) ( $job['cursor'] ?? -1 ), (int) ( $job['upper'] ?? -1 ), memory_get_usage( true ), ini_get( 'memory_limit' ), (int) ( $lock['until'] ?? 0 ) - time() ) );
};
$set_raw_caps = static function ( int $id, array $caps ) use ( $wpdb, $meta_key ): void {
	$wpdb->update( $wpdb->usermeta, [ 'meta_value' => maybe_serialize( $caps ) ], [ 'user_id' => $id, 'meta_key' => $meta_key ] );
	clean_user_cache( $id );
};

try {
	delete_option( 'shouse_watch_scan' );
	delete_option( 'shouse_watch_lock' );
	$user_id = wp_insert_user( [ 'user_login' => $tag . '-target', 'user_email' => $tag . '@example.invalid', 'user_pass' => wp_generate_password( 40 ), 'role' => 'subscriber' ] );
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
	$ids[] = $user_id;
	$user = new WP_User( $user_id );
	// Bulk seed inactive, non-authenticating customers, outside the production event path under test.
	for ( $batch = 0; $batch < 12; ++$batch ) {
		$values = [];
		for ( $i = 0; $i < 100; ++$i ) {
			$values[] = $wpdb->prepare( '(%s, %s, %s)', $tag . '-' . ( $batch * 100 + $i ), '!', $tag . '-' . ( $batch * 100 + $i ) . '@example.invalid' );
		}
		$wpdb->query( "INSERT INTO {$wpdb->users} (user_login,user_pass,user_email) VALUES " . implode( ',', $values ) );
	}
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->usermeta} (user_id,meta_key,meta_value) SELECT ID,%s,%s FROM {$wpdb->users} WHERE user_login LIKE %s AND ID <> %d", $meta_key, maybe_serialize( [ 'subscriber' => true ] ), $wpdb->esc_like( $tag ) . '%', $user_id ) );
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s ORDER BY ID", $wpdb->esc_like( $tag ) . '%' ) ) );
	$assert( 1201 === count( $ids ), 'fixture contains more accounts than two inventory slices' );
	update_option( 'shouse_watch_state', '', false ); // Existing empty row must not be mistaken for a missing option.
	$finish( true );
	$baseline = get_option( 'shouse_watch_state' );
	$assert( is_array( $baseline ) && ! empty( $baseline['privileges'] ), 'initial inventory replaces an existing empty baseline option instead of repeatedly attempting INSERT' );
	add_filter( 'query', $query );

	$preimages = new ReflectionProperty( Watch::class, 'prior_users' );
	$all_users = $single_users = $state_writes = 0;
	foreach ( array_slice( $ids, 1, 1000 ) as $id ) {
		update_user_meta( $id, $meta_key, [ 'subscriber' => true ] );
	}
	$assert( 0 === count( $preimages->getValue( $watch ) ) && 0 === $single_users && 0 === $all_users && 0 === $state_writes, '1000 unchanged customer writes retain no preimages and perform no Watch reads or baseline writes' );
	$short_circuit = static fn( $check ) => false;
	add_filter( 'update_user_metadata', $short_circuit, 5 );
	foreach ( array_slice( $ids, 1, 1000 ) as $id ) {
		update_user_meta( $id, $meta_key, [ 'subscriber' => true, 'read' => true ] );
	}
	remove_filter( 'update_user_metadata', $short_circuit, 5 );
	$assert( 0 === count( $preimages->getValue( $watch ) ) && 0 === $single_users, '1000 short-circuited metadata writes retain no preimages and do no Watch reads' );
	$watch->before_capability_meta( false, $user_id, $meta_key );
	$assert( 0 === count( $preimages->getValue( $watch ) ), 'a non-null short-circuit value is never captured' );
	$inject = static fn( string $sql ): string => str_starts_with( $sql, 'UPDATE `' . $wpdb->usermeta . '`' ) ? str_replace( '`' . $wpdb->usermeta . '`', '`missing_watch_meta_fixture`', $sql ) : $sql;
	foreach ( array_slice( $ids, 1, 150 ) as $id ) {
		update_user_meta( $id, $meta_key, [ 'subscriber' => true, 'read' => true ] );
	}
	$inject = null;
	$assert( count( $preimages->getValue( $watch ) ) <= 128 && (bool) wp_next_scheduled( 'shouse_watch_continue' ), 'failed metadata writes without after actions leave at most 128 preimages and schedule reconciliation' );

	$all_users = $state_writes = 0;
	$user->add_cap( 'read' );
	$user->remove_cap( 'read' );
	$assert( 0 === $all_users && 0 === $state_writes, 'ordinary customer capability changes scan no other users and never rewrite baseline' );
	$user->add_cap( 'install_plugins' );
	$user->remove_cap( 'install_plugins' );
	$assert( 0 === $all_users, 'temporary direct grant and revocation remain single-user reads' );
	$body = $payloads();
	$assert( str_contains( $body, 'Privileges granted to ' . $tag ) && str_contains( $body, 'Privileges removed from ' . $tag ), 'temporary direct grant and revocation each have durable evidence' );
	$assert( empty( get_option( 'shouse_watch_state' )['privileges'][ $user_id ] ), 'revoked direct grant is absent from accepted baseline' );

	add_role( $role, $tag . ' role', [ 'read' => true ] );
	$user->set_role( $role );
	$all_users = 0;
	get_role( $role )->add_cap( 'promote_users' );
	get_role( $role )->remove_cap( 'promote_users' );
	$assert( 0 === $all_users && str_contains( $payloads(), 'Role privileges granted to ' . $role ) && str_contains( $payloads(), 'Role privileges removed from ' . $role ), 'temporary custom-role escalation and revocation are recorded without enumerating members' );
	$user->set_role( 'subscriber' );
	$finish( true );

	$tail = end( $ids );
	$set_raw_caps( $tail, [ 'subscriber' => true, 'install_plugins' => true ] );
	$baseline = get_option( 'shouse_watch_state' );
	$all_users = 0;
	$watch->check();
	$job = get_option( 'shouse_watch_scan' );
	$assert( $all_users <= 5 && $job['cursor'] > 0 && $job['cursor'] < $tail, 'one invocation checkpoints at most 500 accounts instead of scanning all users' );
	$assert( $baseline === get_option( 'shouse_watch_state' ), 'partial inventory leaves the accepted baseline untouched' );
	$cursor = $job['cursor'];
	$watch->check();
	$assert( get_option( 'shouse_watch_scan' )['cursor'] > $cursor, 'next invocation resumes beyond its previous user cursor' );
	$changes = $finish();
	$assert( (bool) array_filter( $changes, static fn( $change ) => str_contains( $change, 'Privileges granted' ) && str_contains( $change, '(#' . $tail . ')' ) ), 'completed inventory detects direct SQL grant near the end of a large user table' );

	// ABA race: the scan observes a raw grant, then a hook-driven revoke restores its original baseline.
	$set_raw_caps( $user_id, [ 'subscriber' => true, 'install_plugins' => true ] );
	$watch->check();
	$staged = get_option( 'shouse_watch_scan' );
	$assert( in_array( 'install_plugins', $staged['privileges'][ $user_id ]['caps'], true ), 'staged inventory actually observed the soon-to-be-revoked privilege' );
	$user = new WP_User( $user_id );
	$user->remove_cap( 'install_plugins' );
	$epoch_after = get_option( 'shouse_watch_epoch' );
	$assert( $epoch_after !== $staged['epoch'], 'targeted revocation invalidates in-flight inventory even when its baseline returns to the original value' );
	$finish();
	$assert( empty( get_option( 'shouse_watch_state' )['privileges'][ $user_id ] ), 'completed reconciliation cannot resurrect a revoked privilege from its stale checkpoint' );

	// Publication race: mutation occurs after final freshness checks, inside the SQL query filter.
	$watch->check();
	$watch->check();
	$fired = false;
	$inject = static function ( string $sql ) use ( &$inject, &$fired, $user_id ): string {
		if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, ' state JOIN ' ) ) {
			$inject = null;
			$fired = true;
			$account = new WP_User( $user_id );
			$account->add_cap( 'delete_plugins' );
		}
		return $sql;
	};
	$watch->check();
	$assert( $fired && in_array( 'delete_plugins', get_option( 'shouse_watch_state' )['privileges'][ $user_id ]['caps'], true ), 'atomic publication cannot overwrite a targeted grant arriving after the final read' );
	$finish();
	$user = new WP_User( $user_id );
	$user->remove_cap( 'delete_plugins' );

	// Queue failure never acknowledges the new privilege; later reconciliation can retry it.
	$baseline = get_option( 'shouse_watch_state' );
	$inject = static fn( string $sql ): string => str_starts_with( $sql, 'INSERT INTO `' . Queue::table() . '`' ) ? str_replace( '`' . Queue::table() . '`', '`missing_watch_queue_fixture`', $sql ) : $sql;
	$user->add_cap( 'edit_plugins' );
	$assert( $baseline === get_option( 'shouse_watch_state' ), 'failed targeted enqueue preserves the previous baseline' );
	$inject = null;
	$changes = $finish();
	$assert( (bool) array_filter( $changes, static fn( $change ) => str_contains( $change, 'edit_plugins' ) ), 'reconciliation retries the privilege that could not be durably queued' );

	delete_option( 'shouse_watch_scan' );
	$baseline = get_option( 'shouse_watch_state' );
	$all_users = 0;
	$delay = true;
	$watch->check();
	$delay = false;
	$assert( 2 === $all_users && get_option( 'shouse_watch_scan' ) && $baseline === get_option( 'shouse_watch_state' ), 'elapsed-time budget stops slow inventory before the 500-user count cap' );
	delete_option( 'shouse_watch_scan' );

	delete_option( 'shouse_watch_state' );
	$all_users = 0;
	$watch->boot(); // Same object: WordPress replaces its already-registered callbacks.
	$assert( 0 === $all_users && false === get_option( 'shouse_watch_state' ) && (bool) wp_next_scheduled( 'shouse_watch_continue' ), 'new-install bootstrap schedules its baseline without enumerating users' );
	$set_raw_caps( $user_id, [ 'subscriber' => true ] );
	$before_nested = count( $rows() );
	$nested = static function ( $meta_id, $changed_user, $changed_key ) use ( &$nested, $user_id, $meta_key ): void {
		if ( $user_id !== (int) $changed_user || $changed_key !== $meta_key ) { return; }
		remove_action( 'update_user_meta', $nested, 20 );
		$account = new WP_User( $user_id );
		$account->add_cap( 'edit_themes' );
	};
	add_action( 'update_user_meta', $nested, 20, 3 );
	update_user_meta( $user_id, $meta_key, [ 'subscriber' => true, 'read' => true ] );
	remove_action( 'update_user_meta', $nested, 20 );
	$nested_payload = implode( "\n", array_column( array_slice( $rows(), $before_nested ), 'payload' ) );
	$assert( str_contains( $nested_payload, 'Privileges granted' ) && str_contains( $nested_payload, 'Privileges removed' ) && str_contains( $nested_payload, 'edit_themes' ), 'nested grant then outer revoke both remain observable before any baseline exists' );
	$assert( ! array_key_exists( $user_id, $preimages->getValue( $watch ) ), 'completed nested writes release their own preimage' );

	WP_CLI::success( $checks . ' Watch budget and event checks passed.' );
} finally {
	remove_filter( 'query', $query );
	foreach ( $ids as $id ) {
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id ] );
		$wpdb->delete( $wpdb->users, [ 'ID' => $id ] );
		clean_user_cache( $id );
	}
	remove_role( $role );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE payload LIKE %s', Queue::table(), '%' . $wpdb->esc_like( $tag ) . '%' ) );
	foreach ( $old_options as $name => $value ) {
		false === $value ? delete_option( $name ) : update_option( $name, $value, false );
	}
	$wpdb->suppress_errors( $old_errors );
}
