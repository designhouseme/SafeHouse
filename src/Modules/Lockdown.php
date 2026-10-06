<?php
/**
 * Install lockdown: no new plugins or themes, no ZIP uploads, no code editor. Updates keep working.
 *
 * Two layers: map_meta_cap hides the UI, and upgrader_pre_install stops installs that come
 * through code paths which skip capability checks (the 2025–26 "unauthenticated plugin
 * install" bugs). Watch adds a third layer by alerting on any new plugin directory.
 *
 * Unlock for 30 minutes with `wp shouse unlock` or, unless SHOUSE_LOCKDOWN_UI_UNLOCK is false,
 * the button on the settings page. Every unlock is logged and e-mailed.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use WP_Error;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use SafeHouse\Core\Log;
use SafeHouse\Core\Notify;

defined( 'ABSPATH' ) || exit;

final class Lockdown extends AbstractModule {

	private const UNLOCK_OPTION = 'shouse_lockdown_until';
	private const LOCKED_CAPS   = [ 'install_plugins', 'install_themes', 'upload_plugins', 'upload_themes', 'edit_plugins', 'edit_themes', 'edit_files' ];

	public function id(): string {
		return 'lockdown';
	}

	public function defaults(): array {
		return [];
	}

	public function label(): string {
		return __( 'Install lockdown', 'shouse' );
	}

	public function description(): string {
		return __( 'Nobody can install new plugins or themes or upload ZIP files, not even through a vulnerable plugin that skips permission checks. Updates keep working. Unlock for 30 minutes when you need to install something.', 'shouse' );
	}

	public function fields(): array {
		return [];
	}

	public function available(): bool {
		return ! Compat::constant_on( 'DISALLOW_FILE_MODS' );
	}

	public function unavailable_reason(): string {
		return __( 'DISALLOW_FILE_MODS in wp-config.php already blocks all installs and updates.', 'shouse' );
	}

	public function boot(): void {
		add_filter( 'map_meta_cap', [ $this, 'lock_caps' ], 10, 2 );
		add_filter( 'upgrader_pre_install', [ $this, 'block_install' ], 10, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse unlock', [ $this, 'cli_unlock' ] );
			WP_CLI::add_command( 'shouse lock', [ $this, 'cli_lock' ] );
		}
	}

	public static function unlocked_until(): int {
		return (int) get_option( self::UNLOCK_OPTION, 0 );
	}

	public static function is_unlocked(): bool {
		return self::unlocked_until() > time();
	}

	/**
	 * @param string[] $caps Primitive caps.
	 * @param string   $cap  Requested cap.
	 * @return string[]
	 */
	public function lock_caps( array $caps, string $cap ): array {
		return in_array( $cap, self::LOCKED_CAPS, true ) && ! self::is_unlocked() ? [ 'do_not_allow' ] : $caps;
	}

	/**
	 * Core passes action=install for new plugins/themes and uploads, action=update for updates.
	 *
	 * @param mixed                $response   True or an error from earlier filters.
	 * @param array<string, mixed> $hook_extra Upgrade context.
	 */
	public function block_install( mixed $response, array $hook_extra ): mixed {
		if ( is_wp_error( $response ) || self::is_unlocked() ) {
			return $response;
		}
		$type = $hook_extra['type'] ?? '';
		if ( 'install' !== ( $hook_extra['action'] ?? '' ) || ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			return $response;
		}
		Log::add( 'install_blocked', sprintf( 'Blocked a %s install while locked', $type ), [], 'warning' );
		return new WP_Error( 'shouse_locked', __( 'Installing plugins and themes is locked by SafeHouse. Unlock it first (SafeHouse in the admin menu or `wp shouse unlock`).', 'shouse' ) );
	}

	public function unlock( int $minutes, string $via ): void {
		$minutes = max( 1, min( 240, $minutes ) );
		update_option( self::UNLOCK_OPTION, time() + $minutes * MINUTE_IN_SECONDS, false );
		$user = wp_get_current_user();
		$who  = $user->exists() ? $user->user_login : $via;
		Log::add( 'lockdown_unlocked', sprintf( 'Installs unlocked for %d minutes via %s', $minutes, $via ), [], 'warning' );
		Notify::send(
			'installs unlocked',
			[ sprintf( 'Plugin and theme installs were unlocked for %d minutes by %s (%s).', $minutes, $who, $via ) ]
		);
	}

	public function lock(): void {
		delete_option( self::UNLOCK_OPTION );
		Log::add( 'lockdown_locked', 'Installs locked again' );
	}

	public function tasks(): array {
		if ( self::is_unlocked() ) {
			return [ 'lock' => __( 'Lock now', 'shouse' ) ];
		}
		if ( defined( 'SHOUSE_LOCKDOWN_UI_UNLOCK' ) && ! SHOUSE_LOCKDOWN_UI_UNLOCK ) {
			return [];
		}
		return [ 'unlock' => __( 'Unlock installs for 30 minutes', 'shouse' ) ];
	}

	public function handle_task( string $task ): string {
		if ( 'unlock' === $task ) {
			$this->unlock( 30, 'settings page' );
			return __( 'Installs are unlocked for 30 minutes.', 'shouse' );
		}
		$this->lock();
		return __( 'Installs are locked.', 'shouse' );
	}

	public function render_panel(): void {
		$until = self::unlocked_until();
		echo '<p class="shouse-panel">';
		if ( $until > time() ) {
			/* translators: %s: time, e.g. 14:35 */
			echo esc_html( sprintf( __( 'Unlocked until %s.', 'shouse' ), wp_date( get_option( 'time_format' ), $until ) ) );
		} else {
			esc_html_e( 'Locked. Updates of installed plugins, themes and core still work.', 'shouse' );
		}
		echo '</p>';
	}

	/**
	 * Unlock plugin and theme installs for a while.
	 *
	 * ## OPTIONS
	 *
	 * [--minutes=<minutes>]
	 * : How long, 1–240.
	 * ---
	 * default: 30
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function cli_unlock( array $args, array $assoc_args ): void {
		$this->unlock( (int) ( $assoc_args['minutes'] ?? 30 ), 'WP-CLI' );
		WP_CLI::success( 'Installs unlocked until ' . wp_date( 'H:i', self::unlocked_until() ) . '.' );
	}

	/**
	 * Lock plugin and theme installs again.
	 */
	public function cli_lock(): void {
		$this->lock();
		WP_CLI::success( 'Installs locked.' );
	}
}
