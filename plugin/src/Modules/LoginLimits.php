<?php
/**
 * Login limits: brute-force protection for wp-login.php, the WooCommerce login form, XML-RPC and
 * application passwords.
 *
 * By address: too many failures from one IP (IPv6 by /64) lock it out, longer each time.
 * By account, without locking the owner out: after a successful login the browser gets a signed
 * device cookie for that account. When an account collects too many failures from browsers without
 * it, only new devices are paused; devices that logged in before keep working (OWASP "device
 * cookies"). Existing accounts share a counter by user ID across login names and email addresses;
 * unknown names have pseudonymous counters too, so a pause alone does not reveal an account.
 *
 * Safety: an address is blocked only when it is the visitor's. A connection from Cloudflare without
 * the Cloudflare setting, or from a private address (an unknown proxy or load balancer), would put
 * every visitor behind one address; then only the account rule applies. Both facts come from the
 * TCP peer, which no request header can fake.
 *
 * Stands down while Wordfence's brute-force protection is on.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use WP_Error;
use WP_User;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use SafeHouse\Core\Log;
use SafeHouse\Core\Net;
use SafeHouse\Core\Notify;
use SafeHouse\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class LoginLimits extends AbstractModule {

	/** The address is locked out. Refusals with this code are not counted again (that would never end). */
	public const ERROR_CODE = 'shouse_locked';

	/** The account is paused for new devices. Refusals with this code still count against the address. */
	public const PAUSED_CODE = 'shouse_paused';

	private const DB_VERSION     = '1';
	private const DB_OPTION      = 'shouse_login_db_version';
	private const COOKIE         = 'shouse_device_';
	private const LEGACY_COOKIE  = 'wphouse_device_'; // Issued before the rename to SafeHouse; still accepted until it expires.
	private const COOKIE_DAYS    = 180;
	private const ACCOUNT_WINDOW = HOUR_IN_SECONDS;
	private const ACCOUNT_PAUSE  = HOUR_IN_SECONDS;
	private const MAX_LOCKOUT    = DAY_IN_SECONDS;
	private const KEEP_ROWS      = 30 * DAY_IN_SECONDS;

	public function id(): string {
		return 'login_limits';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [
			'ip_attempts'      => 5,
			'ip_window'        => 15,
			'ip_lockout'       => 15,
			'account_attempts' => 10,
			'allowlist'        => '',
			'notify'           => true,
		];
	}

	public function label(): string {
		return __( 'Login limits', 'shouse' );
	}

	public function description(): string {
		return __( 'Stops password guessing on the login forms, XML-RPC and application passwords. Too many failures from one address lock it out, longer each time. An account under attack is paused only for devices that never logged into it; devices that did keep working, so nobody can lock the owner out.', 'shouse' );
	}

	public function fields(): array {
		return [
			'ip_attempts'      => [
				'type'  => 'number',
				'label' => __( 'Failures before an address is locked out', 'shouse' ),
				'min'   => 2,
				'max'   => 50,
			],
			'ip_window'        => [
				'type'  => 'number',
				'label' => __( 'Counted over (minutes)', 'shouse' ),
				'min'   => 1,
				'max'   => 1440,
			],
			'ip_lockout'       => [
				'type'  => 'number',
				'label' => __( 'First lockout (minutes)', 'shouse' ),
				'help'  => __( 'Each further lockout of the same address lasts four times longer, up to 24 hours.', 'shouse' ),
				'min'   => 1,
				'max'   => 1440,
			],
			'account_attempts' => [
				'type'  => 'number',
				'label' => __( 'Failures per account in an hour before new devices are paused', 'shouse' ),
				'min'   => 3,
				'max'   => 100,
			],
			'allowlist'        => [
				'type'       => 'textarea',
				'label'      => __( 'Never lock out these addresses', 'shouse' ),
				'help'       => __( 'One IP address or range per line, for example 203.0.113.7 or 198.51.100.0/24.', 'shouse' ),
				'max_length' => 2000,
			],
			'notify'           => [
				'type'  => 'toggle',
				'label' => __( 'E-mail when an account is paused', 'shouse' ),
			],
		];
	}

	public function available(): bool {
		return ! Compat::wordfence_on( 'loginSecurityEnabled' ) || Compat::ignore_overlaps();
	}

	public function sanitize( array $input, array $old ): array {
		$clean = parent::sanitize( $input, $old );
		$raw   = is_string( $input['allowlist'] ?? '' ) ? ( $input['allowlist'] ?? '' ) : 'invalid';
		if ( strlen( $raw ) > 2000 ) {
			add_settings_error( Settings::OPTION, 'shouse_invalid_allowlist', __( 'The login allowlist is too long. The previous allowlist was kept.', 'shouse' ) );
			$clean['allowlist'] = $old['allowlist'] ?? '';
			return $clean;
		}
		$lines = (array) preg_split( '/\R/', $raw );
		foreach ( $lines as $index => $line ) {
			if ( '' !== trim( $line ) && ! Net::valid_range( trim( $line ) ) ) {
				add_settings_error(
					Settings::OPTION,
					'shouse_invalid_allowlist',
					/* translators: %d: line number. */
					sprintf( __( 'Login allowlist line %d is not a valid IP address or CIDR range. The previous allowlist was kept.', 'shouse' ), $index + 1 )
				);
				$clean['allowlist'] = $old['allowlist'] ?? '';
				break;
			}
		}
		return $clean;
	}

	public function unavailable_reason(): string {
		return __( 'Wordfence brute force protection is on and already limits login attempts.', 'shouse' );
	}

	public function boot(): void {
		self::maybe_install();
		// Core's username/email callbacks can replace an earlier authenticate error. This hook is
		// inside each callback, immediately before wp_check_password(), and does respect WP_Error.
		add_filter( 'wp_authenticate_user', [ $this, 'refuse_before_password' ], PHP_INT_MAX );
		add_filter( 'authenticate', [ $this, 'refuse_locked' ], 100, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', [ $this, 'application_passwords_available' ], PHP_INT_MAX );
		add_action( 'wp_login_failed', [ $this, 'login_failed' ], 10, 2 );
		add_action( 'application_password_failed_authentication', [ $this, 'application_password_failed' ] );
		// REST Basic auth checks application passwords without the authenticate filter; this is its hook.
		add_action( 'wp_authenticate_application_password_errors', [ $this, 'refuse_locked_application_password' ] );
		add_action( 'wp_login', [ $this, 'login_succeeded' ], 10, 2 );
		add_action( 'shouse_daily', [ $this, 'purge' ] );
		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse login', LoginLimitsCommand::class );
		}
	}

	/** Preserve errors from other authentication providers, including second-factor plugins. */
	public function refuse_before_password( mixed $user ): mixed {
		return $user instanceof WP_User ? $this->refuse_locked( $user, $user->user_login ) : $user;
	}

	/** Core checks availability before reading or hashing application passwords (also on REST). */
	public function application_passwords_available( bool $available ): bool {
		$ip = $this->allowlisted() ? null : $this->ip_subject();
		return $available && ( null === $ip || 0 === $this->locked_for( 'ip', $ip ) );
	}

	/**
	 * Refuse a login from a locked address, or a paused account on a new device, even with the right password.
	 *
	 * @param mixed $user     WP_User, WP_Error or null so far.
	 * @param mixed $username Submitted name.
	 * @param mixed $password Submitted password.
	 */
	public function refuse_locked( mixed $user, mixed $username = '', mixed $password = '' ): mixed {
		$username = is_string( $username ) ? trim( $username ) : '';
		if ( '' === $username || $this->allowlisted() ) {
			return $user; // Not a form or Basic-auth login (cookies, other flows), or a trusted address.
		}
		$ip = $this->ip_subject();
		if ( null !== $ip ) {
			$left = $this->locked_for( 'ip', $ip );
			if ( $left > 0 ) {
				/* translators: %d: minutes. */
				return new WP_Error( self::ERROR_CODE, sprintf( _n( 'Too many failed login attempts from your address. Try again in %d minute.', 'Too many failed login attempts from your address. Try again in %d minutes.', (int) ceil( $left / 60 ), 'shouse' ), (int) ceil( $left / 60 ) ) );
			}
		}
		$accounts = $user instanceof WP_User ? [ $user ] : self::account_candidates( $username );
		foreach ( $accounts as $account ) {
			if ( $this->has_device_cookie( $account ) ) {
				continue;
			}
			foreach ( self::account_keys( $account ) as $key ) {
				if ( $this->locked_for( 'user', $key ) > 0 ) {
					return new WP_Error( self::PAUSED_CODE, __( 'This account is paused for new devices after many failed attempts. Log in from a device you used before, or try again in an hour.', 'shouse' ) );
				}
			}
		}
		return $user;
	}

	/**
	 * @param string        $username Submitted name.
	 * @param WP_Error|null $error    Why it failed.
	 */
	public function login_failed( string $username, mixed $error = null ): void {
		$codes = $error instanceof WP_Error ? $error->get_error_codes() : [];
		if ( in_array( self::ERROR_CODE, $codes, true ) ) {
			return; // Refused by the address lockout itself: counting it would extend the lockout forever.
		}
		// Hammering a paused account still counts against the address, but not against the account again.
		$this->count_failure( trim( $username ), ! in_array( self::PAUSED_CODE, $codes, true ) );
	}

	public function application_password_failed( mixed $error = null ): void {
		// Invalid passwords never reach wp_authenticate_application_password_errors. Check here too,
		// before counting, and attach the same error for early availability refusals.
		if ( $error instanceof WP_Error ) {
			$this->refuse_locked_application_password( $error );
		}
		if ( $error instanceof WP_Error && in_array( self::ERROR_CODE, $error->get_error_codes(), true ) ) {
			return;
		}
		$this->count_failure( '' ); // By address only: application passwords are 24 random characters, the account rule is for people.
	}

	/** A locked address cannot use application passwords either, even a valid one. */
	public function refuse_locked_application_password( WP_Error $error ): void {
		$ip = $this->allowlisted() ? null : $this->ip_subject();
		if ( null !== $ip && $this->locked_for( 'ip', $ip ) > 0 ) {
			$error->add( self::ERROR_CODE, __( 'Too many failed login attempts from your address. Try again later.', 'shouse' ), [ 'status' => 429 ] );
		}
	}

	/**
	 * Successful login: forget this address's failures and remember the device for the account.
	 * The two_factor module, when it exists, must call issue_device_cookie() after the second factor instead.
	 *
	 * @param string  $user_login Login.
	 * @param WP_User $user       User.
	 */
	public function login_succeeded( string $user_login, WP_User $user ): void {
		$ip = $this->ip_subject();
		if ( null !== $ip ) {
			$this->reset_failures( 'ip', $ip );
		}
		$this->issue_device_cookie( $user );
	}

	public function issue_device_cookie( WP_User $user ): void {
		if ( headers_sent() ) {
			return;
		}
		$expires = time() + self::COOKIE_DAYS * DAY_IN_SECONDS;
		$cookie  = self::cookie_name( $user );
		setcookie(
			$cookie,
			$expires . '.' . self::sign( $cookie . '|' . $expires ),
			[
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && constant( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/',
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}

	/** Daily: drop rows nobody has touched for a month and that lock nothing. */
	public function purge(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s AND ( locked_until IS NULL OR locked_until < %s )', self::table(), self::time( time() - self::KEEP_ROWS ), self::time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shouse_login';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(8) NOT NULL,
				subject varchar(191) NOT NULL,
				fails int(10) unsigned NOT NULL DEFAULT 0,
				window_start datetime NOT NULL,
				lockouts int(10) unsigned NOT NULL DEFAULT 0,
				locked_until datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_subject (kind,subject),
				KEY locked_until (locked_until)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Current lockouts and pauses, for the panel and WP-CLI.
	 *
	 * @return list<array{kind: string, subject: string, until: string, lockouts: int}>
	 */
	public static function active(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, subject, locked_until, lockouts FROM %i WHERE locked_until > %s ORDER BY locked_until DESC LIMIT 200', self::table(), self::time() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[] = [
				'kind'     => (string) $row['kind'],
				'subject'  => (string) $row['subject'],
				'until'    => (string) $row['locked_until'],
				'lockouts' => (int) $row['lockouts'],
			];
		}
		return $out;
	}

	/**
	 * Lift lockouts: one address or account, or all of them when $subject is ''.
	 *
	 * @return int Rows cleared.
	 */
	public static function unlock( string $subject = '' ): int {
		global $wpdb;
		if ( '' === $subject ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$subjects = [ $subject, self::ip_key( $subject ) ];
		foreach ( self::account_candidates( $subject ) as $account ) {
			$subjects = array_merge( $subjects, self::account_keys( $account ) );
		}
		$subjects = array_unique( $subjects );
		$cleared  = 0;
		foreach ( $subjects as $one ) {
			$cleared += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject = %s', self::table(), $one ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $cleared;
	}

	public function tasks(): array {
		return [ 'unlock' => __( 'Lift all lockouts', 'shouse' ) ];
	}

	public function handle_task( string $task ): string {
		$count = self::unlock();
		Log::add( 'login_unlocked', 'All login lockouts lifted from the settings page', [ 'rows' => $count ], 'warning' );
		return __( 'All lockouts lifted.', 'shouse' );
	}

	public function render_panel(): void {
		echo '<div class="shouse-panel">';
		if ( null === $this->ip_subject() && $this->unsafe_peer() ) {
			echo '<p class="shouse-note">' . esc_html( $this->unsafe_reason() ) . '</p>';
		}
		$active = self::active();
		if ( ! $active ) {
			echo '<p>' . esc_html__( 'Nothing is locked out right now.', 'shouse' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Address or account', 'shouse' ) . '</th><th>' . esc_html__( 'Until (UTC)', 'shouse' ) . '</th></tr></thead><tbody>';
		foreach ( $active as $row ) {
			$what = 'ip' === $row['kind'] ? $row['subject'] : __( 'account (new devices)', 'shouse' );
			echo '<tr><td>' . esc_html( $what ) . '</td><td>' . esc_html( $row['until'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['shouse_login_limits'] = [
			'label' => __( 'SafeHouse login limits', 'shouse' ),
			'test'  => [ $this, 'site_health_result' ],
		];
		return $tests;
	}

	/** @return array<string, mixed> */
	public function site_health_result(): array {
		$unsafe = $this->unsafe_peer();
		return [
			'label'       => $unsafe ? __( 'Login limits cannot block by address on this server', 'shouse' ) : __( 'Login limits block password guessing', 'shouse' ),
			'status'      => $unsafe ? 'critical' : 'good',
			'badge'       => [
				'label' => __( 'Security', 'shouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $unsafe ? $this->unsafe_reason() : __( 'Failed logins are counted per address and per account; addresses are locked out and accounts are paused for new devices.', 'shouse' ) ) . '</p>',
			'test'        => 'shouse_login_limits',
		];
	}

	private function count_failure( string $username, bool $count_account = true ): void {
		if ( $this->allowlisted() ) {
			return;
		}
		$ip = $this->ip_subject();
		if ( null !== $ip && $this->locked_for( 'ip', $ip ) > 0 ) {
			return; // Includes invalid application passwords and concurrent requests already in flight.
		}
		if ( null !== $ip ) {
			$row = $this->bump( 'ip', $ip, (int) $this->opt( 'ip_window' ) * MINUTE_IN_SECONDS );
			if ( $row['fails'] >= (int) $this->opt( 'ip_attempts' ) ) {
				$seconds = (int) min( self::MAX_LOCKOUT, (int) $this->opt( 'ip_lockout' ) * MINUTE_IN_SECONDS * ( 4 ** $row['lockouts'] ) );
				$this->lock( 'ip', $ip, $seconds );
				Log::add( 'login_locked', sprintf( 'Address locked out for %d minutes after failed logins', (int) ( $seconds / 60 ) ), [ 'subject' => $ip ], 'warning' );
			}
		}
		if ( ! $count_account || '' === $username ) {
			return;
		}
		foreach ( self::account_candidates( $username ) as $account ) {
			$this->count_account_failure( $account );
		}
	}

	private function count_account_failure( string|WP_User $identity ): void {
		if ( $this->has_device_cookie( $identity ) ) {
			return; // A device that logged into this account before is not part of an attack on it.
		}
		$account = self::account_key( $identity );
		$row     = $this->bump( 'user', $account, self::ACCOUNT_WINDOW );
		if ( $row['fails'] >= (int) $this->opt( 'account_attempts' ) && 0 === $this->locked_for( 'user', $account ) ) {
			$this->lock( 'user', $account, self::ACCOUNT_PAUSE );
			$user = self::account_user( $identity );
			Log::add( 'login_paused', 'Account paused for new devices after failed logins', [ 'user' => $user ? $user->user_login : '(no such account)' ], 'warning' );
			if ( $user && $this->opt( 'notify' ) ) {
				/* translators: %s: user login. */
				Notify::send( sprintf( __( 'Account %s paused for new devices', 'shouse' ), $user->user_login ), [ __( 'Many failed logins for this account came from devices that never logged into it. For an hour only devices that did can log in.', 'shouse' ) ] );
			}
		}
	}

	/**
	 * Count one failure in a sliding window and return the row after the update.
	 *
	 * @return array{fails: int, lockouts: int}
	 */
	private function bump( string $kind, string $subject, int $window ): array {
		global $wpdb;
		$now   = self::time();
		$start = self::time( time() - $window );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic upsert; the window restarts when it has passed.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (kind, subject, fails, window_start, lockouts, updated_at) VALUES (%s, %s, 1, %s, 0, %s) ON DUPLICATE KEY UPDATE fails = IF(window_start < %s, 1, fails + 1), window_start = IF(window_start < %s, VALUES(window_start), window_start), updated_at = VALUES(updated_at)', self::table(), $kind, $subject, $now, $now, $start, $start ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT fails, lockouts FROM %i WHERE kind = %s AND subject = %s', self::table(), $kind, $subject ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return [
			'fails'    => (int) ( $row['fails'] ?? 0 ),
			'lockouts' => (int) ( $row['lockouts'] ?? 0 ),
		];
	}

	private function lock( string $kind, string $subject, int $seconds ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET fails = 0, lockouts = lockouts + 1, locked_until = %s, updated_at = %s WHERE kind = %s AND subject = %s AND (locked_until IS NULL OR locked_until <= %s)', self::table(), self::time( time() + $seconds ), self::time(), $kind, $subject, self::time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Seconds left on a lockout, 0 when there is none. */
	private function locked_for( string $kind, string $subject ): int {
		global $wpdb;
		$until = $wpdb->get_var( $wpdb->prepare( 'SELECT locked_until FROM %i WHERE kind = %s AND subject = %s', self::table(), $kind, $subject ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$left  = null === $until ? 0 : strtotime( $until . ' UTC' ) - time();
		return max( 0, (int) $left );
	}

	private function reset_failures( string $kind, string $subject ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET fails = 0, updated_at = %s WHERE kind = %s AND subject = %s', self::table(), self::time(), $kind, $subject ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The address to count, or null when blocking it could block everyone (see the class comment). */
	private function ip_subject(): ?string {
		if ( $this->unsafe_peer() ) {
			return null;
		}
		$ip = Net::client_ip();
		return '' === $ip ? null : self::ip_key( $ip );
	}

	/** Every visitor would arrive from the same address: Cloudflare without the setting, or a private peer. */
	private function unsafe_peer(): bool {
		$peer = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $peer ) {
			return true;
		}
		if ( Net::from_cloudflare( $peer ) ) {
			return ! Net::behind_cloudflare();
		}
		$public = false !== filter_var( $peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		return ! $public && Net::client_ip() === $peer && ! defined( 'SHOUSE_TRUSTED_PROXIES' );
	}

	private function unsafe_reason(): string {
		$peer = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return Net::from_cloudflare( $peer )
			? __( 'Requests come through Cloudflare, but SafeHouse is not set up for it, so every visitor has a Cloudflare address. Locking one out would lock out everyone; only the per-account rule works. In SafeHouse → General, set "Proxy in front of the site" to Cloudflare.', 'shouse' )
			: __( 'Requests come from a private address, so a proxy or load balancer sits in front of the site and every visitor has its address. Locking one out would lock out everyone; only the per-account rule works. Name the proxy in wp-config.php with SHOUSE_TRUSTED_PROXIES.', 'shouse' );
	}

	private function allowlisted(): bool {
		$ip = Net::client_ip();
		if ( '' === $ip ) {
			return false;
		}
		$lines = (array) preg_split( '/\R/', (string) $this->opt( 'allowlist' ) );
		if ( defined( 'SHOUSE_LOGIN_ALLOWLIST' ) && is_array( SHOUSE_LOGIN_ALLOWLIST ) ) {
			$lines = array_merge( $lines, SHOUSE_LOGIN_ALLOWLIST );
		}
		foreach ( $lines as $range ) {
			$range = trim( (string) $range );
			if ( '' !== $range && Net::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	private function has_device_cookie( string|WP_User $username ): bool {
		$user    = self::account_user( $username );
		$cookies = [ self::cookie_name( $username ) ];
		// Keep already issued device cookies working across the identity-key migration.
		if ( $user ) {
			foreach ( [ self::COOKIE, self::LEGACY_COOKIE ] as $prefix ) {
				foreach ( [ $user->user_login, $user->user_email ] as $name ) {
					$cookies[] = $prefix . substr( self::sign( 'name|' . strtolower( trim( $name ) ) ), 0, 20 );
				}
			}
		}
		foreach ( array_unique( $cookies ) as $cookie ) {
			$value = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
			if ( preg_match( '/^(\d+)\.([a-f0-9]{64})$/', $value, $m ) && (int) $m[1] >= time() && hash_equals( self::sign( $cookie . '|' . $m[1] ), $m[2] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Cookie name per account, keyed so it does not reveal the account. */
	private static function cookie_name( string|WP_User $username ): string {
		return self::COOKIE . substr( self::sign( 'device|' . self::account_key( $username ) ), 0, 20 );
	}

	private static function sign( string $data ): string {
		return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
	}

	/** Use the database's canonical identity, including its collation, without storing plain IDs. */
	private static function account_key( string|WP_User $username ): string {
		$user = self::account_user( $username );
		$name = $user ? 'account-id|' . $user->ID : 'account|' . strtolower( trim( (string) $username ) );
		return 'u:' . substr( self::sign( $name ), 0, 40 );
	}

	/**
	 * Keep active pauses issued before the ID-key migration until their normal expiry.
	 *
	 * @return list<string>
	 */
	private static function account_keys( string|WP_User $username ): array {
		$keys = [ self::account_key( $username ) ];
		$user = self::account_user( $username );
		if ( $user ) {
			foreach ( [ $user->user_login, $user->user_email ] as $name ) {
				$keys[] = 'u:' . substr( self::sign( 'account|' . strtolower( trim( $name ) ) ), 0, 40 );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * A login can also be another user's email; core may attempt both identities.
	 *
	 * @return list<WP_User|string>
	 */
	private static function account_candidates( string $username ): array {
		$users = [];
		foreach ( [ 'login', 'email' ] as $field ) {
			$user = 'email' !== $field || is_email( $username ) ? get_user_by( $field, $username ) : false;
			if ( $user ) {
				$users[ $user->ID ] = $user;
			}
		}
		return $users ? array_values( $users ) : [ $username ];
	}

	private static function account_user( string|WP_User $username ): WP_User|false {
		if ( $username instanceof WP_User ) {
			return $username;
		}
		$username = trim( $username );
		$user     = get_user_by( 'login', $username );
		return $user ? $user : ( is_email( $username ) ? get_user_by( 'email', $username ) : false );
	}

	/** IPv4 as is; IPv6 by its /64, which one attacker usually controls whole. */
	private static function ip_key( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$bin = (string) inet_pton( $ip );
		return (string) inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	private static function time( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp ?? time() );
	}
}
