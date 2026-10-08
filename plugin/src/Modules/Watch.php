<?php
/**
 * Change alerts for the things attackers leave behind: new administrators, new or activated
 * plugins, mu-plugins, drop-ins, edits to wp-config.php and changes to the options a takeover
 * flips (open registration, default role, admin e-mail, site address).
 *
 * Role and option changes made through WordPress are alerted immediately. Everything else is an
 * resumable hourly inventory diff, which
 * also catches changes made straight in the database or over FTP. The first run (and
 * `wp shouse watch accept`, meant for the end of deploy scripts) records a baseline silently.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use WP_Error;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Log;
use SafeHouse\Core\Notify;
use SafeHouse\Core\WorkBudget;

defined( 'ABSPATH' ) || exit;

final class Watch extends AbstractModule {

	private const STATE_OPTION  = 'shouse_watch_state';
	private const SCAN_OPTION   = 'shouse_watch_scan';
	private const EPOCH_OPTION  = 'shouse_watch_epoch';
	private const LOCK_OPTION   = 'shouse_watch_lock';
	private const CONTINUE_HOOK = 'shouse_watch_continue';
	private const PAGE_SIZE     = 100;
	private const SLICE_USERS   = 500;
	private const MAX_PREIMAGES = 128;
	private bool $pending       = false;
	/** @var array<int, array<string, mixed>|WP_Error> */
	private array $prior_users = [];
	/** @var array<int, int> Nested real writes awaiting their after action. */
	private array $prior_depths   = [];
	private const CAPS            = [ 'manage_options', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'install_plugins', 'update_plugins', 'activate_plugins', 'delete_plugins', 'edit_plugins', 'install_themes', 'update_themes', 'edit_themes', 'delete_themes', 'switch_themes', 'unfiltered_html', 'manage_woocommerce' ];
	private const OPTIONS         = [ 'users_can_register', 'default_role', 'admin_email', 'siteurl', 'home' ];
	private bool $delivery_queued = true;

	private const DROPINS = [ 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'maintenance.php', 'install.php', 'sunrise.php', 'fatal-error-handler.php', 'php-error.php' ];

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
		return __( 'Queues an alert when sensitive capabilities are granted or removed (including custom roles and individual grants), a plugin or theme appears or is activated, a mu-plugin or drop-in shows up, wp-config.php changes, or someone opens registration or changes the default role, admin e-mail or site address. Changes are also written to the activity log.', 'shouse' );
	}

	public function fields(): array {
		return [];
	}

	public function boot(): void {
		// Bootstrap must not enumerate customers on the first public request.
		if ( ! $this->stored_state() ) {
			$this->schedule();
		}
		// These actions run after short-circuit filters and unchanged-value checks.
		add_action( 'add_user_meta', [ $this, 'before_capability_add' ], 10, 2 );
		foreach ( [ 'update_user_meta', 'delete_user_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'before_capability_write' ], 10, 3 );
		}
		foreach ( [ 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'on_capability_meta' ], 10, 3 );
		}
		global $wpdb;
		add_action( 'update_option_' . $wpdb->get_blog_prefix() . 'user_roles', [ $this, 'on_roles_update' ], 10, 2 );
		foreach ( self::OPTIONS as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'on_option_update' ], 10, 3 );
		}
		add_action( 'shouse_hourly', [ $this, 'cron_check' ] );
		add_action( self::CONTINUE_HOOK, [ $this, 'cron_check' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse watch', [ $this, 'cli' ] );
		}
	}

	public function before_capability_add( int $user_id, string $key ): void {
		$this->before_capability_meta( null, $user_id, $key );
	}

	/** @param mixed $meta_id Metadata row ID or IDs. */
	public function before_capability_write( mixed $meta_id, int $user_id, string $key ): void {
		$this->before_capability_meta( null, $user_id, $key );
	}

	/** Capture only actual writes. Failed SQL can omit the after action, so retained preimages are capped. */
	public function before_capability_meta( mixed $check, int $user_id, string $key ): mixed {
		global $wpdb;
		if ( null !== $check || $wpdb->get_blog_prefix() . 'capabilities' !== $key ) {
			return $check;
		}
		if ( ! isset( $this->prior_users[ $user_id ] ) && count( $this->prior_users ) >= self::MAX_PREIMAGES ) {
			$oldest = array_key_first( $this->prior_users );
			unset( $this->prior_users[ $oldest ], $this->prior_depths[ $oldest ] );
			$this->schedule();
		}
		$this->prior_depths[ $user_id ] = ( $this->prior_depths[ $user_id ] ?? 0 ) + 1;
		$this->prior_users[ $user_id ]  = self::user_privileges( $user_id );
		return $check;
	}

	/**
	 * @param mixed $meta_id Changed metadata row ID or IDs. */
	public function on_capability_meta( mixed $meta_id, int $user_id, string $key ): void {
		global $wpdb;
		if ( $wpdb->get_blog_prefix() . 'capabilities' !== $key ) {
			return;
		}
		$prior = $this->prior_users[ $user_id ] ?? null;
		$depth = ( $this->prior_depths[ $user_id ] ?? 1 ) - 1;
		$read  = self::read_stored( self::STATE_OPTION );
		$now   = self::user_privileges( $user_id );
		if ( $depth > 0 ) {
			// An inner write becomes the real preimage for the still-pending outer write.
			$this->prior_depths[ $user_id ] = $depth;
			$this->prior_users[ $user_id ]  = $now;
		} else {
			unset( $this->prior_users[ $user_id ], $this->prior_depths[ $user_id ] );
		}
		if ( is_wp_error( $prior ) || is_wp_error( $now ) || is_wp_error( $read ) ) {
			$this->schedule();
			return;
		}
		$state   = is_array( $read['value'] ) ? $read['value'] : [];
		$old     = $state['privileges'][ $user_id ] ?? $prior ?? [
			'login' => $now['login'],
			'caps'  => [],
		];
		$changes = self::privilege_changes( [ $user_id => $old ], [ $user_id => $now ] );
		// A grant and revocation in one request must both survive, even before bootstrap finishes.
		if ( is_array( $prior ) ) {
			$changes = array_values( array_unique( array_merge( $changes, self::privilege_changes( [ $user_id => $prior ], [ $user_id => $now ] ) ) ) );
		}
		if ( ! $changes ) {
			return; // Customer/subscriber churn never rewrites the full baseline.
		}
		$this->invalidate_scan();
		if ( ! $this->report( $changes ) ) {
			$this->schedule();
			return;
		}
		if ( ! $state || ! isset( $state['privileges'] ) ) {
			$this->schedule();
			return;
		}
		if ( $now['caps'] ) {
			$state['privileges'][ $user_id ] = $now;
		} else {
			unset( $state['privileges'][ $user_id ] );
		}
		$state['admins']   = self::admins( $state['privileges'] );
		$state['revision'] = wp_generate_uuid4();
		if ( ! self::replace_stored( self::STATE_OPTION, $read['raw'], $state ) ) {
			$this->schedule();
		}
	}

	/** Role definitions are cheap to compare; affected users are reconciled in the background. */
	public function on_roles_update( mixed $old_roles, mixed $new_roles ): void {
		$old     = self::role_privileges( is_array( $old_roles ) ? $old_roles : [] );
		$new     = self::role_privileges( is_array( $new_roles ) ? $new_roles : [] );
		$changes = self::role_changes( $old, $new );
		if ( $changes ) {
			$this->invalidate_scan();
		}
		$read = $changes ? self::read_stored( self::STATE_OPTION ) : null;
		if ( $changes && $this->report( $changes ) ) {
			if ( ! is_wp_error( $read ) && is_array( $read['value'] ) && $read['value'] ) {
				$state             = $read['value'];
				$state['roles']    = $new;
				$state['revision'] = wp_generate_uuid4();
				self::replace_stored( self::STATE_OPTION, $read['raw'], $state );
			}
		}
		$this->schedule();
	}

	public function on_option_update( mixed $old_value, mixed $value, string $option ): void {
		$this->invalidate_scan();
		$read   = self::read_stored( self::STATE_OPTION );
		$change = self::option_change( $option, $old_value, $value );
		$actor  = wp_get_current_user();
		$line   = sprintf( '%s, by %s', $change, $actor->exists() ? $actor->user_login : 'no logged-in user (code, CLI or cron)' );
		Log::add( 'option_changed', $line, [ 'option' => $option ], 'critical' );
		$queued = Notify::send(
			'site setting changed',
			[ $line, '', 'Attackers change these settings to register their own administrator or take over password resets. If you did not expect this, change it back and check the site.' ],
			'admin_email' === $option ? (string) $old_value : ''
		);
		if ( $queued && ! is_wp_error( $read ) && is_array( $read['value'] ) && isset( $read['value']['options'] ) ) {
			$state                       = $read['value'];
			$state['options'][ $option ] = self::scalar( $value );
			$state['revision']           = wp_generate_uuid4();
			self::replace_stored( self::STATE_OPTION, $read['raw'], $state );
		}
	}

	private static function option_change( string $option, mixed $old_value, mixed $value ): string {
		return sprintf( 'Setting %s changed from "%s" to "%s"', $option, self::scalar( $old_value ), self::scalar( $value ) );
	}

	/** Option value as a short string for alerts; these options are all scalar in core. */
	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? mb_substr( (string) $value, 0, 200 ) : '(' . gettype( $value ) . ')';
	}

	/** Explicit diagnostic snapshot; never used by request hooks or the resumable worker.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function snapshot(): array|WP_Error {
		$roles = self::raw_roles();
		if ( is_wp_error( $roles ) ) {
			return $roles;
		}
		$budget     = new WorkBudget();
		$cursor     = 0;
		$privileges = [];
		do {
			$rows = self::user_page( $cursor );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['ID'];
				$entry  = self::resolve_user( $row, $roles );
				if ( $entry['caps'] ) {
					$privileges[ $cursor ] = $entry;
				}
			}
			$more = count( $rows ) === self::PAGE_SIZE;
			if ( $more && $budget->exhausted() ) {
				return new WP_Error( 'watch_snapshot_budget', __( 'The inventory needs more time. Run a background check to finish it in batches.', 'shouse' ) );
			}
		} while ( $more );
		return $this->inventory( $privileges, $roles );
	}

	/**
	 * @param array<int, array<string, mixed>> $privileges
	 * @param array<string, mixed> $roles
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function inventory( array $privileges, array $roles ): array|WP_Error {
		$options = self::raw_options();
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		$active = get_option( 'active_plugins', [] );
		$config = $this->config_path();
		return [
			'admins'         => self::admins( $privileges ),
			'privileges'     => $privileges,
			'roles'          => self::role_privileges( $roles ),
			'plugins'        => self::entries( WP_PLUGIN_DIR ),
			'active_plugins' => is_array( $active ) ? array_values( $active ) : [],
			'themes'         => self::entries( get_theme_root() ),
			'mu_plugins'     => self::entries( WPMU_PLUGIN_DIR ),
			'dropins'        => array_values( array_filter( self::DROPINS, static fn( $file ) => file_exists( WP_CONTENT_DIR . '/' . $file ) ) ),
			'config_hash'    => $config ? (string) hash_file( 'sha256', $config ) : '',
			'options'        => $options,
		];
	}

	public function cron_check(): void {
		$this->reconcile();
	}

	/** One bounded slice. An incomplete scan never changes the accepted baseline.
	 *
	 * @return string[]|WP_Error
	 */
	public function check(): array|WP_Error {
		return $this->reconcile( false );
	}

	/**
	 * @return true|WP_Error */
	public function accept(): bool|WP_Error {
		$result = $this->reconcile( true );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @return string[]|WP_Error */
	private function reconcile( ?bool $accept = null ): array|WP_Error {
		$this->delivery_queued = true;
		$this->pending         = true;
		$budget                = new WorkBudget();
		$token                 = $this->claim();
		if ( ! $token ) {
			$this->schedule();
			return [];
		}
		try {
			return $this->scan_slice( $accept, $budget, $token );
		} finally {
			$this->release( $token );
			if ( $this->pending ) {
				$this->schedule();
			}
		}
	}

	/**
	 * @return string[]|WP_Error */
	private function scan_slice( ?bool $accept, WorkBudget $budget, string $token ): array|WP_Error {
		$before     = self::read_stored( self::STATE_OPTION );
		$stored_job = self::read_stored( self::SCAN_OPTION );
		$roles      = self::raw_roles();
		if ( is_wp_error( $before ) || is_wp_error( $stored_job ) || is_wp_error( $roles ) ) {
			return self::read_error();
		}
		// Probe all database inputs before staging work, even when this slice is not the last one.
		$options = self::raw_options();
		if ( is_wp_error( $options ) ) {
			return $options;
		}
		$epoch = self::read_stored( self::EPOCH_OPTION );
		if ( is_wp_error( $epoch ) ) {
			return $epoch;
		}
		if ( null === $epoch['raw'] ) {
			add_option( self::EPOCH_OPTION, wp_generate_uuid4(), '', false );
			$epoch = self::read_stored( self::EPOCH_OPTION );
			if ( is_wp_error( $epoch ) || null === $epoch['raw'] ) {
				return self::read_error();
			}
		}
		$base_hash = hash( 'sha256', (string) $before['raw'] );
		$role_hash = hash( 'sha256', serialize( $roles ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- internal fingerprint only.
		$job       = is_array( $stored_job['value'] ) ? $stored_job['value'] : [];
		$mode      = $accept ?? (bool) ( $job['accept'] ?? false );
		if ( ! $job || $job['baseline'] !== $base_hash || $job['roles'] !== $role_hash || ( $job['epoch'] ?? '' ) !== $epoch['raw'] || ( null !== $accept && $mode !== (bool) $job['accept'] ) ) {
			global $wpdb;
			$upper = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bound this inventory before new customers arrive.
			if ( self::query_failed() ) {
				return self::read_error();
			}
			$job = [
				'baseline'   => $base_hash,
				'roles'      => $role_hash,
				'epoch'      => $epoch['raw'],
				'accept'     => $mode,
				'cursor'     => 0,
				'upper'      => $upper,
				'privileges' => [],
			];
		}
		$processed = 0;
		$complete  = false;
		do {
			if ( $budget->exhausted() ) {
				break;
			}
			$rows = self::user_page( (int) $job['cursor'], (int) $job['upper'] );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $row ) {
				$job['cursor'] = (int) $row['ID'];
				$entry         = self::resolve_user( $row, $roles );
				if ( $entry['caps'] ) {
					$job['privileges'][ $job['cursor'] ] = $entry;
				}
				++$processed;
			}
			$complete = count( $rows ) < self::PAGE_SIZE || $job['cursor'] >= $job['upper'];
		} while ( ! $complete && $processed < self::SLICE_USERS );
		if ( ! $this->owns_claim( $token ) ) {
			return [];
		}
		if ( ! $complete ) {
			if ( ! self::replace_stored( self::SCAN_OPTION, $stored_job['raw'], $job ) ) {
				return self::read_error();
			}
			return [];
		}
		$now         = $this->inventory( $job['privileges'], $roles );
		$fresh_roles = self::raw_roles();
		$fresh_state = self::read_stored( self::STATE_OPTION );
		$fresh_epoch = self::read_stored( self::EPOCH_OPTION );
		if ( is_wp_error( $now ) || is_wp_error( $fresh_roles ) || is_wp_error( $fresh_state ) || is_wp_error( $fresh_epoch ) ) {
			return self::read_error();
		}
		if ( $fresh_roles !== $roles || $fresh_state['raw'] !== $before['raw'] || $fresh_epoch['raw'] !== $epoch['raw'] ) {
			return []; // A targeted event won: restart rather than overwrite its accepted evidence.
		}
		$old     = is_array( $before['value'] ) ? $before['value'] : [];
		$changes = [];
		if ( $old && ! $mode ) {
			[ $changes, $old_admin_email ] = self::inventory_changes( $old, $now );
			$this->delivery_queued         = $this->report( $changes, $old_admin_email );
			if ( ! $this->delivery_queued ) {
				return $changes;
			}
		}
		if ( ! self::publish_state( $before['raw'], $now, $epoch['raw'] ) ) {
			return $changes; // Concurrent targeted change: leave it in place and retry later.
		}
		self::remove_stored( self::SCAN_OPTION, $stored_job['raw'] );
		$this->pending = false;
		if ( $mode ) {
			Log::add( 'inventory_accepted', 'Current plugins, privileges, files and site settings accepted as the baseline' );
		}
		return $changes;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $now
	 *
	 * @return array{0: list<string>, 1: string}
	 */
	private static function inventory_changes( array $before, array $now ): array {
		$changes = [];
		$labels  = [
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
		if ( isset( $before['privileges'] ) ) {
			$changes = array_merge( $changes, self::privilege_changes( $before['privileges'], $now['privileges'] ) );
		}
		if ( isset( $before['roles'] ) ) {
			$changes = array_merge( $changes, self::role_changes( $before['roles'], $now['roles'] ) );
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

		return [ $changes, $old_admin_email ];
	}

	/**
	 * @param string[] $changes */
	private function report( array $changes, string $old_admin_email = '' ): bool {
		if ( ! $changes ) {
			return true;
		}
		$critical = (bool) preg_grep( '/^(Privileges granted|Role privileges granted|Must-use plugin added|Drop-in added|wp-config|Setting )/', $changes );
		Log::add( 'inventory_changed', implode( '; ', $changes ), [], $critical ? 'critical' : 'warning' );
		return Notify::send( 'changes detected', array_merge( [ 'SafeHouse noticed these changes since the last check:', '' ], array_map( static fn( $c ) => '- ' . $c, $changes ) ), $old_admin_email );
	}

	public function tasks(): array {
		return [
			'check'  => __( 'Check now', 'shouse' ),
			'accept' => __( 'Accept current state', 'shouse' ),
		];
	}

	public function handle_task( string $task ): string {
		if ( 'accept' === $task ) {
			$result = $this->accept();
			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}
			return $this->pending ? __( 'The inventory is continuing in the background. The previous baseline stays in place until it finishes.', 'shouse' ) : __( 'The current state is the new baseline.', 'shouse' );
		}
		$changes = $this->check();
		if ( is_wp_error( $changes ) ) {
			return $changes->get_error_message();
		}
		if ( ! $this->delivery_queued ) {
			return __( 'Changes detected, but the alert could not be stored. The previous baseline was retained. Check database access and alert recipients.', 'shouse' );
		}
		if ( $this->pending ) {
			return __( 'The inventory is continuing in the background. The previous baseline stays in place until it finishes.', 'shouse' );
		}
		/* translators: %d: number of changes. */
		return $changes ? sprintf( _n( '%d change found and reported.', '%d changes found and reported.', count( $changes ), 'shouse' ), count( $changes ) ) : __( 'No changes since the last check.', 'shouse' );
	}

	public function render_panel(): void {
		$state = $this->stored_state();
		echo '<p class="shouse-panel">';
		if ( ! $state ) {
			esc_html_e( 'No baseline yet. It will be recorded by the scheduled background check.', 'shouse' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: number of privileged accounts, 2: number of plugins, 3: number of mu-plugins, 4: number of drop-ins, 5: number of site settings. */
					__( 'Watching %1$d privileged accounts, %2$d plugins, %3$d must-use plugins, %4$d drop-ins, wp-config.php and %5$d site settings.', 'shouse' ),
					count( (array) ( $state['privileges'] ?? $state['admins'] ) ),
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
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function cli( array $args ): void {
		$accept  = 'accept' === $args[0];
		$changes = [];
		// CLI explicitly drives up to 100 bounded slices. Remaining work retains its checkpoint.
		for ( $slice = 0; $slice < 100; ++$slice ) {
			$result = $this->reconcile( $accept );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$changes = array_merge( $changes, $result );
			if ( ! $this->delivery_queued ) {
				WP_CLI::error( 'Alert could not be stored. The previous baseline was retained. Check database access and alert recipients.' );
			}
			if ( ! $this->pending ) {
				foreach ( $changes as $change ) {
					WP_CLI::log( $change );
				}
				WP_CLI::success( $accept ? 'Baseline updated.' : ( $changes ? count( $changes ) . ' change(s) reported.' : 'No changes.' ) );
				return;
			}
		}
		WP_CLI::error( 'Inventory is still pending. Its checkpoint was saved; run this command again or let WP-Cron continue.' );
	}

	/**
	 * Watched options as stored in the database, past any filter (Hardening filters default_role
	 * on read, which would hide a value planted straight in the table) and past the object cache.
	 *
	 *
	 * @return array<string, string>|WP_Error
	 */
	private static function raw_options(): array|WP_Error {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( self::OPTIONS ), '%s' ) );
		$rows         = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)", self::OPTIONS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the uncached value is the point; placeholders built above.
		if ( self::query_failed() ) {
			return self::read_error();
		}
		$options = array_fill_keys( self::OPTIONS, '' );
		foreach ( $rows as $row ) {
			$options[ (string) $row->option_name ] = self::scalar( $row->option_value );
		}
		return $options;
	}

	/**
	 * @return array<string, mixed>|WP_Error */
	private static function raw_roles(): array|WP_Error {
		global $wpdb;
		$roles = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->get_blog_prefix() . 'user_roles' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- stored capabilities must bypass filters and caches.
		return self::query_failed() ? self::read_error() : ( is_array( $roles ) ? $roles : [] );
	}

	/**
	 * @return list<array<string, mixed>>|WP_Error */
	private static function user_page( int $cursor, int $upper = PHP_INT_MAX ): array|WP_Error {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT u.ID, u.user_login, m.meta_value FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON u.ID = m.user_id WHERE m.meta_key = %s AND u.ID > %d AND u.ID <= %d ORDER BY u.ID LIMIT %d", $wpdb->get_blog_prefix() . 'capabilities', $cursor, $upper, self::PAGE_SIZE ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded uncached reconciliation.
		return self::query_failed() ? self::read_error() : $rows;
	}

	/**
	 * @return array{login: string, caps: list<string>}|WP_Error */
	private static function user_privileges( int $user_id ): array|WP_Error {
		global $wpdb;
		$roles = self::raw_roles();
		if ( is_wp_error( $roles ) ) {
			return $roles;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT u.ID, u.user_login, m.meta_value FROM {$wpdb->users} u LEFT JOIN {$wpdb->usermeta} m ON u.ID = m.user_id AND m.meta_key = %s WHERE u.ID = %d LIMIT 1", $wpdb->get_blog_prefix() . 'capabilities', $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one affected account, not an inventory.
		if ( self::query_failed() ) {
			return self::read_error();
		}
		return self::resolve_user(
			is_array( $row ) ? $row : [
				'user_login' => '#' . $user_id,
				'meta_value' => [],
			],
			$roles
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $roles
	 *
	 * @return array{login: string, caps: list<string>}
	 */
	private static function resolve_user( array $row, array $roles ): array {
		$assigned = maybe_unserialize( $row['meta_value'] );
		$assigned = is_array( $assigned ) ? $assigned : [];
		$all      = [];
		foreach ( array_keys( $assigned ) as $role ) {
			if ( isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ) {
				$all = array_merge( $all, $roles[ $role ]['capabilities'] );
			}
		}
		$all = array_merge( $all, $assigned );
		return [
			'login' => (string) $row['user_login'],
			'caps'  => array_values( array_filter( self::CAPS, static fn( $cap ) => ! empty( $all[ $cap ] ) ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $privileges
	 * @return array<int, string> */
	private static function admins( array $privileges ): array {
		$admins = [];
		foreach ( $privileges as $id => $entry ) {
			if ( in_array( 'manage_options', $entry['caps'], true ) ) {
				$admins[ $id ] = $entry['login'];
			}
		}
		return $admins;
	}

	/**
	 * @param array<string, mixed> $roles
	 * @return array<string, list<string>> */
	private static function role_privileges( array $roles ): array {
		$out = [];
		foreach ( $roles as $name => $role ) {
			$caps    = is_array( $role ) && is_array( $role['capabilities'] ?? null ) ? $role['capabilities'] : [];
			$watched = array_values( array_filter( self::CAPS, static fn( $cap ) => ! empty( $caps[ $cap ] ) ) );
			if ( $watched ) {
				$out[ $name ] = $watched;
			}
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $before
	 * @param array<int, array<string, mixed>> $now
	 * @return list<string> */
	private static function privilege_changes( array $before, array $now ): array {
		$changes = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $now ) ) ) as $id ) {
			$old     = $before[ $id ] ?? [
				'login' => '#' . $id,
				'caps'  => [],
			];
			$new     = $now[ $id ] ?? [
				'login' => $old['login'],
				'caps'  => [],
			];
			$added   = array_diff( $new['caps'], $old['caps'] );
			$removed = array_diff( $old['caps'], $new['caps'] );
			if ( $added ) {
				$changes[] = 'Privileges granted to ' . $new['login'] . ' (#' . $id . '): ' . implode( ', ', $added );
			}
			if ( $removed ) {
				$changes[] = 'Privileges removed from ' . $old['login'] . ' (#' . $id . '): ' . implode( ', ', $removed );
			}
		}
		return $changes;
	}

	/**
	 * @param array<string, list<string>> $before
	 * @param array<string, list<string>> $now
	 * @return list<string> */
	private static function role_changes( array $before, array $now ): array {
		$changes = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $now ) ) ) as $name ) {
			$added   = array_diff( $now[ $name ] ?? [], $before[ $name ] ?? [] );
			$removed = array_diff( $before[ $name ] ?? [], $now[ $name ] ?? [] );
			if ( $added ) {
				$changes[] = 'Role privileges granted to ' . $name . ': ' . implode( ', ', $added );
			}
			if ( $removed ) {
				$changes[] = 'Role privileges removed from ' . $name . ': ' . implode( ', ', $removed );
			}
		}
		return $changes;
	}

	/** Invalidate even a grant+revoke that returns the baseline to its previous value. */
	private function invalidate_scan(): void {
		update_option( self::EPOCH_OPTION, wp_generate_uuid4(), false );
	}

	/**
	 * @param array<string, mixed> $value Complete baseline, guarded by the sensitive-event epoch. */
	private static function publish_state( ?string $before, array $value, string $epoch ): bool {
		global $wpdb;
		// A unique revision makes successful publication observable even if the inventory is unchanged.
		$value['revision'] = wp_generate_uuid4();
		$after             = maybe_serialize( $value );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- check baseline and event epoch in one atomic statement.
		if ( null === $before ) {
			$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'off' FROM {$wpdb->options} epoch WHERE epoch.option_name = %s AND BINARY epoch.option_value = BINARY %s", self::STATE_OPTION, $after, self::EPOCH_OPTION, $epoch ) );
		} else {
			$written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} state JOIN {$wpdb->options} epoch ON epoch.option_name = %s SET state.option_value = %s WHERE state.option_name = %s AND BINARY state.option_value = BINARY %s AND BINARY epoch.option_value = BINARY %s", self::EPOCH_OPTION, $after, self::STATE_OPTION, $before, $epoch ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		self::clear_option_cache( self::STATE_OPTION );
		return 1 === $written;
	}

	private function schedule(): void {
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK );
		}
	}

	/**
	 * @return array{raw: ?string, value: mixed}|WP_Error */
	private static function read_stored( string $name ): array|WP_Error {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- get_var conflates an existing empty string with a missing row.
		$raw = is_array( $row ) ? (string) $row['option_value'] : null;
		return self::query_failed() ? self::read_error() : [
			'raw'   => $raw,
			'value' => null === $raw ? null : maybe_unserialize( $raw ),
		];
	}

	/**
	 * @param mixed $value Replacement guarded against concurrent metadata/option events. */
	private static function replace_stored( string $name, ?string $before, mixed $value ): bool {
		global $wpdb;
		$after = maybe_serialize( $value );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic baseline/checkpoint replacement.
		if ( null === $before ) {
			$written = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $after ) );
		} else {
			$written = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $after, $name, $before ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		self::clear_option_cache( $name );
		if ( $before === $after && 0 === $written ) {
			$current = self::read_stored( $name );
			return ! is_wp_error( $current ) && $current['raw'] === $before;
		}
		return 1 === $written;
	}

	private static function remove_stored( string $name, ?string $before ): void {
		if ( null === $before ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $name, $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release only the version this worker owns.
		self::clear_option_cache( $name );
	}

	private static function clear_option_cache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private function claim(): string {
		$read = self::read_stored( self::LOCK_OPTION );
		if ( is_wp_error( $read ) || ( is_array( $read['value'] ) && (int) $read['value']['until'] > time() ) ) {
			return '';
		}
		$token = wp_generate_uuid4();
		return self::replace_stored(
			self::LOCK_OPTION,
			$read['raw'],
			[
				'token' => $token,
				'until' => time() + 60,
			]
		) ? $token : '';
	}

	private function owns_claim( string $token ): bool {
		$read = self::read_stored( self::LOCK_OPTION );
		return ! is_wp_error( $read ) && is_array( $read['value'] ) && $token === $read['value']['token'] && (int) $read['value']['until'] > time();
	}

	private function release( string $token ): void {
		$read = self::read_stored( self::LOCK_OPTION );
		if ( ! is_wp_error( $read ) && is_array( $read['value'] ) && $token === $read['value']['token'] ) {
			self::remove_stored( self::LOCK_OPTION, $read['raw'] );
		}
	}

	/**
	 * wpdb updates last_error on every query.
	 *
	 * @phpstan-impure
	 */
	private static function query_failed(): bool {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}

	private static function read_error(): WP_Error {
		return new WP_Error( 'watch_read_failed', __( 'Monitoring could not read the current state from the database. The previous baseline was retained. Check database access and try again.', 'shouse' ) );
	}

	/**
	 * @return array<string, mixed> */
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
