<?php
/**
 * LiteSpeed page cache without the LiteSpeed Cache plugin. The server keeps the pages; SafeHouse only
 * marks which responses may be cached and asks the server to drop them after changes. No files, no
 * .htaccess writes. On servers that are not LiteSpeed the headers do nothing.
 *
 * What is cached: GET/HEAD front-end pages with status 200 for visitors who are not logged in and
 * have no cart, comment or password cookie, unless the page set a cookie, sent no-cache, defined
 * DONOTCACHEPAGE (WooCommerce does for cart, checkout and account) or matches an excluded path.
 *
 * Public caching requires a server-side cookie vary rule, confirmed by LSCACHE_VARY_COOKIE. PHP
 * response headers alone do not provide this guarantee on OpenLiteSpeed. The rule also protects
 * sessions predating activation or an expired supplementary cookie; Site Health tests the bypass.
 * The supplementary cookie is renewed on login, comments, password entry and cart changes.
 *
 * Purges: after content changes the next response carries `X-LiteSpeed-Purge: tag=shouse`. Changes
 * made where no response can carry it (WP-CLI, cron after the response) are queued and sent with the
 * next request WordPress handles; WP-CLI also requests admin-ajax.php to make that happen at once.
 * (Not wp-cron.php: it finishes its response before WordPress loads.)
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use SafeHouse\Core\ContentChanges;
use SafeHouse\Core\Log;
use SafeHouse\Core\SafeMode;

defined( 'ABSPATH' ) || exit;

final class LiteSpeed extends AbstractModule {

	private const QUEUE             = 'shouse_litespeed_purge';
	private const VARY              = '_lscache_vary';
	private const TAG               = 'shouse';
	private const FORMAT            = 'shouse_litespeed_cache_format';
	private static bool $purge_sent = false;

	/** Request cookies (name prefixes) that mean the page is personal and must not come from or go to the cache. */
	private const PRIVATE_COOKIES = [ self::VARY, 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ];

	/** The receiver remains available when the caching module is off or safe mode is active. */
	public static function register(): void {
		add_action( 'init', [ self::class, 'receive_purge' ], 0 );
		add_action( 'shutdown', [ self::class, 'finish_purge' ], PHP_INT_MAX );
	}

	public function id(): string {
		return 'litespeed';
	}

	public function defaults(): array {
		return [
			'ttl'         => 240,
			'never_cache' => '',
		];
	}

	public function label(): string {
		return __( 'LiteSpeed page cache', 'shouse' );
	}

	public function description(): string {
		return __( 'On LiteSpeed servers, pages for visitors who are not logged in and have no cart are served from the server cache without running WordPress. SafeHouse only sends cache headers and clears the cache after changes; it writes no files. Logged-in users, the cart, checkout and account pages are never cached.', 'shouse' );
	}

	public function fields(): array {
		return [
			'ttl'         => [
				'type'  => 'number',
				'label' => __( 'Keep pages for (minutes)', 'shouse' ),
				'help'  => __( 'At most 8 hours, so forms on cached pages still carry a valid security token. Changes clear the cache anyway.', 'shouse' ),
				'min'   => 5,
				'max'   => 480,
			],
			'never_cache' => [
				'type'       => 'textarea',
				'label'      => __( 'Never cache these paths', 'shouse' ),
				'help'       => __( 'One per line, for example /contact/. A line matches every address that starts with it.', 'shouse' ),
				'max_length' => 2000,
			],
		];
	}

	public function available(): bool {
		return ! Compat::litespeed_cache_active() || Compat::ignore_overlaps();
	}

	public function unavailable_reason(): string {
		return __( 'The LiteSpeed Cache plugin is active and manages the server cache itself.', 'shouse' );
	}

	public function boot(): void {
		if ( '3' !== get_option( self::FORMAT ) ) {
			$this->purge(); // Previously cached responses did not carry the complete cookie vary list.
			update_option( self::FORMAT, '3', false );
		}
		add_action( 'init', [ $this, 'on_init' ], 1 );
		add_action( 'template_redirect', [ $this, 'start' ], PHP_INT_MAX );

		add_action( 'set_logged_in_cookie', [ $this, 'logged_in' ], 10, 3 );
		add_action( 'clear_auth_cookie', [ $this, 'vary_off' ] );
		add_action( 'set_comment_cookies', [ $this, 'commented' ] );
		add_action( 'woocommerce_set_cart_cookies', [ $this, 'cart_cookies' ] );
		add_filter( 'post_password_expires', [ $this, 'post_password_expires' ], PHP_INT_MAX );

		// Any change clears all of this site's LiteSpeed pages: tag purges are cheap, unlike Cloudflare's.
		add_action( ContentChanges::ACTION, [ $this, 'purge' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse cache purge', [ $this, 'cli_purge' ] );
		}
	}

	/** Renew personal visitors' supplementary cookie; explicit varies also protect existing sessions. */
	public function on_init(): void {
		if ( is_user_logged_in() || $this->personal_cookies( false ) ) {
			$this->vary_on( time() + 2 * DAY_IN_SECONDS );
		}
	}

	public static function receive_purge(): void {
		if ( SafeMode::active() && get_option( self::FORMAT ) ) {
			self::queue_purge();
		}
		$pending = get_option( self::QUEUE );
		if ( ! $pending || ! self::web_request() || headers_sent() ) {
			return;
		}
		header( 'X-LiteSpeed-Purge: tag=' . self::TAG );
		self::$purge_sent = true;
		global $wpdb;
		// A concurrent content change replaces the token and must remain queued for another response.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::QUEUE, (string) $pending ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- conditional acknowledgement cannot use delete_option().
		wp_cache_delete( self::QUEUE, 'options' );
	}

	/** CLI changes need a web response even after the command switched the module or safe mode off. */
	public static function finish_purge(): void {
		if ( ! self::$purge_sent && SafeMode::active() && get_option( self::FORMAT ) ) {
			self::queue_purge();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && get_option( self::QUEUE ) ) {
			wp_remote_get(
				admin_url( 'admin-ajax.php' ),
				[
					'blocking'    => false,
					'timeout'     => 3,
					'redirection' => 0,
				]
			);
		}
	}

	private static function queue_purge(): void {
		update_option( self::QUEUE, wp_generate_uuid4(), false );
	}

	/** Buffer a page that may be cacheable; the final decision is made once the page is complete. */
	public function start(): void {
		if ( ! self::server_varies_ready() || ! $this->cacheable_request() ) {
			self::no_cache();
			return;
		}
		ob_start( [ $this, 'finish' ] );
	}

	/** Output-buffer callback: mark the page public only if nothing during rendering made it personal. */
	public function finish( string $buffer ): string {
		if ( headers_sent() ) {
			return $buffer;
		}
		if ( 200 !== http_response_code() || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || self::personal_headers() ) {
			self::no_cache();
			return $buffer;
		}
		header( 'X-LiteSpeed-Cache-Control: public,max-age=' . ( (int) $this->opt( 'ttl' ) * MINUTE_IN_SECONDS ) );
		header( 'X-LiteSpeed-Tag: ' . self::TAG );
		header( 'X-LiteSpeed-Vary: ' . implode( ',', array_map( static fn( $cookie ) => 'cookie=' . $cookie, self::vary_cookies() ) ) );
		return $buffer;
	}

	/**
	 * @param string $cookie Cookie value.
	 * @param int    $expire Expiry of the auth cookie.
	 */
	public function logged_in( string $cookie, int $expire, int $expiration ): void {
		$this->vary_on( $expire ? $expire : $expiration );
	}

	public function commented(): void {
		/** This filter is documented in wp-includes/comment.php */
		$this->vary_on( time() + (int) apply_filters( 'comment_cookie_lifetime', 30000000 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
	}

	/** Follow WordPress's actual password-cookie lifetime, including session-only cookies. */
	public function post_password_expires( int $expire ): int {
		$this->vary_on( $expire );
		return $expire;
	}

	/** WooCommerce sets or clears its cart cookies; follow it unless the visitor is logged in anyway. */
	public function cart_cookies( bool $set ): void {
		if ( $set ) {
			$this->vary_on( time() + 2 * DAY_IN_SECONDS );
		} elseif ( ! is_user_logged_in() ) {
			$this->vary_off();
		}
	}

	public function vary_on( int $expire ): void {
		if ( ! headers_sent() ) {
			if ( is_user_logged_in() ) {
				$auth   = wp_parse_auth_cookie( '', 'logged_in' );
				$expire = max( $expire, (int) ( $auth['expiration'] ?? 0 ) );
			}
			setcookie( self::VARY, '1', self::cookie_options( $expire ) );
			$_COOKIE[ self::VARY ] = '1';
		}
	}

	public function vary_off(): void {
		if ( ! headers_sent() && isset( $_COOKIE[ self::VARY ] ) ) {
			setcookie( self::VARY, '', self::cookie_options( time() - YEAR_IN_SECONDS ) );
			unset( $_COOKIE[ self::VARY ] );
		}
	}

	/** Clear every page SafeHouse marked: in this response when possible, otherwise with the next request. */
	public function purge(): void {
		if ( self::web_request() && ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: tag=' . self::TAG );
			self::$purge_sent = true;
			return;
		}
		self::queue_purge();
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
		if ( $was_enabled !== $is_enabled ) {
			$this->purge(); // Pages cached with our headers would otherwise stay until they expire.
		}
	}

	public function tasks(): array {
		return [ 'purge' => __( 'Clear the cache', 'shouse' ) ];
	}

	public function handle_task( string $task ): string {
		$this->purge();
		Log::add( 'cache_purged', 'LiteSpeed cache cleared from the settings page' );
		return __( 'LiteSpeed was asked to clear the cache.', 'shouse' );
	}

	public function render_panel(): void {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		echo '<div class="shouse-panel"><p>';
		echo esc_html(
			str_contains( strtolower( $software ), 'litespeed' )
				? __( 'This server is LiteSpeed. Tools → Site Health checks whether it actually serves pages from its cache.', 'shouse' )
				: __( 'This server does not look like LiteSpeed, so the cache headers have no effect here.', 'shouse' )
		);
		echo '</p></div>';
		if ( ! self::server_varies_ready() ) {
			echo '<div class="shouse-panel"><p>' . esc_html__( 'Public caching is paused until the server checks personal cookies before serving cached pages. Ask your host to apply these rewrite rules to this site and restart LiteSpeed, then run the SafeHouse LiteSpeed test in Site Health. No files are changed automatically.', 'shouse' ) . '</p><pre>' . esc_html( self::server_rules() ) . '</pre><p>' . esc_html__( 'Verify an anonymous cache hit, then repeat with a WordPress login or WooCommerce cart cookie and without _lscache_vary: the response must not be a cache hit.', 'shouse' ) . '</p></div>';
		}
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['shouse_litespeed'] = [
			'label' => __( 'SafeHouse LiteSpeed page cache', 'shouse' ),
			'test'  => [ $this, 'site_health_result' ],
		];
		return $tests;
	}

	/**
	 * Load the home page twice as a visitor and read LiteSpeed's cache header on the second answer.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_result(): array {
		$headers = [];
		for ( $i = 0; $i < 2; $i++ ) {
			$response = wp_remote_get(
				home_url( '/' ),
				[
					'timeout'     => 10,
					'redirection' => 0,
					'cookies'     => [],
				]
			);
			$headers  = is_wp_error( $response ) ? [] : wp_remote_retrieve_headers( $response );
		}
		$server = strtolower( (string) ( $headers['server'] ?? '' ) );
		$cache  = strtolower( (string) ( $headers['x-litespeed-cache'] ?? '' ) );

		$bypass = [] !== self::vary_cookies();
		if ( 'hit' === $cache ) {
			foreach ( self::vary_cookies() as $cookie ) {
				$response = wp_remote_get(
					home_url( '/' ),
					[
						'timeout'     => 5,
						'redirection' => 0,
						'cookies'     => [ $cookie => 'shouse-cookie-probe' ],
					]
				);
				if ( is_wp_error( $response ) || str_starts_with( strtolower( (string) wp_remote_retrieve_header( $response, 'x-litespeed-cache' ) ), 'hit' ) ) {
					$bypass = false;
					break;
				}
			}
		}
		if ( 'hit' === $cache && ! $bypass ) {
			$status = 'critical';
			$label  = __( 'LiteSpeed served a cached page despite a personal cookie', 'shouse' );
			$text   = __( 'Disable public caching and have your host correct the cookie vary rules, restart LiteSpeed and purge existing pages. Test each login, cart and password cookie without _lscache_vary before re-enabling caching.', 'shouse' );
		} elseif ( 'hit' === $cache ) {
			$status = 'good';
			$label  = __( 'LiteSpeed serves pages from its cache', 'shouse' );
			$text   = __( 'The home page came from cache for an anonymous visitor; each personal cookie separately bypassed that cached page without relying on the supplementary vary cookie.', 'shouse' );
		} elseif ( ! self::server_varies_ready() ) {
			$status = 'recommended';
			$label  = __( 'LiteSpeed public caching is paused until cookie rules are configured', 'shouse' );
			$text   = __( 'Apply the cookie vary rewrite rules shown in the SafeHouse LiteSpeed settings, restart LiteSpeed and run this test again. PHP response headers alone do not protect sessions created before activation on OpenLiteSpeed.', 'shouse' );
		} elseif ( str_contains( $server, 'litespeed' ) ) {
			$status = 'recommended';
			$label  = __( 'LiteSpeed does not serve pages from its cache yet', 'shouse' );
			$text   = __( 'The server is LiteSpeed, but the home page was not served from its cache. On LiteSpeed Enterprise add this to .htaccess, or ask your host to turn the cache on: <IfModule LiteSpeed> CacheLookup public on </IfModule>. A page that sets a cookie for every visitor is never cached either.', 'shouse' );
		} else {
			$status = 'recommended';
			$label  = __( 'The server is not LiteSpeed', 'shouse' );
			$text   = __( 'The LiteSpeed page cache module has no effect on this server. Switch it off, or use the cache your host provides.', 'shouse' );
		}
		if ( Compat::woocommerce_active() && 'geolocation' === get_option( 'woocommerce_default_customer_address' ) ) {
			$status = 'critical' === $status ? $status : 'recommended';
			$text  .= ' ' . __( 'WooCommerce geolocates customers without page caching support, so cached pages may show one country\'s prices and taxes to everyone. In WooCommerce → Settings → General choose "Geolocate (with page caching support)".', 'shouse' );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Performance', 'shouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'test'        => 'shouse_litespeed',
		];
	}

	/**
	 * Clear the LiteSpeed page cache.
	 */
	public function cli_purge(): void {
		$this->purge();
		Log::add( 'cache_purged', 'LiteSpeed cache cleared from WP-CLI' );
		WP_CLI::success( 'Purge queued; a web request will be triggered when this command finishes.' );
	}

	/** Anonymous front-end GET/HEAD without personal cookies, not excluded. */
	private function cacheable_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || is_user_logged_in() || is_admin() || wp_doing_ajax() || is_preview() || is_customize_preview() || is_search() ) {
			return false;
		}
		if ( $this->personal_cookies() ) {
			return false;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/', PHP_URL_PATH );
		foreach ( (array) preg_split( '/\R/', (string) $this->opt( 'never_cache' ) ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line && str_starts_with( $path, $line ) ) {
				return false;
			}
		}
		return true;
	}

	private function personal_cookies( bool $include_vary = true ): bool {
		$cookies = self::vary_cookies();
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( ! $include_vary && self::VARY === $name ) {
				continue;
			}
			if ( in_array( (string) $name, $cookies, true ) ) {
				return true;
			}
			foreach ( self::PRIVATE_COOKIES as $prefix ) {
				if ( str_starts_with( (string) $name, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Exact names let LiteSpeed decide before PHP, including sessions created before activation.
	 *
	 * @return string[]
	 */
	private static function vary_cookies(): array {
		$hash    = defined( 'COOKIEHASH' ) ? (string) COOKIEHASH : '';
		$cookies = [ self::VARY, 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp-postpass_' . $hash, 'comment_author_' . $hash, 'comment_author_email_' . $hash, 'comment_author_url_' . $hash ];
		foreach ( [ 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ] as $constant ) {
			if ( defined( $constant ) ) {
				$cookies[] = (string) constant( $constant );
			}
		}
		/** This filter is documented by WooCommerce's session handler. */
		$cookies[] = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . $hash ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
		foreach ( $cookies as $cookie ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $cookie ) ) {
				return []; // Never silently omit a custom personal cookie from the required server varies.
			}
		}
		return array_values( array_unique( $cookies ) );
	}

	/** Only server-produced variables count; a client-supplied HTTP header cannot opt caching in. */
	private static function server_varies_ready(): bool {
		$raw = $_SERVER['LSCACHE_VARY_COOKIE'] ?? '';
		if ( ! is_string( $raw ) ) {
			return false;
		}
		$names   = array_map( 'trim', explode( ',', $raw ) );
		$cookies = self::vary_cookies();
		return [] !== $cookies && [] === array_diff( $cookies, $names );
	}

	/** Host-applied rule: varies are evaluated before a cache hit can skip WordPress. */
	public static function server_rules(): string {
		$cookies = self::vary_cookies();
		if ( ! $cookies ) {
			return '# SafeHouse: correct the custom cookie name first; only letters, digits, underscores and hyphens are supported.';
		}
		return "<IfModule LiteSpeed>\nRewriteEngine On\nRewriteRule .* - [E=\"cache-vary:" . implode( ',', $cookies ) . "\"]\n</IfModule>";
	}

	/** A cookie was set, or something already said the page is private or must not be cached. */
	private static function personal_headers(): bool {
		foreach ( headers_list() as $header ) {
			$header = strtolower( $header );
			if ( str_starts_with( $header, 'set-cookie:' ) ) {
				return true;
			}
			if ( str_starts_with( $header, 'cache-control:' ) && preg_match( '/no-cache|no-store|private/', $header ) ) {
				return true;
			}
		}
		return false;
	}

	private static function no_cache(): void {
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/** A request that goes through the web server, so a response header reaches LiteSpeed. */
	private static function web_request(): bool {
		return ! ( defined( 'WP_CLI' ) && WP_CLI ) && PHP_SAPI !== 'cli';
	}

	/**
	 * @return array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
	 */
	private static function cookie_options( int $expire ): array {
		return [
			'expires'  => $expire,
			'path'     => defined( 'COOKIEPATH' ) && constant( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '/',
			'domain'   => (string) COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		];
	}
}
