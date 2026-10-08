<?php
/** Local WordPress integration test: incomplete inventory never replaces a Watch baseline. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	exit( 1 );
}

use SafeHouse\Core\Log;
use SafeHouse\Core\Queue;
use SafeHouse\Modules\Watch;

global $wpdb;
$watch       = new Watch();
$path        = $args[1] ?? 'roles';
$writes      = 0;
$mail_calls  = 0;
$blocked     = 0;
$checks      = 0;
$old_state   = get_option( 'shouse_watch_state' );
$old_work = [];
foreach ( [ 'shouse_watch_scan', 'shouse_watch_lock', 'shouse_watch_epoch', 'cron' ] as $name ) {
	$old_work[ $name ] = get_option( $name );
}
delete_option( 'shouse_watch_scan' );
delete_option( 'shouse_watch_lock' );
$old_errors  = $wpdb->suppress_errors( true );
$fail_query  = static function ( string $sql ) use ( $wpdb, &$path, &$writes, &$blocked ): string {
	if ( str_starts_with( $sql, 'INSERT INTO `' . Queue::table() . '`' ) || str_starts_with( $sql, 'INSERT INTO `' . Log::table() . '`' ) ) {
		++$writes;
	}
	$matches = match ( $path ) {
		'roles'   => str_starts_with( $sql, "SELECT option_value FROM {$wpdb->options} WHERE option_name = '" . $wpdb->get_blog_prefix() . "user_roles'" ),
		'users'   => str_starts_with( $sql, 'SELECT u.ID, u.user_login, m.meta_value FROM ' ),
		'options' => str_starts_with( $sql, "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (" ),
		default   => false,
	};
	if ( $matches ) {
		++$blocked;
		return 'SELECT * FROM `missing_shouse_watch_read_fixture`';
	}
	return $sql;
};
$mail_filter = static function () use ( &$mail_calls ): bool {
	++$mail_calls;
	return true; // Local fixture: transport is never called.
};
$assert = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	WP_CLI::log( 'PASS: ' . $label );
};

// Separate invocations verify actual CLI exit 1 without terminating the main fixture.
// wp eval-file /tests/security/watch-read-test.php cli-check roles
// wp eval-file /tests/security/watch-read-test.php cli-accept options
if ( in_array( $args[0] ?? '', [ 'cli-check', 'cli-accept' ], true ) ) {
	add_filter( 'query', $fail_query );
	$watch->cli( [ substr( $args[0], 4 ) ] );
	WP_CLI::error( 'The failed inventory unexpectedly returned CLI success.' );
}

try {
	$baseline = $watch->snapshot();
	$assert( ! is_wp_error( $baseline ), 'healthy inventory is readable before injection' );
	$baseline['config_hash'] = 'watch-read-fixture-' . wp_generate_uuid4();
	update_option( 'shouse_watch_state', $baseline, false );
	add_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX );
	foreach ( [ 'roles', 'users', 'options' ] as $path ) {
		$writes  = 0;
		$blocked = 0;
		add_filter( 'query', $fail_query );
		$result = $watch->snapshot();
		$assert( is_wp_error( $result ) && 'watch_read_failed' === $result->get_error_code(), "$path SELECT failure returns an explicit inventory error" );
		$message = $result->get_error_message();
		$assert( is_wp_error( $watch->check() ), "$path SELECT failure produces no fabricated changes" );
		$assert( is_wp_error( $watch->accept() ), "$path SELECT failure cannot be accepted" );
		$assert( $message === $watch->handle_task( 'check' ), "$path failure is visible in the check action" );
		$assert( $message === $watch->handle_task( 'accept' ), "$path failure is visible in the accept action" );
		$assert( 5 === $blocked, "$path query failure exercised every public action" );
		$assert( 0 === $writes && 0 === $mail_calls, "$path failure writes no alert or misleading audit entry and sends no mail" );
		remove_filter( 'query', $fail_query );
		$assert( $baseline === get_option( 'shouse_watch_state' ), "$path failure preserves the exact baseline" );
	}
	$changes = $watch->check();
	$assert( is_array( $changes ) && [ 'wp-config.php changed' ] === $changes, 'the next healthy read detects the retained difference' );
	$assert( $baseline !== get_option( 'shouse_watch_state' ), 'healthy read advances the baseline after enqueue' );
	$assert( [] === $watch->check(), 'recovery does not produce repeated or fictional changes' );
	$assert( true === $watch->accept(), 'healthy inventory can be accepted again' );
	WP_CLI::success( $checks . ' Watch read-failure checks passed.' );
} finally {
	remove_filter( 'query', $fail_query );
	remove_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX );
	$wpdb->suppress_errors( $old_errors );
	foreach ( $old_work as $name => $value ) {
		false === $value ? delete_option( $name ) : update_option( $name, $value, false );
	}
	if ( false === $old_state ) {
		delete_option( 'shouse_watch_state' );
	} else {
		update_option( 'shouse_watch_state', $old_state, false );
	}
}
