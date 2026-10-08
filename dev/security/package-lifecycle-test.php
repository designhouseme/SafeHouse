<?php
/**
 * Disposable ZIP-install fixture; never run against a developer's persistent site.
 * Invoke each mode in a fresh wp eval-file process around install/safe-mode/deactivate/uninstall.
 * Pin watch=false, tweaks=true and SHOUSE_LOCK_SETTINGS=true in the disposable wp-config.php.
 * Define SHOUSE_PACKAGE_LIFECYCLE_FIXTURE=true as an explicit disposable-site guard.
 * Block outbound HTTP and mail, disable automatic cron, and install the ZIP into writable plugins.
 * Return local empty update responses for WordPress.org's core/plugin/theme checks to avoid
 * core's expected connection warnings when asserting that debug.log stays empty.
 * Sequence: install --activate; seed; safe-mode on; safe; safe-mode off; active;
 * deactivate; inactive; activate; reactivated; prepare-uninstall; uninstall --deactivate; uninstalled.
 * Phase names above are arguments to wp eval-file; lifecycle actions are regular wp commands.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! defined( 'SHOUSE_PACKAGE_LIFECYCLE_FIXTURE' ) || ! SHOUSE_PACKAGE_LIFECYCLE_FIXTURE ) {
	exit( 1 );
}

use SafeHouse\Core\Queue;
use SafeHouse\Core\Settings;
use SafeHouse\Core\Signature;
use SafeHouse\Plugin;

global $wpdb;
$mode = $args[0] ?? '';
$checks = 0;
$assert = static function ( bool $ok, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	WP_CLI::log( 'PASS: ' . $label );
};
$table = $wpdb->prefix . 'shouse_jobs';
$rows = static fn() => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
$cron = static function (): array {
	$hooks = [];
	foreach ( _get_cron_array() as $events ) {
		foreach ( array_keys( $events ) as $hook ) {
			if ( str_starts_with( $hook, 'shouse_' ) || str_starts_with( $hook, 'wphouse_' ) ) { $hooks[] = $hook; }
		}
	}
	return $hooks;
};
$tables = static fn() => (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'shouse_' ) . '%' ) );
$assert_pins = static function () use ( $assert ): void {
	$p = Plugin::instance();
	$assert( false === $p->settings->forced( 'watch' ) && ! $p->is_running( 'watch' ), 'pinned-off Watch stays stopped' );
	$assert( true === $p->settings->forced( 'tweaks' ) && $p->is_running( 'tweaks' ), 'pinned-on Tweaks runs despite saved disabled setting' );
};
$metadata = get_option( 'lifecycle_package_expected', [] );

switch ( $mode ) {
	case 'seed':
		$assert( is_plugin_active( 'shouse/shouse.php' ) && defined( 'SHOUSE_VERSION' ), 'ZIP plugin is active and boots' );
		$assert( ! file_exists( dirname( SHOUSE_FILE ) . '/.git' ) && ! file_exists( dirname( SHOUSE_FILE ) . '/dev' ), 'installed package has no development checkout' );
		$assert_pins();
		$assert( SafeHouse\Modules\Omnibus::maybe_install( true ), 'optional price-history schema can be installed from the package' );
		$assert( 4 === count( $tables() ), 'all four SafeHouse tables are present' );
		$assert( '2' === get_option( 'shouse_queue_db_version' ) && '2' === get_option( 'shouse_omnibus_db_version' ), 'queue and price-history schema versions are recorded' );
		$p = Plugin::instance();
		$p->settings->set_module_enabled( 'watch', true );
		$p->settings->set_module_enabled( 'tweaks', false );
		wp_set_current_user( 1 );
		$stored = $p->settings->all();
		$assert( Settings::locked() && $stored === $p->settings->sanitize( [ 'modules' => [ 'watch' => false, 'tweaks' => true ] ] ), 'locked settings reject form changes even for the administrator' );
		$assert_pins();
		Queue::handler( 'cloudflare', static fn() => new WP_Error( 'fixture', 'No external purge permitted.' ) );
		$assert( Queue::add( 'mail', [ 'to' => 'fixture@example.test', 'subject' => 'Lifecycle fixture', 'body' => 'Local test only.' ] ), 'mail job is durably queued' );
		$assert( Queue::add( 'cloudflare', [ 'zone' => 'fixture-zone', 'batch' => [ '*' => true ] ] ), 'purge job is durably queued' );
		Queue::run( 'mail', 1 );
		$pending = $rows();
		$assert( 2 === count( $pending ) && 1 === (int) $pending[0]['attempts'], 'blocked mail stays queued with retry metadata' );
		$floor = [ 'protocol' => 2, 'generation' => 12345, 'digest' => hash( 'sha256', 'lifecycle' ) ];
		$assert( Signature::advance_floor( 'shouse_update_floor', $floor ) && Signature::advance_floor( 'shouse_advisory_floor', $floor ), 'both rollback-prevention floors are persisted' );
		update_option( 'shouse_queue_storage_error', time(), false );
		update_option( 'shouse_litespeed_pending', [ 'fixture' => true ], false );
		update_option( 'wphouse_lifecycle_fixture', true, false );
		set_transient( 'shouse_lifecycle_fixture', 'fixture', HOUR_IN_SECONDS );
		set_site_transient( 'shouse_lifecycle_fixture', 'fixture', HOUR_IN_SECONDS );
		wp_schedule_single_event( time() + 600, 'shouse_cloudflare_purge' );
		$assert( in_array( 'shouse_hourly', $cron(), true ) && in_array( 'shouse_daily', $cron(), true ) && in_array( 'shouse_queue', $cron(), true ) && in_array( 'shouse_cloudflare_purge', $cron(), true ), 'all four lifecycle cron hooks are represented' );
		update_option( 'lifecycle_package_expected', [ 'rows' => $rows(), 'floor' => $floor, 'settings' => $stored ], false );
		WP_CLI::log( 'Artifact version: ' . SHOUSE_VERSION . '; WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION );
		break;
	case 'safe':
		$assert( SafeHouse\Core\SafeMode::active(), 'safe-mode flag is active in a fresh request' );
		$p = Plugin::instance();
		$assert( [] === array_filter( array_keys( $p->modules() ), [ $p, 'is_running' ] ), 'safe mode stops every module, including pinned-on modules' );
		$assert( $metadata['settings'] === $p->settings->all(), 'safe mode preserves saved settings' );
		$assert( $metadata['rows'] === $rows(), 'safe mode preserves both pending jobs and retry metadata' );
		$assert( has_action( 'shouse_queue', [ Queue::class, 'run' ] ) !== false, 'queue recovery hook remains available in safe mode' );
		break;
	case 'active':
	case 'reactivated':
		$assert( ! SafeHouse\Core\SafeMode::active(), 'normal mode resumes in a fresh request' );
		$assert_pins();
		$assert( $metadata['rows'] === $rows(), 'deactivation/reactivation or safe-mode round trip preserves queued payloads and retry metadata exactly' );
		$assert( $metadata['floor'] === get_option( 'shouse_update_floor' ) && $metadata['floor'] === get_option( 'shouse_advisory_floor' ), 'both security floors survive lifecycle changes' );
		$assert( $metadata['settings'] === Plugin::instance()->settings->all(), 'saved settings survive lifecycle changes' );
		$assert( in_array( 'shouse_hourly', $cron(), true ) && in_array( 'shouse_daily', $cron(), true ), 'recurring processing is scheduled after activation' );
		break;
	case 'inactive':
		$assert( ! is_plugin_active( 'shouse/shouse.php' ) && ! class_exists( Plugin::class ), 'deactivated package does not boot' );
		$assert( [] === $cron(), 'deactivation removes every SafeHouse cron event' );
		$assert( $metadata['rows'] === $rows(), 'deactivation retains both queued jobs exactly' );
		$assert( 4 === count( $tables() ), 'deactivation retains all data tables' );
		$assert( $metadata['settings'] === get_option( 'shouse_settings' ) && $metadata['floor'] === get_option( 'shouse_update_floor' ), 'deactivation retains settings and security floor' );
		break;
	case 'prepare-uninstall':
		$assert( false !== file_put_contents( WP_CONTENT_DIR . '/shouse-safe-mode', '' ) && false !== file_put_contents( WP_CONTENT_DIR . '/wphouse-safe-mode', '' ), 'current and legacy rescue flags exist before uninstall' );
		$assert( 2 === count( $rows() ) && 4 === count( $tables() ), 'uninstall starts with queued work and all tables' );
		break;
	case 'uninstalled':
		$assert( ! is_dir( WP_PLUGIN_DIR . '/shouse' ) && ! class_exists( Plugin::class ), 'uninstall removes the package files' );
		$assert( [] === $tables(), 'uninstall removes log, login, price-history and job tables' );
		$names = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
		$left = array_values( array_filter( $names, static fn( $name ) => (bool) preg_match( '/^(?:(?:_site)?_transient_(?:timeout_)?)?(?:shouse_|wphouse_)/', $name ) ) );
		$assert( [] === $left, 'uninstall removes all current/legacy options, floors, queue state and transients' );
		$assert( [] === $cron(), 'uninstall leaves no SafeHouse cron events' );
		$assert( ! file_exists( WP_CONTENT_DIR . '/shouse-safe-mode' ) && ! file_exists( WP_CONTENT_DIR . '/wphouse-safe-mode' ), 'uninstall removes both rescue flags' );
		break;
	default:
		throw new RuntimeException( 'Unknown lifecycle phase.' );
}
$assert( ! file_exists( WP_CONTENT_DIR . '/debug.log' ) || '' === trim( (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) ), 'no PHP warnings or errors in the lifecycle debug log' );
WP_CLI::success( $checks . ' package lifecycle checks passed in phase ' . $mode . '.' );
