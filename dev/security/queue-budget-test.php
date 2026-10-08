<?php
/** Cooperative queue budgets, poison-job retention, owned leases and scoped mail limits. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) { exit( 1 ); }
use SafeHouse\Core\Queue;
use SafeHouse\Core\Notify;

$checks = 0;
$assert = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	WP_CLI::log( 'PASS: ' . $label );
};
global $wpdb;
$table = Queue::table();
$prefix = 'qb_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
$queue_a = $prefix . '_a';
$queue_b = $prefix . '_b';
$worker_key = 'shouse_queue_worker';
$old_worker = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $worker_key ) );
$old_memory = ini_get( 'memory_limit' );
$called = 0;
$callback = static function () use ( &$called ) { ++$called; return true; };
Queue::handler( $queue_a, $callback );
Queue::handler( $queue_b, $callback );
$row = static fn( $id ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
$add = static function ( $queue ) use ( $wpdb, $table ): int {
	if ( ! Queue::add( $queue, [ 'fixture' => true ] ) ) { throw new RuntimeException( 'Could not enqueue fixture job.' ); }
	// Scheduling may itself insert an option; get the most recent job for this unique queue instead.
	$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE queue = %s', $table, $queue ) );
	return $id;
};
$due = static function ( $id ) use ( $wpdb, $table ): void {
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET available_at = 0, locked_until = 0 WHERE id = %d', $table, $id ) );
};
$forget = static function ( $id ) use ( $wpdb, $table ): void { $wpdb->delete( $table, [ 'id' => $id ] ); };
$set_worker = static function ( $owner ) use ( $wpdb, $worker_key ): void {
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $worker_key, $owner ) );
};
$clear_worker = static function () use ( $wpdb, $worker_key ): void { $wpdb->delete( $wpdb->options, [ 'option_name' => $worker_key ] ); };
try {
	$clear_worker();
	$queue_reads = 0;
	$observe = static function ( $sql ) use ( &$queue_reads, $table ) {
		if ( str_starts_with( ltrim( $sql ), 'SELECT' ) && str_contains( $sql, $table ) ) { ++$queue_reads; }
		return $sql;
	};
	add_filter( 'query', $observe );
	Queue::add( $queue_a, [ 'fixture' => true ] );
	remove_filter( 'query', $observe );
	$assert( 0 === $queue_reads, 'enqueue schedules a known due time without querying the queue backlog' );
	Queue::run( $queue_a );
	$called = 0;
	$ids = [];
	foreach ( [ $queue_a, $queue_b, $queue_a, $queue_b ] as $queue ) { $ids[] = $add( $queue ); $due( end( $ids ) ); }
	Queue::run( '', 2 );
	$assert( 2 === $called, 'two-job budget is global across handlers' );
	foreach ( $ids as $id ) { $forget( $id ); }

	$called = 0;
	$id = $add( $queue_a );
	$set_worker( ( time() + 600 ) . ':other-worker' );
	Queue::run( $queue_a );
	$assert( 0 === $called && 0 === (int) $row( $id )['attempts'], 'a live global worker lease prevents a second worker' );
	$set_worker( ( time() - 1 ) . ':crashed-worker' );
	Queue::run( $queue_a );
	$assert( 1 === $called && null === $row( $id ), 'expired global worker lease recovers automatically' );

	$called = 0;
	$successor = ( time() + 600 ) . ':successor';
	Queue::handler( $queue_a, static function () use ( &$called, $set_worker, $successor ) { ++$called; $set_worker( $successor ); return true; } );
	$id = $add( $queue_a ); $id2 = $add( $queue_a );
	Queue::run( $queue_a );
	$assert( 1 === $called && $successor === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $worker_key ) ), 'worker cannot renew or release a successor lease' );
	$forget( $id ); $forget( $id2 ); $clear_worker();

	$called = 0;
	Queue::handler( $queue_a, static function () use ( &$called, $row, &$id, $assert ) {
		++$called;
		$state = $row( $id );
		$assert( $called === (int) $state['attempts'] && (int) $state['locked_until'] > time(), 'attempt is durable before callback entry' );
		return new WP_Error( 'fixture_delivery', 'Fixture unavailable transport.' );
	} );
	$id = $add( $queue_a );
	for ( $attempt = 0; $attempt < 7; ++$attempt ) { $due( $id ); Queue::run( $queue_a ); }
	$assert( 5 === $called && 1 === (int) $row( $id )['paused'] && 5 === (int) $row( $id )['attempts'], 'five failures retain and pause the job without an endless retry' );
	$before_paused = Queue::status()['paused'];
	$assert( 1 === Queue::resume( 1 ) && $before_paused - 1 === Queue::status()['paused'], 'explicit resume is bounded to one retained job' );
	$assert( 0 === (int) $row( $id )['attempts'] && 0 === (int) $row( $id )['paused'], 'resume starts a new explicit retry budget' );
	$forget( $id );

	$called = 0;
	Queue::handler( $queue_a, $callback );
	$id = $add( $queue_a );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = 5, available_at = 0, locked_until = 0, lock_token = %s WHERE id = %d', $table, 'crashed-fifth-attempt', $id ) );
	Queue::run( $queue_a );
	$assert( 0 === $called && 1 === (int) $row( $id )['paused'], 'fifth fatal attempt is paused on recovery without calling poison code again' );
	$forget( $id );

	Queue::handler( $queue_a, static fn() => new WP_Error( 'fixture_deferred', 'Waiting for transport rate slot.', [ 'deferred' => true, 'retry_after' => 30 ] ) );
	$id = $add( $queue_a );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts = 4 WHERE id = %d', $table, $id ) );
	for ( $attempt = 0; $attempt < 6; ++$attempt ) { $due( $id ); Queue::run( $queue_a ); }
	$assert( 4 === (int) $row( $id )['attempts'] && 0 === (int) $row( $id )['paused'], 'rate deferrals do not consume the failure budget' );
	$forget( $id );

	$id = $add( $queue_a );
	$unknown = $prefix . '_missing';
	$wpdb->update( $table, [ 'queue' => $unknown ], [ 'id' => $id ] );
	Queue::run( $unknown );
	$assert( 1 === (int) $row( $id )['paused'] && 0 === (int) $row( $id )['attempts'], 'missing handler pauses its retained job without repeated dispatch' );
	$assert( 0 === Queue::resume( 20 ), 'explicit resume cannot reactivate an unavailable handler' );
	$forget( $id );

	$called = 0;
	Queue::handler( $queue_a, $callback );
	$id = $add( $queue_a );
	$wpdb->update( $table, [ 'payload' => '{broken' ], [ 'id' => $id ] );
	Queue::run( $queue_a );
	$assert( 0 === $called && 1 === (int) $row( $id )['paused'], 'malformed payload is retained immediately for operator review' );
	$forget( $id );

	$id = $add( $queue_a );
	$memory = (int) ceil( memory_get_usage( true ) / 1048576 ) + 1;
	$changed = ini_set( 'memory_limit', $memory . 'M' );
	if ( false === $changed ) { throw new RuntimeException( 'Fixture cannot lower PHP memory_limit.' ); }
	Queue::run( $queue_a );
	ini_set( 'memory_limit', (string) $old_memory );
	$assert( 0 === $called && 0 === (int) $row( $id )['attempts'], 'worker leaves jobs untouched when memory headroom is exhausted' );
	$forget( $id );

	Queue::handler( $queue_a, static function () use ( &$called ) { ++$called; usleep( 5200000 ); return true; } );
	$id = $add( $queue_a ); $id2 = $add( $queue_a );
	Queue::run( $queue_a );
	$assert( 1 === $called && null === $row( $id ) && 0 === (int) $row( $id2 )['attempts'], 'expired time budget does not begin another callback' );
	$forget( $id ); $forget( $id2 );

	// Real PHPMailer setup with a local fake send method: no sockets or external mail.
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	$old_mailer = $GLOBALS['phpmailer'] ?? null;
	$old_pre_mail = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
	$mailer = new class( true ) extends PHPMailer\PHPMailer\PHPMailer {
		public array $observed = [];
		public function send() {
			$smtp = $this->getSMTPInstance();
			$this->observed[] = [ $this->Timeout, $smtp->Timeout, $smtp->Timelimit ];
			return true;
		}
	};
	$mailer->Timeout = 31;
	$mailer->getSMTPInstance()->Timeout = 32;
	$mailer->getSMTPInstance()->Timelimit = 33;
	$GLOBALS['phpmailer'] = $mailer;
	remove_all_filters( 'pre_wp_mail' );
	$fixture_from = static fn() => 'fixture@example.test';
	add_filter( 'wp_mail_from', $fixture_from, PHP_INT_MAX );
	try {
		$assert( true === Notify::deliver( [ 'to' => 'fixture@example.test', 'subject' => 'Bounded fixture', 'body' => 'No delivery' ] ), 'alert transport uses the actual WordPress mail lifecycle' );
		$assert( [ 5, 5, 5 ] === $mailer->observed[0], 'own SMTP delivery uses bounded connection and command timeouts' );
		$assert( [ 31, 32, 33 ] === [ $mailer->Timeout, $mailer->getSMTPInstance()->Timeout, $mailer->getSMTPInstance()->Timelimit ], 'mailer and SMTP settings are restored after own delivery' );
		wp_mail( 'fixture@example.test', 'Unrelated mail', 'No delivery' );
		$assert( [ 31, 32, 33 ] === $mailer->observed[1], 'unrelated WordPress mail keeps its original timeout configuration' );
	} finally {
		remove_filter( 'wp_mail_from', $fixture_from, PHP_INT_MAX );
		$GLOBALS['phpmailer'] = $old_mailer;
		if ( null !== $old_pre_mail ) { $GLOBALS['wp_filter']['pre_wp_mail'] = $old_pre_mail; } else { unset( $GLOBALS['wp_filter']['pre_wp_mail'] ); }
	}
	WP_CLI::success( $checks . ' queue budget checks passed.' );
} finally {
	ini_set( 'memory_limit', (string) $old_memory );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE queue LIKE %s', $table, $wpdb->esc_like( $prefix ) . '%' ) );
	$clear_worker();
	if ( null !== $old_worker ) { $set_worker( $old_worker ); }
}
