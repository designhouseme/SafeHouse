<?php
/** Atomic/bounded form storage regressions: wp eval-file /tests/security-form-guard-test.php. */

use SafeHouse\Core\FormGuard;
use SafeHouse\Core\Honeypot;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() || ! class_exists( FormGuard::class ) ) {
	throw new RuntimeException( 'Requires a disposable WP_ENVIRONMENT_TYPE=local WordPress with SafeHouse.' );
}

global $wpdb;
$old_prefix = $wpdb->prefix;
$old_errors = $wpdb->suppress_errors;
$options = [ 'shouse_form_guard_db_version', 'shouse_form_guard_install_after' ];
$backup = [];
foreach ( $options as $option ) {
	$backup[$option] = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $option ), ARRAY_A );
}
$wpdb->prefix = $old_prefix . 'form_guard_fixture_';
$table = FormGuard::table();
$messages = [];
$failures = [];
$assert = static function ( bool $passed, string $label ) use ( &$messages, &$failures ): void {
	$messages[] = ( $passed ? 'ok   ' : 'FAIL ' ) . $label;
	if ( ! $passed ) $failures[] = $label;
};
$hash = static fn( string $input ): string => hash( 'sha256', 'form-guard-fixture|' . $input );
$owner = static fn( string $subject, string $stage = 'issue', string $kind = 'browser' ): string => hash( 'sha256', $stage . '|' . $kind . '|' . $subject );
$slot = static fn( string $fingerprint ): int => (int) hexdec( substr( $fingerprint, 0, 7 ) ) % 32768;
$filters = [];
$retry_response = static function ( string $reason, ?int $retry_at ): array {
	// Capture the actual JSON error without terminating WP-CLI or sleeping across a window.
	$stop = new RuntimeException( 'form-guard-retry-response' );
	$die = static function () use ( $stop ): never { throw $stop; };
	$handler = static fn(): Closure => $die;
	add_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
	add_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
	ob_start();
	try {
		( new ReflectionMethod( Honeypot::class, 'unavailable' ) )->invoke( null, $reason, $retry_at );
	} catch ( RuntimeException $error ) {
		if ( $stop !== $error ) throw $error;
	} finally {
		$body = ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true', PHP_INT_MAX );
		remove_filter( 'wp_die_ajax_handler', $handler, PHP_INT_MAX );
	}
	return json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
};
$clear_options = static function () use ( $wpdb, $options ): void {
	foreach ( $options as $option ) {
		$wpdb->delete( $wpdb->options, [ 'option_name' => $option ] );
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};

try {
	$clear_options();
	// Upgrade the initial slot schema, including multiple legacy empty owner values.
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (pool tinyint unsigned NOT NULL, kind tinyint unsigned NOT NULL, slot int unsigned NOT NULL, fingerprint char(64) NOT NULL DEFAULT \'\', expires_at bigint unsigned NOT NULL DEFAULT 0, hits int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (pool,kind,slot))', $table ) );
	$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (pool,kind,slot) VALUES (3,2,32766),(3,2,32767)', $table ) );
	update_option( $options[0], '1', false );
	$assert( FormGuard::maybe_install(), 'first schema installation succeeds' );
	$assert( FormGuard::install() && FormGuard::maybe_install(), 'schema installation is idempotent' );
	$assert( '2' === get_option( $options[0] ), 'new version marker is set after schema and unique owner verification' );
	$assert( '2' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE fingerprint IS NULL', $table ) ), 'legacy empty owners become nullable free slots' );
	$assert( $old_errors === $wpdb->suppress_errors, 'successful installation restores SQL error visibility' );

	foreach ( [ 'register', 'lostpassword', 'comments' ] as $form ) {
		$fingerprint = $hash( 'issue-' . $form );
		$proof = FormGuard::issue( $form, $fingerprint, time() + 1200 );
		$assert( '' === $proof['reason'] && is_int( $proof['slot'] ) && $proof['slot'] >= 0 && $proof['slot'] < 16384, $form . ' proof uses its fixed slot range' );
		$assert( '' === FormGuard::consume( $form, $proof['slot'], $fingerprint ), $form . ' proof is accepted once' );
		$assert( 'replayed' === FormGuard::consume( $form, $proof['slot'], $fingerprint ), $form . ' proof replay is refused' );
	}

	$fingerprint = $hash( 'generation-one' );
	$proof = FormGuard::issue( 'register', $fingerprint, time() + 1200 );
	$assert( 'replayed' === FormGuard::consume( 'comments', $proof['slot'], $fingerprint ), 'another form cannot consume a proof' );
	$assert( 'replayed' === FormGuard::consume( 'register', $proof['slot'], $hash( 'wrong-generation' ) ), 'wrong generation cannot consume a proof' );
	$assert( '' === FormGuard::consume( 'register', $proof['slot'], $fingerprint ), 'incorrect consumers do not destroy the legitimate proof' );
	$replacement = $hash( 'generation-two' );
	$wpdb->update( $table, [ 'fingerprint' => $replacement, 'expires_at' => time() + 1200 ], [ 'pool' => 1, 'kind' => 1, 'slot' => $proof['slot'] ] );
	$assert( 'replayed' === FormGuard::consume( 'register', $proof['slot'], $fingerprint ), 'reusing a consumed slot never revives its previous generation' );
	$assert( '' === FormGuard::consume( 'register', $proof['slot'], $replacement ), 'replacement generation remains usable' );
	$wpdb->update( $table, [ 'expires_at' => time() - 1 ], [ 'pool' => 1, 'kind' => 1, 'slot' => $proof['slot'] ] );
	$assert( 'replayed' === FormGuard::consume( 'register', $proof['slot'], $replacement ), 'expired proof is refused independently of the signed-proof parser' );

	// Interleave a competing operation immediately before the first SQL claim reaches MySQL.
	$fingerprint = $hash( 'interleaved-consumption' );
	$proof = FormGuard::issue( 'comments', $fingerprint, time() + 1200 );
	$competing = null;
	$interleave = static function ( string $query ) use ( &$interleave, &$competing, $table, $fingerprint, $proof ): string {
		if ( str_contains( $query, 'UPDATE `' . $table . '` SET expires_at = 0' ) && str_contains( $query, $fingerprint ) ) {
			remove_filter( 'query', $interleave );
			$competing = FormGuard::consume( 'comments', $proof['slot'], $fingerprint );
		}
		return $query;
	};
	add_filter( 'query', $interleave );
	$filters[] = $interleave;
	$first = FormGuard::consume( 'comments', $proof['slot'], $fingerprint );
	$assert( '' === $competing && 'replayed' === $first, 'interleaved consumers have exactly one winner' );

	$subject = $hash( 'quota-boundary' );
	for ( $i = 0; $i < 3; ++$i ) $assert( '' === FormGuard::budget( 'register', 'issue', 'browser', $subject, 3, 600 ), 'quota accepts allowance ' . ( $i + 1 ) );
	$assert( 'rate_limited' === FormGuard::budget( 'register', 'issue', 'browser', $subject, 3, 600 ), 'quota rejects the first request beyond its boundary' );
	$counter_slot = $slot( $owner( $subject ) );
	$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pool=1 AND kind=2 AND slot=%d', $table, $counter_slot ), ARRAY_A );
	$retry_at = null;
	FormGuard::budget( 'register', 'issue', 'browser', $subject, 3, 600, $retry_at );
	$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pool=1 AND kind=2 AND slot=%d', $table, $counter_slot ), ARRAY_A );
	$assert( $before === $after && '3' === $after['hits'], 'refusals do not increment counters or extend lockout' );
	$assert( (int) $after['expires_at'] === $retry_at, 'rate refusal reports the actual fixed-window deadline stored in its counter' );
	$started = time();
	$response = $retry_response( 'rate_limited', $retry_at );
	$finished = time();
	$retry = $response['data']['retryAfter'] ?? null;
	$assert( false === $response['success'] && 'rate_limited' === $response['data']['reason'] && is_int( $retry ) && $retry >= max( 1, $retry_at - $finished ) && $retry <= max( 1, $retry_at - $started ), 'rate response waits only for the refused counter window to finish' );
	$response = $retry_response( 'rate_limited', time() - 1 );
	$assert( 1 === $response['data']['retryAfter'], 'response delayed past the refused window boundary waits one second rather than a new full window' );
	$response = $retry_response( 'storage', $retry_at );
	$assert( 60 === $response['data']['retryAfter'], 'storage failures retain their independent retry delay' );
	$assert( '' === FormGuard::budget( 'comments', 'issue', 'browser', $subject, 3, 600 ), 'another form keeps an independent quota pool' );
	$assert( '' === FormGuard::budget( 'register', 'submit', 'browser', $subject, 3, 600 ), 'issuance and submission accounting are separate' );
	$assert( '' === FormGuard::budget( 'register', 'issue', 'ip', $subject, 3, 600 ), 'browser and IP accounting are separate' );
	$wpdb->update( $table, [ 'expires_at' => time() - 1 ], [ 'pool' => 1, 'kind' => 2, 'slot' => $counter_slot ] );
	$assert( '' === FormGuard::budget( 'register', 'issue', 'browser', $subject, 3, 600, $retry_at ) && null === $retry_at, 'expired accounting window resets on demand without cron or a stale retry deadline' );
	$assert( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE pool=1 AND kind=2 AND slot=%d', $table, $counter_slot ) ), 'reset window begins with one accepted request' );

	// Find a real deterministic collision; a different subject must never evict the first owner.
	$seen = [];
	$collision = null;
	for ( $i = 0; $i < 10000; ++$i ) {
		$candidate = $hash( 'collision-' . $i );
		$candidate_slot = $slot( $owner( $candidate ) );
		if ( isset( $seen[$candidate_slot] ) ) { $collision = [ $seen[$candidate_slot], $candidate, $candidate_slot ]; break; }
		$seen[$candidate_slot] = $candidate;
	}
	$assert( is_array( $collision ), 'fixture finds two distinct subjects in one deterministic quota slot' );
	if ( is_array( $collision ) ) {
		[ $one, $two, $shared_slot ] = $collision;
		$wpdb->delete( $table, [ 'pool' => 2, 'kind' => 2, 'slot' => $shared_slot ] );
		$assert( '' === FormGuard::budget( 'lostpassword', 'issue', 'browser', $one, 2, 600 ), 'first collision owner receives a counter' );
		$assert( '' === FormGuard::budget( 'lostpassword', 'issue', 'browser', $two, 2, 600 ), 'colliding subject claims a bounded alternate slot' );
		$assert( '' === FormGuard::budget( 'lostpassword', 'issue', 'browser', $one, 2, 600 ), 'collision does not displace the first owner' );
		$assert( 'rate_limited' === FormGuard::budget( 'lostpassword', 'issue', 'browser', $one, 2, 600 ), 'collision cannot reset the first owner quota' );
		$assert( '' === FormGuard::budget( 'lostpassword', 'issue', 'browser', $two, 2, 600 ), 'alternate slot receives the second request for the same subject' );
		$assert( 'rate_limited' === FormGuard::budget( 'lostpassword', 'issue', 'browser', $two, 2, 600 ), 'alternate-slot subject cannot obtain another counter to evade its quota' );
	}

	$subject = $hash( 'interleaved-quota' );
	$owner_hash = $owner( $subject );
	$competing = null;
	$interleave_budget = static function ( string $query ) use ( &$interleave_budget, &$competing, $table, $owner_hash, $subject ): string {
		if ( str_contains( $query, 'UPDATE `' . $table . '` SET hits = ' ) && str_contains( $query, $owner_hash ) ) {
			remove_filter( 'query', $interleave_budget );
			$competing = FormGuard::budget( 'comments', 'issue', 'browser', $subject, 1, 600 );
		}
		return $query;
	};
	add_filter( 'query', $interleave_budget );
	$filters[] = $interleave_budget;
	$first = FormGuard::budget( 'comments', 'issue', 'browser', $subject, 1, 600 );
	$assert( '' === $competing && 'rate_limited' === $first, 'interleaved final-allowance claims have exactly one winner' );
	$assert( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pool=3 AND kind=2 AND fingerprint=%s', $table, $owner_hash ) ), 'interleaved creation leaves exactly one counter for an owner' );

	// A concurrent worker can select another free candidate before this worker performs its claim.
	$subject = $hash( 'different-candidate-race' );
	$owner_hash = $owner( $subject );
	$first_slot = $slot( $owner_hash );
	$other_slot = ( $first_slot + 1 ) % 32768;
	$wpdb->delete( $table, [ 'pool' => 3, 'kind' => 2, 'slot' => $first_slot ] );
	$wpdb->delete( $table, [ 'pool' => 3, 'kind' => 2, 'slot' => $other_slot ] );
	$interleave_other_slot = static function ( string $query ) use ( &$interleave_other_slot, $wpdb, $table, $owner_hash, $other_slot ): string {
		if ( str_contains( $query, 'UPDATE IGNORE `' . $table . '`' ) && str_contains( $query, $owner_hash ) ) {
			remove_filter( 'query', $interleave_other_slot );
			$wpdb->insert( $table, [ 'pool' => 3, 'kind' => 2, 'slot' => $other_slot, 'fingerprint' => $owner_hash, 'hits' => 1, 'expires_at' => time() + 600 ] );
		}
		return $query;
	};
	add_filter( 'query', $interleave_other_slot );
	$filters[] = $interleave_other_slot;
	$assert( 'rate_limited' === FormGuard::budget( 'comments', 'issue', 'browser', $subject, 1, 600 ), 'unique owner constraint rejects a racing claim in another candidate slot' );
	$assert( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pool=3 AND kind=2 AND fingerprint=%s', $table, $owner_hash ) ), 'racing different candidates cannot create duplicate active counters' );

	$rows_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	$assert( 'context' === FormGuard::issue( 'arbitrary-form', $hash('invalid'), time() + 1200 )['reason'], 'arbitrary form names cannot allocate pools' );
	$assert( 'context' === FormGuard::issue( 'register', 'not-a-hash', time() + 1200 )['reason'], 'arbitrary fingerprints cannot allocate rows' );
	$assert( 'context' === FormGuard::issue( 'register', $hash('invalid'), time() + 100000 )['reason'], 'unbounded token expiry is refused' );
	$assert( 'context' === FormGuard::consume( 'register', 16384, $hash('invalid') ), 'out-of-range token slots are refused' );
	$assert( 'context' === FormGuard::budget( 'register', 'arbitrary', 'browser', $subject, 3, 600 ), 'arbitrary accounting stages are refused' );
	$assert( 'context' === FormGuard::budget( 'register', 'issue', 'arbitrary', $subject, 3, 600 ), 'arbitrary accounting kinds are refused' );
	$assert( 'context' === FormGuard::budget( 'register', 'issue', 'browser', '127.0.0.1', 3, 600 ), 'raw addresses are refused as subjects' );
	$assert( $rows_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'invalid context never writes storage' );

	// Fill only fixed ranges, then show that random new subjects cannot add beyond those ranges.
	foreach ( [ 1 => 16384, 2 => 32768 ] as $kind => $size ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE pool=1 AND kind=%d', $table, $kind ) );
		for ( $start = 0; $start < $size; $start += 512 ) {
			$values = [];
			for ( $i = $start; $i < min( $start + 512, $size ); ++$i ) $values[] = $wpdb->prepare( '(%d,%d,%d,%s,%d,1)', 1, $kind, $i, $hash( 'capacity-owner-' . $i ), time() + 1200 );
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (pool,kind,slot,fingerprint,expires_at,hits) VALUES ', $table ) . implode( ',', $values ) );
		}
	}
	$assert( 'capacity' === FormGuard::issue( 'register', $hash('full-pool'), time() + 1200 )['reason'], 'full token pool refuses without overwriting live challenges' );
	for ( $i = 0; $i < 50; ++$i ) {
		$assert( 'capacity' === FormGuard::budget( 'register', 'issue', 'browser', $hash('full-rate-' . $i), 3, 600 ), 'full quota pool refuses new subject ' . $i );
	}
	$assert( '49152' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pool=1', $table ) ), 'arbitrarily many subjects cannot grow one form beyond 49,152 rows' );
	$assert( '' === FormGuard::issue( 'lostpassword', $hash('other-pool'), time() + 1200 )['reason'], 'full registration pool does not exhaust password-reset token pool' );

	$wpdb->prefix .= 'missing_';
	$assert( 'storage' === FormGuard::issue( 'register', $hash('missing'), time() + 1200 )['reason'], 'missing table blocks issuance' );
	$assert( 'storage' === FormGuard::consume( 'register', 0, $hash('missing') ), 'missing table blocks consumption' );
	$assert( 'storage' === FormGuard::budget( 'register', 'issue', 'browser', $hash('missing'), 3, 600 ), 'missing table blocks quotas' );
	$assert( $old_errors === $wpdb->suppress_errors, 'store failures restore prior SQL error visibility' );
	$wpdb->prefix = $old_prefix . 'form_guard_fixture_';
	$write_fault = static fn( string $query ): string => str_contains( $query, 'UPDATE `' . $table . '`' ) ? 'INVALID FORM GUARD WRITE FIXTURE' : $query;
	add_filter( 'query', $write_fault );
	$filters[] = $write_fault;
	$assert( 'storage' === FormGuard::issue( 'comments', $hash('write-denied'), time() + 1200 )['reason'], 'denied token claim blocks issuance' );
	$assert( 'storage' === FormGuard::consume( 'comments', 0, $hash('write-denied') ), 'denied token consumption never passes' );
	$assert( 'storage' === FormGuard::budget( 'comments', 'submit', 'browser', $hash('write-denied'), 3, 600 ), 'denied quota claim never passes' );
	remove_filter( 'query', $write_fault );

	$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	$clear_options();
	$schema_attempts = 0;
	$schema_fault = static function ( string $query ) use ( $table, &$schema_attempts ): string {
		if ( str_contains( $query, 'CREATE TABLE ' . $table ) ) { ++$schema_attempts; return 'INVALID FORM GUARD SCHEMA FIXTURE'; }
		return $query;
	};
	add_filter( 'query', $schema_fault );
	$filters[] = $schema_fault;
	$assert( ! FormGuard::maybe_install(), 'failed schema installation reports failure' );
	$assert( false === get_option( $options[0] ), 'failed schema installation cannot set the version marker' );
	$assert( ! FormGuard::maybe_install() && 1 === $schema_attempts, 'repeated requests cannot flood failed schema creation' );
	remove_filter( 'query', $schema_fault );
} finally {
	foreach ( $filters as $filter ) remove_filter( 'query', $filter );
	$wpdb->suppress_errors( true );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	$wpdb->prefix = $old_prefix;
	$clear_options();
	foreach ( $options as $option ) {
		if ( is_array( $backup[$option] ) ) $wpdb->insert( $wpdb->options, $backup[$option] );
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$wpdb->suppress_errors( $old_errors );
}

foreach ( $messages as $message ) echo $message . "\n";
if ( $failures ) throw new RuntimeException( count( $failures ) . ' form guard regressions failed.' );
echo count( $messages ) . " form guard checks passed.\n";
