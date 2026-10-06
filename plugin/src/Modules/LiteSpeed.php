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
 * The server looks a page up before PHP runs, so it cannot know a visitor is logged in or has a cart.
 * Tested on OpenLiteSpeed (dev/ols): it keeps a separate cache per value of the `_lscache_vary` cookie
 * and ignores WordPress and WooCommerce cookies. So we set `_lscache_vary` on login, for commenters
 * and with the WooCommerce cart cookies, and those visitors always reach PHP, which never lets their
 * pages be cached.
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

defined( 'ABSPATH' ) || exit;

final class LiteSpeed extends AbstractModule {

	private const QUEUE = 'shouse_litespeed_purge';
	private const VARY  = '_lscache_vary';
	private const TAG   = 'shouse';

	/** Request cookies (name prefixes) that mean the page is personal and must not come from or go to the cache. */
	private const PRIVATE_COOKIES = [ self::VARY, 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ];

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
		add_action( 'init', [ $this, 'on_init' ], 1 );
		add_action( 'template_redirect', [ $this, 'start' ], PHP_INT_MAX );

		add_action( 'set_logged_in_cookie', [ $this, 'logged_in' ], 10, 3 );
		add_action( 'clear_auth_cookie', [ $this, 'vary_off' ] );
		add_action( 'set_comment_cookies', [ $this, 'commented' ] );
		add_action( 'woocommerce_set_cart_cookies', [ $this, 'cart_cookies' ] );

		// Any change clears all of this site's LiteSpeed pages: tag purges are cheap, unlike Cloudflare's.
		add_action( ContentChanges::ACTION, [ $this, 'purge' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse cache purge', [ $this, 'cli_purge' ] );
		}
	}

	/** Send a queued purge, and give logged-in users from before the module was on their vary cookie. */
	public function on_init(): void {
		if ( self::web_request() && get_option( self::QUEUE ) && ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: tag=' . self::TAG );
			delete_option( self::QUEUE );
		}
		if ( is_user_logged_in() && empty( $_COOKIE[ self::VARY ] ) ) {
			$this->vary_on( time() + 2 * DAY_IN_SECONDS );
		}
	}

	/** Buffer a page that may be cacheable; the final decision is made once the page is complete. */
	public function start(): void {
		if ( ! $this->cacheable_request() ) {
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

	/** WooCommerce sets or clears its cart cookies; follow it unless the visitor is logged in anyway. */
	public function cart_cookies( bool $set ): void {
		if ( $set ) {
			$this->vary_on( time() + 2 * DAY_IN_SECONDS );
		} elseif ( ! is_user_logged_in() ) {
			$this->vary_off();
		}
	}

	public function vary_on( int $expire ): void {
		if ( ! headers_sent() && ! isset( $_COOKIE[ self::VARY ] ) ) {
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
			return;
		}
		if ( ! get_option( self::QUEUE ) ) {
			update_option( self::QUEUE, time(), false );
		}
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
		if ( $was_enabled && ! $is_enabled ) {
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

		if ( 'hit' === $cache ) {
			$status = 'good';
			$label  = __( 'LiteSpeed serves pages from its cache', 'shouse' );
			$text   = __( 'The home page came from the LiteSpeed cache for a visitor who is not logged in.', 'shouse' );
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
			$status = 'recommended';
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
		// WP-CLI has no response to carry the purge: make the site handle one request now.
		wp_remote_get(
			admin_url( 'admin-ajax.php' ),
			[
				'blocking' => false,
				'timeout'  => 1,
			]
		);
		WP_CLI::success( 'Purge queued; LiteSpeed applies it with the next request to the site.' );
	}

	/** Anonymous front-end GET/HEAD without personal cookies, not excluded. */
	private function cacheable_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || is_user_logged_in() || is_admin() || wp_doing_ajax() || is_preview() || is_customize_preview() || is_search() ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( self::PRIVATE_COOKIES as $prefix ) {
				if ( str_starts_with( (string) $name, $prefix ) ) {
					return false;
				}
			}
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
