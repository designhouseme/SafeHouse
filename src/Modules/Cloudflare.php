<?php
/**
 * Clears the Cloudflare cache after content changes, for sites that cache HTML at Cloudflare (APO,
 * Cache Rules with "Cache Everything"). Static files are cached by Cloudflare anyway; this keeps pages fresh.
 *
 * Listens to Core\ContentChanges: a changed post clears its own address and the listings it appears on
 * (up to 30 addresses per call, Cloudflare's limit), anything site-wide clears everything. Calls go out
 * at the end of the request, at most one every 30 seconds; changes in between are sent by cron.
 *
 * The only outbound host is api.cloudflare.com. The token (a Cloudflare API token with only
 * Zone → Cache Purge on this zone) and the zone ID live in wp-config.php:
 *   define( 'WPHOUSE_CLOUDFLARE_TOKEN', '...' );
 *   define( 'WPHOUSE_CLOUDFLARE_ZONE', '0123456789abcdef0123456789abcdef' );
 * WPHOUSE_CLOUDFLARE_API (another API base) is honoured only on local/development sites, for tests.
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use WP_CLI;
use WPHouse\Core\AbstractModule;
use WPHouse\Core\ContentChanges;
use WPHouse\Core\Log;
use WPHouse\Core\Updater;

defined( 'ABSPATH' ) || exit;

final class Cloudflare extends AbstractModule {

	private const API      = 'https://api.cloudflare.com/client/v4';
	private const GAP      = 30;
	private const MAX_URLS = 30;
	private const CRON     = 'wphouse_cloudflare_purge';
	private const PENDING  = 'wphouse_cloudflare_pending';
	private const LAST     = 'wphouse_cloudflare_last';
	private const RESULT   = 'wphouse_cloudflare_result';

	/**
	 * Addresses changed in this request; "*" means everything. Null when nothing changed.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $pending = null;

	public function id(): string {
		return 'cloudflare';
	}

	public function defaults(): array {
		return [];
	}

	public function label(): string {
		return __( 'Cloudflare cache', 'wphouse' );
	}

	public function description(): string {
		return __( 'Clears the Cloudflare cache after changes, for sites where Cloudflare caches whole pages (APO or a Cache Everything rule). A changed post clears its own page and the listings it appears on; menus, widgets, themes and plugins clear everything. The API token and zone ID live in wp-config.php.', 'wphouse' );
	}

	public function fields(): array {
		return [];
	}

	public function available(): bool {
		return '' !== self::token() && '' !== self::zone();
	}

	public function unavailable_reason(): string {
		return __( 'Add WPHOUSE_CLOUDFLARE_TOKEN (an API token allowed only to purge this zone\'s cache) and WPHOUSE_CLOUDFLARE_ZONE (the zone ID from the Cloudflare dashboard) to wp-config.php.', 'wphouse' );
	}

	public function boot(): void {
		add_action( ContentChanges::ACTION, [ $this, 'collect' ] ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'shutdown', [ $this, 'send' ], PHP_INT_MAX );
		add_action( self::CRON, [ $this, 'send_pending' ] );
		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( Updater::is_local() ) {
			add_filter( 'http_request_host_is_external', [ $this, 'allow_local_api' ], 10, 2 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wphouse cloudflare purge', [ $this, 'cli_purge' ] );
		}
	}

	/**
	 * @param mixed $urls Changed addresses; empty for everything.
	 */
	public function collect( mixed $urls = [] ): void {
		$this->pending ??= [];
		$urls            = is_array( $urls ) ? $urls : [];
		if ( ! $urls ) {
			$this->pending['*'] = true;
		}
		foreach ( $urls as $url ) {
			$this->pending[ (string) $url ] = true;
		}
	}

	/** End of the request: purge now, or leave it to cron when the last call was under 30 seconds ago. */
	public function send(): void {
		if ( null === $this->pending ) {
			return;
		}
		$batch         = $this->pending;
		$this->pending = null;
		if ( get_transient( self::LAST ) ) {
			$queued = get_option( self::PENDING, [] );
			update_option( self::PENDING, ( is_array( $queued ) ? $queued : [] ) + $batch, false );
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_single_event( time() + self::GAP, self::CRON );
			}
			return;
		}
		$this->purge( $batch );
	}

	/** Cron: send what piled up while calls were spaced out. */
	public function send_pending(): void {
		$queued = get_option( self::PENDING, [] );
		delete_option( self::PENDING );
		if ( is_array( $queued ) && $queued ) {
			$this->purge( $queued );
		}
	}

	/**
	 * Call the Cloudflare API. Never fails the request: the outcome goes to the log and the panel.
	 *
	 * @param array<string, true> $batch Addresses, or "*" for everything.
	 * @return string Error message, or '' on success.
	 */
	public function purge( array $batch ): string {
		set_transient( self::LAST, time(), self::GAP );
		$everything = isset( $batch['*'] ) || count( $batch ) > self::MAX_URLS;
		$body       = $everything ? [ 'purge_everything' => true ] : [ 'files' => array_keys( $batch ) ];
		$response   = wp_safe_remote_post(
			self::api() . '/zones/' . rawurlencode( self::zone() ) . '/purge_cache',
			[
				'timeout' => 5,
				'headers' => [
					'Authorization' => 'Bearer ' . self::token(),
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode( $body ),
			]
		);
		$what       = $everything ? 'everything' : count( $batch ) . ' addresses';
		$error      = '';
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} else {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || empty( $data['success'] ) ) {
				$first = is_array( $data ) ? ( $data['errors'][0] ?? [] ) : [];
				$error = sprintf( 'HTTP %d: %s', wp_remote_retrieve_response_code( $response ), is_array( $first ) ? (string) ( $first['message'] ?? 'unknown error' ) : 'unknown error' );
			}
		}
		update_option(
			self::RESULT,
			[
				'time'  => time(),
				'count' => $everything ? 0 : count( $batch ), // 0: everything.
				'error' => mb_substr( sanitize_text_field( $error ), 0, 300 ),
			],
			false
		);
		if ( '' === $error ) {
			Log::add( 'cloudflare_purged', 'Cloudflare cache cleared: ' . $what );
		} else {
			Log::add( 'cloudflare_purge_failed', 'Cloudflare cache could not be cleared: ' . $error, [ 'what' => $what ], 'warning' );
		}
		return $error;
	}

	public function tasks(): array {
		return [ 'purge' => __( 'Clear the whole Cloudflare cache', 'wphouse' ) ];
	}

	public function handle_task( string $task ): string {
		$error = $this->purge( [ '*' => true ] );
		/* translators: %s: error message. */
		return '' === $error ? __( 'Cloudflare cache cleared.', 'wphouse' ) : sprintf( __( 'Cloudflare refused: %s', 'wphouse' ), $error );
	}

	public function render_panel(): void {
		$result = get_option( self::RESULT );
		echo '<div class="wphouse-panel"><p>';
		if ( ! is_array( $result ) ) {
			echo esc_html__( 'Nothing cleared yet.', 'wphouse' );
		} else {
			$when  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['time'] );
			$count = (int) ( $result['count'] ?? 0 );
			/* translators: %d: number of addresses. */
			$what = $count ? sprintf( _n( '%d address', '%d addresses', $count, 'wphouse' ), $count ) : __( 'everything', 'wphouse' );
			echo esc_html(
				'' === $result['error']
					/* translators: 1: date and time, 2: "everything" or "N addresses". */
					? sprintf( __( 'Last cleared %1$s (%2$s).', 'wphouse' ), $when, $what )
					/* translators: 1: date and time, 2: error message. */
					: sprintf( __( 'The last call on %1$s failed: %2$s', 'wphouse' ), $when, $result['error'] )
			);
		}
		echo '</p></div>';
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$result = get_option( self::RESULT );
		if ( is_array( $result ) && '' !== $result['error'] ) {
			$tests['direct']['wphouse_cloudflare'] = [
				'label' => __( 'WPHouse Cloudflare cache', 'wphouse' ),
				'test'  => [ $this, 'site_health_result' ],
			];
		}
		return $tests;
	}

	/** @return array<string, mixed> */
	public function site_health_result(): array {
		$result = (array) get_option( self::RESULT );
		return [
			'label'       => __( 'Cloudflare refused to clear the cache', 'wphouse' ),
			'status'      => 'recommended',
			'badge'       => [
				'label' => __( 'Performance', 'wphouse' ),
				'color' => 'blue',
			],
			/* translators: %s: error message from Cloudflare. */
			'description' => '<p>' . esc_html( sprintf( __( 'Visitors may see old pages until the cache expires. Check the token and zone ID in wp-config.php. Cloudflare said: %s', 'wphouse' ), (string) ( $result['error'] ?? '' ) ) ) . '</p>',
			'test'        => 'wphouse_cloudflare',
		];
	}

	/**
	 * Clear the whole Cloudflare cache now.
	 */
	public function cli_purge(): void {
		$error = $this->purge( [ '*' => true ] );
		if ( '' !== $error ) {
			WP_CLI::error( 'Cloudflare refused: ' . $error );
		}
		WP_CLI::success( 'Cloudflare cache cleared.' );
	}

	/** Lets the test API on a private address through, on local/development sites only. */
	public function allow_local_api( bool $external, string $host ): bool {
		return wp_parse_url( self::api(), PHP_URL_HOST ) === $host ? true : $external;
	}

	private static function api(): string {
		if ( Updater::is_local() && defined( 'WPHOUSE_CLOUDFLARE_API' ) ) {
			return rtrim( (string) WPHOUSE_CLOUDFLARE_API, '/' );
		}
		return self::API;
	}

	private static function token(): string {
		return defined( 'WPHOUSE_CLOUDFLARE_TOKEN' ) ? trim( (string) WPHOUSE_CLOUDFLARE_TOKEN ) : '';
	}

	private static function zone(): string {
		$zone = defined( 'WPHOUSE_CLOUDFLARE_ZONE' ) ? strtolower( trim( (string) WPHOUSE_CLOUDFLARE_ZONE ) ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $zone ) ? $zone : '';
	}
}
