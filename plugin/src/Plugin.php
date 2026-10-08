<?php
/**
 * Module registry. Every module is instantiated (cheap, no hooks), but only enabled and
 * available modules get booted. Admin code loads only in wp-admin, CLI code only in WP-CLI.
 *
 * @package SafeHouse
 */

namespace SafeHouse;

use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Cli;
use SafeHouse\Core\Log;
use SafeHouse\Core\ObjectCache;
use SafeHouse\Core\SafeMode;
use SafeHouse\Core\Settings;
use SafeHouse\Core\Updater;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var array<int, class-string<AbstractModule>> */
	private const MODULES = [
		Modules\Hardening::class,
		Modules\Lockdown::class,
		Modules\Watch::class,
		Modules\PluginHealth::class,
		Modules\Stability::class,
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
		Modules\Omnibus::class,
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
		Core\Migration::run(); // Before anything reads options or tables: installs from before the rename to SafeHouse.
		$plugin = self::instance();

		Log::maybe_install();
		Core\Queue::boot();
		Core\Notify::register();
		self::schedule_events();
		add_action( 'shouse_daily', [ Log::class, 'purge' ] );
		add_action( 'init', [ $plugin, 'load_textdomain' ] );
		Updater::register(); // Also in safe mode: that is how a fix for a broken module arrives.
		ObjectCache::register(); // Also in safe mode: status and `wp shouse object-cache disable` must stay reachable.
		add_filter( 'site_status_tests', [ Core\Net::class, 'site_health_test' ] );
		Core\ContentChanges::register();
		Modules\LiteSpeed::register(); // Finish pending invalidations even after the module is disabled.

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
			\WP_CLI::add_command( 'shouse stability', Core\StabilityCommand::class );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'shouse', false, dirname( plugin_basename( SHOUSE_FILE ) ) . '/languages' );
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

	/** The SafeHouse admin page, optionally opened at a module (by id) or section. */
	public static function settings_url( string $anchor = '' ): string {
		return admin_url( 'admin.php?page=shouse' ) . ( '' !== $anchor ? '#shouse-' . $anchor : '' );
	}

	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'shouse_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'shouse_hourly' );
		}
		if ( ! wp_next_scheduled( 'shouse_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'shouse_daily' );
		}
	}

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			wp_die( esc_html__( 'SafeHouse 0.x supports single sites only. Activate it per site instead of network-wide.', 'shouse' ), '', [ 'back_link' => true ] );
		}
		Core\Migration::run();
		Log::install();
		self::schedule_events();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'shouse_hourly' );
		wp_clear_scheduled_hook( 'shouse_daily' );
		wp_clear_scheduled_hook( 'shouse_queue' );
		wp_clear_scheduled_hook( 'shouse_cloudflare_purge' );
		wp_clear_scheduled_hook( 'shouse_watch_continue' );
		delete_option( 'shouse_stability_session' );
		ObjectCache::deactivate();
	}
}
