<?php
/**
 * Core WP-CLI commands. Modules add their own subcommands (e.g. `wp shouse unlock`) when they boot.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_CLI;
use SafeHouse\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage SafeHouse modules, safe mode and the event log.
 */
final class Cli {

	public static function register(): void {
		WP_CLI::add_command( 'shouse', self::class );
	}

	/**
	 * Show every module, whether it is enabled and whether it is running.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$plugin = Plugin::instance();
		$rows   = [];
		foreach ( $plugin->modules() as $id => $module ) {
			$forced = $plugin->settings->forced( $id );
			$rows[] = [
				'module'   => $id,
				'enabled'  => $plugin->settings->module_enabled( $module ) ? 'yes' : 'no',
				'running'  => $plugin->is_running( $id ) ? 'yes' : 'no',
				'source'   => null !== $forced ? 'SHOUSE_MODULES' : 'settings',
				'overlaps' => implode( ', ', array_map( static fn( $k, $v ) => "$k: $v", array_keys( $module->coverage() ), $module->coverage() ) ),
			];
		}
		if ( SafeMode::active() ) {
			WP_CLI::warning( 'Safe mode is on: no module is running.' );
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'module', 'enabled', 'running', 'source', 'overlaps' ] );
	}

	/**
	 * Enable or disable a module.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : enable or disable.
	 * ---
	 * options:
	 *   - enable
	 *   - disable
	 * ---
	 *
	 * <module>
	 * : Module id, as shown by `wp shouse status`.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function module( array $args ): void {
		[ $action, $id ] = $args;
		$plugin          = Plugin::instance();
		if ( null === $plugin->module( $id ) ) {
			WP_CLI::error( "Unknown module: $id" );
		}
		if ( null !== $plugin->settings->forced( $id ) ) {
			WP_CLI::warning( "$id is pinned by SHOUSE_MODULES in wp-config.php; the saved setting has no effect." );
		}
		$plugin->settings->set_module_enabled( $id, 'enable' === $action );
		WP_CLI::success( "$id " . ( 'enable' === $action ? 'enabled' : 'disabled' ) . '.' );
	}

	/**
	 * Turn safe mode on or off. Safe mode stops every module without changing settings.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * @subcommand safe-mode
	 * @param string[] $args Positional arguments.
	 */
	public function safe_mode( array $args ): void {
		$file = SafeMode::flag_file();
		if ( 'on' === $args[0] ) {
			if ( false === file_put_contents( $file, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- operator command.
				WP_CLI::error( "Could not create $file" );
			}
			Log::add( 'safe_mode', 'Safe mode turned on from WP-CLI', [], 'warning' );
			WP_CLI::success( 'Safe mode on.' );
			return;
		}
		foreach ( [ $file, SafeMode::legacy_flag_file() ] as $flag ) {
			if ( file_exists( $flag ) && ! unlink( $flag ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				WP_CLI::error( "Could not remove $flag" );
			}
		}
		Log::add( 'safe_mode', 'Safe mode turned off from WP-CLI' );
		if ( Compat::constant_on( 'SHOUSE_SAFE_MODE' ) ) {
			WP_CLI::warning( 'SHOUSE_SAFE_MODE is still defined in wp-config.php.' );
		}
		WP_CLI::success( 'Safe mode off.' );
	}

	/**
	 * Show recent log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of entries.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$rows = array_map(
			static fn( $row ) => [
				'time'     => $row->created_at,
				'event'    => $row->event,
				'severity' => $row->severity,
				'user'     => $row->user_id,
				'ip'       => $row->ip,
				'message'  => $row->message,
			],
			Log::recent( (int) ( $assoc_args['limit'] ?? 20 ) )
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'time', 'event', 'severity', 'user', 'ip', 'message' ] );
	}

	/**
	 * Check the update server now (clears the cached manifest).
	 *
	 * @subcommand update-check
	 */
	public function update_check(): void {
		if ( Compat::constant_on( 'SHOUSE_DISABLE_UPDATES' ) ) {
			WP_CLI::error( 'Updates are disabled by SHOUSE_DISABLE_UPDATES.' );
		}
		if ( Updater::is_dev_checkout() ) {
			WP_CLI::error( 'This is a git checkout; updates are disabled so they cannot overwrite it.' );
		}
		$result = Updater::check_now();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$update = get_site_transient( 'update_plugins' );
		$offer  = is_object( $update ) && isset( $update->response[ Updater::basename() ] ) ? $update->response[ Updater::basename() ] : null;
		WP_CLI::log( 'Installed: ' . SHOUSE_VERSION . ', latest signed release: ' . $result );
		WP_CLI::success( $offer ? 'Update available: ' . $offer->new_version : 'Up to date.' );
	}
}
