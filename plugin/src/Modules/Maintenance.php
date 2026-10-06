<?php
/**
 * Maintenance / coming-soon page: 503 with Retry-After for visitors, the normal site for staff.
 *
 * Only front-end page views are stopped (template_redirect). wp-admin, wp-login, admin-ajax,
 * wp-cron, the REST API and WooCommerce's ?wc-api= callbacks never reach that hook, so payment
 * webhooks keep working. There is deliberately no exception based on request headers or the query
 * string: the client controls those, so an Accept header or an empty ?wc-api= must not unlock the
 * site. Page caches are purged when the mode is switched, otherwise they keep serving the old
 * pages (or the 503) after the switch.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_Admin_Bar;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Log;
use SafeHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Maintenance extends AbstractModule {

	public function id(): string {
		return 'maintenance';
	}

	public function defaults(): array {
		return [
			'headline'    => '',
			'message'     => '',
			'retry_after' => 60,
			'bypass'      => 'edit_posts',
		];
	}

	public function label(): string {
		return __( 'Maintenance mode', 'shouse' );
	}

	public function description(): string {
		return __( 'Visitors see a short "back soon" page with HTTP 503, which search engines understand as temporary. Logged-in staff see the normal site. Payment callbacks, the REST API and cron keep working.', 'shouse' );
	}

	public function fields(): array {
		return [
			'headline'    => [
				'type'       => 'text',
				'label'      => __( 'Headline', 'shouse' ),
				'help'       => __( 'Empty: "We will be back soon".', 'shouse' ),
				'max_length' => 200,
			],
			'message'     => [
				'type'       => 'html',
				'label'      => __( 'Message', 'shouse' ),
				'help'       => __( 'Basic HTML allowed (links, bold, line breaks).', 'shouse' ),
				'max_length' => 2000,
			],
			'retry_after' => [
				'type'  => 'number',
				'label' => __( 'Expected duration (minutes)', 'shouse' ),
				'help'  => __( 'Sent to search engines as Retry-After.', 'shouse' ),
				'min'   => 5,
				'max'   => 10080,
			],
			'bypass'      => [
				'type'    => 'select',
				'label'   => __( 'Who sees the normal site', 'shouse' ),
				'options' => [
					'manage_options' => __( 'Administrators', 'shouse' ),
					'edit_posts'     => __( 'Anyone who can edit content', 'shouse' ),
					'read'           => __( 'Every logged-in user', 'shouse' ),
				],
			],
		];
	}

	public function boot(): void {
		add_action( 'template_redirect', [ $this, 'maybe_block' ], 0 );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_notice' ], 100 );
	}

	public function maybe_block(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_robots() || is_favicon() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( current_user_can( (string) $this->opt( 'bypass' ) ) ) {
			return;
		}

		$minutes = (int) $this->opt( 'retry_after' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared page-cache convention (LiteSpeed, WP Rocket, W3TC, WP Super Cache).
		}
		nocache_headers();
		status_header( 503 );
		header( 'Retry-After: ' . max( 300, $minutes * MINUTE_IN_SECONDS ) );
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		$this->render_page();
		exit;
	}

	private function render_page(): void {
		$headline = (string) $this->opt( 'headline' );
		$headline = '' !== $headline ? $headline : __( 'We will be back soon', 'shouse' );
		$message  = (string) $this->opt( 'message' );
		$site     = get_bloginfo( 'name' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $headline . ' – ' . $site ); ?></title>
<style>
	:root { color-scheme: light dark; }
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 17px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; background: Canvas; color: CanvasText; }
	main { max-width: 34rem; padding: 2rem 1.5rem; }
	h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 1rem; }
	.site { opacity: .65; font-size: .9rem; margin: 0 0 2rem; letter-spacing: .02em; }
	a { color: inherit; }
</style>
</head>
<body>
<main>
	<p class="site"><?php echo esc_html( $site ); ?></p>
	<h1><?php echo esc_html( $headline ); ?></h1>
		<?php echo wp_kses_post( wpautop( $message ) ); ?>
</main>
</body>
</html>
		<?php
	}

	public function admin_bar_notice( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			[
				'id'    => 'shouse-maintenance',
				'title' => esc_html__( 'Maintenance mode is on', 'shouse' ),
				'href'  => Plugin::settings_url( 'maintenance' ),
				'meta'  => [ 'class' => 'shouse-maintenance-on' ],
			]
		);
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
		if ( $was_enabled === $is_enabled ) {
			return;
		}
		Log::add( 'maintenance', $is_enabled ? 'Maintenance mode switched on' : 'Maintenance mode switched off', [], 'warning' );
		self::purge_page_caches();
	}

	/** Purge the page caches we know about. `litespeed_purge_all` also reaches the SafeHouse LiteSpeed and Cloudflare modules (Core\ContentChanges); without the Cloudflare module, Cloudflare HTML caching needs a manual purge. */
	private static function purge_page_caches(): void {
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache API.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( class_exists( 'autoptimizeCache' ) ) {
			\autoptimizeCache::clearall();
		}
	}
}
