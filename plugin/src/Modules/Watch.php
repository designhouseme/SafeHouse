<?php
/**
 * Change alerts for the things attackers leave behind: new administrators, new or activated
 * plugins, mu-plugins, drop-ins, edits to wp-config.php and changes to the options a takeover
 * flips (open registration, default role, admin e-mail, site address).
 *
 * Role and option changes made through WordPress are alerted immediately. Everything else is an
 * hourly inventory diff, which
 * also catches changes made straight in the database or over FTP. The first run (and
 * `wp shouse watch accept`, meant for the end of deploy scripts) records a baseline silently.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Log;
use SafeHouse\Core\Notify;

defined( 'ABSPATH' ) || exit;

final class Watch extends AbstractModule {

	private const STATE_OPTION = 'shouse_watch_state';
	private const OPTIONS      = [ 'users_can_register', 'default_role', 'admin_email', 'siteurl', 'home' ];
	private const DROPINS      = [ 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'install.php', 'sunrise.php', 'fatal-error-handler.php', 'php-error.php' ];

	public function id(): string {
		return 'watch';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [];
	}

	public function label(): string {
		return __( 'Change alerts', 'shouse' );
	}

	public function description(): string {
		return __( 'E-mails the alert recipients when an administrator is added, a plugin or theme appears or is activated, a mu-plugin or drop-in shows up, wp-config.php changes, or someone opens registration or changes the default role, admin e-mail or site address. Changes are also written to the activity log.', 'shouse' );
	}

	public function fields(): array {
		return [];
	}

	public function boot(): void {
		add_action( 'user_register', [ $this, 'on_user_register' ] );
		add_action( 'set_user_role', [ $this, 'on_role_change' ], 10, 3 );
		add_action( 'add_user_role', [ $this, 'on_role_added' ], 10, 2 );
		foreach ( self::OPTIONS as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'on_option_update' ], 10, 3 );
		}
		add_action( 'shouse_hourly', [ $this, 'cron_check' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse watch', [ $this, 'cli' ] );
		}
	}

	public function on_user_register( int $user_id ): void {
		if ( user_can( $user_id, 'manage_options' ) ) {
			$this->admin_alert( $user_id, 'New administrator account' );
		}
	}

	/**
	 * @param int      $user_id   User.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 */
	public function on_role_change( int $user_id, string $role, array $old_roles ): void {
		if ( 'administrator' === $role && ! in_array( 'administrator', $old_roles, true ) ) {
			$this->admin_alert( $user_id, 'User promoted to administrator' );
		}
	}

	public function on_role_added( int $user_id, string $role ): void {
		if ( 'administrator' === $role ) {
			$this->admin_alert( $user_id, 'Administrator role added to a user' );
		}
	}

	private function admin_alert( int $user_id, string $what ): void {
		// wp_insert_user() fires set_user_role and then user_register for the same account.
		static $alerted = [];
		if ( isset( $alerted[ $user_id ] ) ) {
			return;
		}
		$alerted[ $user_id ] = true;

		$user  = get_userdata( $user_id );
		$actor = wp_get_current_user();
		$line  = sprintf(
			'%s: %s (%s), by %s',
			$what,
			$user ? $user->user_login : '#' . $user_id,
			$user ? $user->user_email : '?',
			$actor->exists() ? $actor->user_login : 'no logged-in user (code, CLI or cron)'
		);
		Log::add( 'admin_added', $line, [ 'user_id' => $user_id ], 'critical' );
		Notify::send( 'new administrator', [ $line, '', 'If you did not expect this, lock the account and check the site.' ] );
		$state = $this->stored_state();
		if ( $state && $user ) {
			$state['admins'][ (string) $user_id ] = $user->user_login;
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	public function on_option_update( mixed $old_value, mixed $value, string $option ): void {
		$change = self::option_change( $option, $old_value, $value );
		$actor  = wp_get_current_user();
		$line   = sprintf( '%s, by %s', $change, $actor->exists() ? $actor->user_login : 'no logged-in user (code, CLI or cron)' );
		Log::add( 'option_changed', $line, [ 'option' => $option ], 'critical' );
		Notify::send(
			'site setting changed',
			[ $line, '', 'Attackers change these settings to register their own administrator or take over password resets. If you did not expect this, change it back and check the site.' ],
			'admin_email' === $option ? (string) $old_value : ''
		);
		$state = $this->stored_state();
		if ( $state && isset( $state['options'] ) ) {
			$state['options'][ $option ] = self::scalar( $value );
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	private static function option_change( string $option, mixed $old_value, mixed $value ): string {
		return sprintf( 'Setting %s changed from "%s" to "%s"', $option, self::scalar( $old_value ), self::scalar( $value ) );
	}

	/** Option value as a short string for alerts; these options are all scalar in core. */
	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? mb_substr( (string) $value, 0, 200 ) : '(' . gettype( $value ) . ')';
	}

	/**
	 * Current inventory. Cheap: directory listings, a few options, one user query and one file hash.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$admins = [];
		foreach ( get_users(
			[
				'role'   => 'administrator',
				'fields' => [ 'ID', 'user_login' ],
			]
		) as $user ) {
			$admins[ (string) $user->ID ] = $user->user_login;
		}
		$active = get_option( 'active_plugins', [] );
		$config = $this->config_path();
		return [
			'admins'         => $admins,
			'plugins'        => self::entries( WP_PLUGIN_DIR ),
			'active_plugins' => is_array( $active ) ? array_values( $active ) : [],
			'themes'         => self::entries( get_theme_root() ),
			'mu_plugins'     => self::entries( WPMU_PLUGIN_DIR ),
			'dropins'        => array_values( array_filter( self::DROPINS, static fn( $file ) => file_exists( WP_CONTENT_DIR . '/' . $file ) ) ),
			'config_hash'    => $config ? (string) hash_file( 'sha256', $config ) : '',
			'options'        => self::raw_options(),
		];
	}

	public function cron_check(): void {
		$this->check();
	}

	/**
	 * Compare with the stored baseline, alert once, then store the new state.
	 *
	 * @return string[] Changes found.
	 */
	public function check(): array {
		$before = $this->stored_state();
		$now    = $this->snapshot();
		update_option( self::STATE_OPTION, $now, false );
		if ( ! $before ) {
			return [];
		}

		$changes = [];
		$labels  = [
			'admins'         => [ 'Administrator added', 'Administrator removed' ],
			'plugins'        => [ 'Plugin directory added', 'Plugin directory removed' ],
			'active_plugins' => [ 'Plugin activated', 'Plugin deactivated' ],
			'themes'         => [ 'Theme added', 'Theme removed' ],
			'mu_plugins'     => [ 'Must-use plugin added', 'Must-use plugin removed' ],
			'dropins'        => [ 'Drop-in added', 'Drop-in removed' ],
		];
		foreach ( $labels as $key => [ $added_label, $removed_label ] ) {
			$old = (array) ( $before[ $key ] ?? [] );
			$new = (array) $now[ $key ];
			foreach ( array_diff( $new, $old ) as $item ) {
				$changes[] = "$added_label: $item";
			}
			foreach ( array_diff( $old, $new ) as $item ) {
				$changes[] = "$removed_label: $item";
			}
		}
		if ( ( $before['config_hash'] ?? '' ) !== $now['config_hash'] ) {
			$changes[] = 'wp-config.php changed';
		}
		// A baseline from before options were watched has no 'options' key: record it silently.
		$old_admin_email = '';
		if ( is_array( $before['options'] ?? null ) ) {
			foreach ( $now['options'] as $option => $value ) {
				if ( array_key_exists( $option, $before['options'] ) && (string) $before['options'][ $option ] !== $value ) {
					$changes[] = self::option_change( $option, $before['options'][ $option ], $value );
					if ( 'admin_email' === $option ) {
						$old_admin_email = (string) $before['options'][ $option ];
					}
				}
			}
		}

		if ( $changes ) {
			$critical = (bool) preg_grep( '/^(Administrator added|Must-use plugin added|Drop-in added|wp-config|Setting )/', $changes );
			Log::add( 'inventory_changed', implode( '; ', $changes ), [], $critical ? 'critical' : 'warning' );
			Notify::send( 'changes detected', array_merge( [ 'SafeHouse noticed these changes since the last check (up to an hour ago):', '' ], array_map( static fn( $c ) => '- ' . $c, $changes ) ), $old_admin_email );
		}
		return $changes;
	}

	public function accept(): void {
		update_option( self::STATE_OPTION, $this->snapshot(), false );
		Log::add( 'inventory_accepted', 'Current plugins, admins, files and site settings accepted as the baseline' );
	}

	public function tasks(): array {
		return [
			'check'  => __( 'Check now', 'shouse' ),
			'accept' => __( 'Accept current state', 'shouse' ),
		];
	}

	public function handle_task( string $task ): string {
		if ( 'accept' === $task ) {
			$this->accept();
			return __( 'The current state is the new baseline.', 'shouse' );
		}
		$changes = $this->check();
		/* translators: %d: number of changes. */
		return $changes ? sprintf( _n( '%d change found and reported.', '%d changes found and reported.', count( $changes ), 'shouse' ), count( $changes ) ) : __( 'No changes since the last check.', 'shouse' );
	}

	public function render_panel(): void {
		$state = $this->stored_state();
		echo '<p class="shouse-panel">';
		if ( ! $state ) {
			esc_html_e( 'No baseline yet. It is recorded on the first hourly check.', 'shouse' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: number of administrators, 2: number of plugins, 3: number of mu-plugins, 4: number of drop-ins, 5: number of site settings. */
					__( 'Watching %1$d administrators, %2$d plugins, %3$d must-use plugins, %4$d drop-ins, wp-config.php and %5$d site settings.', 'shouse' ),
					count( (array) $state['admins'] ),
					count( (array) $state['plugins'] ),
					count( (array) $state['mu_plugins'] ),
					count( (array) $state['dropins'] ),
					count( (array) ( $state['options'] ?? [] ) )
				)
			);
		}
		echo '</p>';
	}

	/**
	 * Compare with the baseline now, or accept the current state as the new baseline.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : check or accept. Run `accept` at the end of deploy scripts.
	 * ---
	 * options:
	 *   - check
	 *   - accept
	 * ---
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function cli( array $args ): void {
		if ( 'accept' === $args[0] ) {
			$this->accept();
			WP_CLI::success( 'Baseline updated.' );
			return;
		}
		$changes = $this->check();
		foreach ( $changes as $change ) {
			WP_CLI::log( $change );
		}
		WP_CLI::success( $changes ? count( $changes ) . ' change(s) reported.' : 'No changes.' );
	}

	/**
	 * Watched options as stored in the database, past any filter (Hardening filters default_role
	 * on read, which would hide a value planted straight in the table) and past the object cache.
	 *
	 * @return array<string, string>
	 */
	private static function raw_options(): array {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( self::OPTIONS ), '%s' ) );
		$rows         = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)", self::OPTIONS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the uncached value is the point; placeholders built above.
		$options      = array_fill_keys( self::OPTIONS, '' );
		foreach ( $rows as $row ) {
			$options[ (string) $row->option_name ] = self::scalar( $row->option_value );
		}
		return $options;
	}

	/** @return array<string, mixed> */
	private function stored_state(): array {
		$state = get_option( self::STATE_OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	/** Same lookup as wp-load.php: ABSPATH, or one level up when it is not part of another install. */
	private function config_path(): string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ? $up : '';
	}

	/**
	 * Top-level entries of a directory, without index.php and dotfiles.
	 *
	 * @return string[]
	 */
	private static function entries( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$items = array_values(
			array_filter(
				(array) scandir( $dir ),
				static fn( $name ) => is_string( $name ) && '' !== $name && '.' !== $name[0] && 'index.php' !== $name
			)
		);
		sort( $items );
		return $items;
	}
}
