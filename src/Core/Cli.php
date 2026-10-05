<?php
/**
 * Core WP-CLI commands. Modules add their own subcommands (e.g. `wp wphouse unlock`) when they boot.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

use WP_CLI;
use WPHouse\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage WPHouse modules, safe mode and the event log.
 */
final class Cli {

	public static function register(): void {
		WP_CLI::add_command( 'wphouse', self::class );
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
				'source'   => null !== $forced ? 'WPHOUSE_MODULES' : 'settings',
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
	 * : Module id, as shown by `wp wphouse status`.
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
			WP_CLI::warning( "$id is pinned by WPHOUSE_MODULES in wp-config.php; the saved setting has no effect." );
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
		if ( file_exists( $file ) && ! unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			WP_CLI::error( "Could not remove $file" );
		}
		Log::add( 'safe_mode', 'Safe mode turned off from WP-CLI' );
		if ( Compat::constant_on( 'WPHOUSE_SAFE_MODE' ) ) {
			WP_CLI::warning( 'WPHOUSE_SAFE_MODE is still defined in wp-config.php.' );
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
}
