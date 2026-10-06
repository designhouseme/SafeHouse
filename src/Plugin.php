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
use WPHouse\Core\ObjectCache;
use WPHouse\Core\SafeMode;
use WPHouse\Core\Settings;
use WPHouse\Core\Updater;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var array<int, class-string<AbstractModule>> */
	private const MODULES = [
		Modules\Hardening::class,
		Modules\Lockdown::class,
		Modules\Watch::class,
		Modules\PluginHealth::class,
		Modules\Tweaks::class,
		Modules\Duplicate::class,
		Modules\Smtp::class,
		Modules\Scripts::class,
		Modules\Maintenance::class,
		Modules\Vulnerabilities::class,
		Modules\LiteSpeed::class,
		Modules\Bots::class,
		Modules\LoginLimits::class,
		Modules\Cloudflare::class,
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
		ObjectCache::register(); // Also in safe mode: status and `wp wphouse object-cache disable` must stay reachable.
		add_filter( 'site_status_tests', [ Core\Net::class, 'site_health_test' ] );
		Core\ContentChanges::register();

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

	/** The WPHouse admin page, optionally opened at a module (by id) or section. */
	public static function settings_url( string $anchor = '' ): string {
		return admin_url( 'admin.php?page=wphouse' ) . ( '' !== $anchor ? '#wphouse-' . $anchor : '' );
	}

	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'wphouse_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'wphouse_hourly' );
		}
		if ( ! wp_next_scheduled( 'wphouse_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'wphouse_daily' );
		}
	}

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			wp_die( esc_html__( 'WPHouse 0.x supports single sites only. Activate it per site instead of network-wide.', 'wphouse' ), '', [ 'back_link' => true ] );
		}
		Log::install();
		self::schedule_events();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wphouse_hourly' );
		wp_clear_scheduled_hook( 'wphouse_daily' );
		ObjectCache::deactivate();
	}
}
