<?php
/**
 * Module registry. Every module is instantiated (cheap, no hooks), but only enabled and
 * available modules get booted. Admin code loads only in wp-admin, CLI code only in WP-CLI.
 *
 * @package WPHouse
 */

namespace WPHouse;

use WPHouse\Core\AbstractModule;
use WPHouse\Core\Cli;
use WPHouse\Core\Log;
use WPHouse\Core\SafeMode;
use WPHouse\Core\Settings;
use WPHouse\Core\Updater;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var array<int, class-string<AbstractModule>> */
	private const MODULES = [
		Modules\Hardening::class,
	];

	private static ?Plugin $instance = null;

	public Settings $settings;

	/** @var array<string, AbstractModule> */
	private array $modules = [];

	/** @var array<string, bool> */
	private array $booted = [];

	private function __construct() {
		$this->settings = new Settings();
		foreach ( self::MODULES as $class ) {
			$module                         = new $class();
			$this->modules[ $module->id() ] = $module;
		}
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		$plugin = self::instance();

		Log::maybe_install();
		self::schedule_events();
		add_action( 'wphouse_daily', [ Log::class, 'purge' ] );
		add_action( 'init', [ $plugin, 'load_textdomain' ] );
		Updater::register(); // Also in safe mode: that is how a fix for a broken module arrives.

		if ( ! SafeMode::active() ) {
			foreach ( $plugin->modules as $id => $module ) {
				if ( $module->available() && $plugin->settings->module_enabled( $module ) ) {
					$module->boot();
					$plugin->booted[ $id ] = true;
				}
			}
		}

		if ( is_admin() ) {
			new Admin\Page( $plugin );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wphouse', false, dirname( plugin_basename( WPHOUSE_FILE ) ) . '/languages' );
	}

	/** @return array<string, AbstractModule> */
	public function modules(): array {
		return $this->modules;
	}

	public function module( string $id ): ?AbstractModule {
		return $this->modules[ $id ] ?? null;
	}

	/** Whether the module registered its hooks in this request. */
	public function is_running( string $id ): bool {
		return isset( $this->booted[ $id ] );
	}

	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'wphouse_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'wphouse_hourly' );
		}
		if ( ! wp_next_scheduled( 'wphouse_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'wphouse_daily' );
		}
	}

	public static function activate(): void {
		Log::install();
		self::schedule_events();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wphouse_hourly' );
		wp_clear_scheduled_hook( 'wphouse_daily' );
	}
}
