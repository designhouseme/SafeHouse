<?php
/**
 * Everyday switches that usually come as one plugin each.
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use WP_Query;
use WPHouse\Core\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Tweaks extends AbstractModule {

	public function id(): string {
		return 'tweaks';
	}

	public function defaults(): array {
		return [
			'disable_comments' => false,
			'disable_search'   => false,
			'disable_emojis'   => false,
			'disable_embeds'   => false,
			'clean_head'       => false,
			'heartbeat'        => false,
			'self_pings'       => false,
			'revisions'        => '',
		];
	}

	public function label(): string {
		return __( 'Tweaks', 'wphouse' );
	}

	public function description(): string {
		return __( 'Small switches that are usually a plugin each: comments, search, emojis, embeds, head clean-up, Heartbeat, self-pingbacks and revision limits.', 'wphouse' );
	}

	public function fields(): array {
		return [
			'disable_comments' => [
				'type'  => 'toggle',
				'label' => __( 'Disable comments', 'wphouse' ),
				'help'  => __( 'Closes comments and pingbacks everywhere and hides existing ones. WooCommerce product reviews keep working when reviews are enabled in WooCommerce.', 'wphouse' ),
			],
			'disable_search'   => [
				'type'  => 'toggle',
				'label' => __( 'Disable front-end search', 'wphouse' ),
				'help'  => __( 'Search URLs redirect to the home page (stops search-spam pages). Also disables WooCommerce product search.', 'wphouse' ),
			],
			'disable_emojis'   => [
				'type'  => 'toggle',
				'label' => __( 'Disable emoji scripts', 'wphouse' ),
			],
			'disable_embeds'   => [
				'type'  => 'toggle',
				'label' => __( 'Disable embedding of this site', 'wphouse' ),
				'help'  => __( 'Removes oEmbed discovery and wp-embed.js. Embedding YouTube and others in your posts still works.', 'wphouse' ),
			],
			'clean_head'       => [
				'type'  => 'toggle',
				'label' => __( 'Clean up <head>', 'wphouse' ),
				'help'  => __( 'Removes RSD, Windows Live Writer, shortlink and adjacent-post links.', 'wphouse' ),
			],
			'heartbeat'        => [
				'type'  => 'toggle',
				'label' => __( 'Slow down Heartbeat', 'wphouse' ),
				'help'  => __( 'Every 60 seconds instead of 15–60, and not loaded on the front end for visitors.', 'wphouse' ),
			],
			'self_pings'       => [
				'type'  => 'toggle',
				'label' => __( 'Disable self-pingbacks', 'wphouse' ),
			],
			'revisions'        => [
				'type'        => 'number',
				'label'       => __( 'Revisions to keep', 'wphouse' ),
				'help'        => __( 'Empty keeps the WordPress default (unlimited).', 'wphouse' ),
				'min'         => 0,
				'max'         => 100,
				'allow_empty' => true,
			],
		];
	}

	public function boot(): void {
		if ( $this->opt( 'disable_comments' ) ) {
			$this->disable_comments();
		}
		if ( $this->opt( 'disable_search' ) ) {
			add_action( 'parse_query', [ $this, 'block_search' ] );
			add_filter( 'get_search_form', '__return_empty_string', 100 );
		}
		if ( $this->opt( 'disable_emojis' ) ) {
			$this->disable_emojis();
		}
		if ( $this->opt( 'disable_embeds' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action( 'wp_footer', static fn() => wp_dequeue_script( 'wp-embed' ), 1 );
		}
		if ( $this->opt( 'clean_head' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( $this->opt( 'heartbeat' ) ) {
			add_filter( 'heartbeat_settings', [ $this, 'heartbeat_interval' ] );
			add_action( 'wp_enqueue_scripts', [ $this, 'drop_frontend_heartbeat' ], 100 );
		}
		if ( $this->opt( 'self_pings' ) ) {
			add_action( 'pre_ping', [ $this, 'drop_self_pings' ] );
		}
		if ( '' !== $this->opt( 'revisions' ) && null !== $this->opt( 'revisions' ) ) {
			add_filter( 'wp_revisions_to_keep', fn() => (int) $this->opt( 'revisions' ) );
		}
	}

	/**
	 * Product reviews stay when WooCommerce has reviews enabled.
	 *
	 * @return string[]
	 */
	private function comment_exempt_types(): array {
		return 'yes' === get_option( 'woocommerce_enable_reviews' ) && post_type_exists( 'product' ) ? [ 'product' ] : [];
	}

	private function disable_comments(): void {
		$open = function ( mixed $open, mixed $post_id ): bool {
			return in_array( get_post_type( (int) $post_id ), $this->comment_exempt_types(), true ) && (bool) $open;
		};
		// comments_open() is what wp-comments-post.php, the REST API and XML-RPC all check.
		add_filter( 'comments_open', $open, 20, 2 );
		add_filter( 'pings_open', $open, 20, 2 );
		add_filter(
			'comments_array',
			fn( mixed $comments, mixed $post_id ) => in_array( get_post_type( (int) $post_id ), $this->comment_exempt_types(), true ) ? $comments : [],
			20,
			2
		);
		add_filter( 'feed_links_show_comments_feed', '__return_false' );

		// WooCommerce registers "product" on init priority 5.
		add_action(
			'init',
			function (): void {
				$exempt = $this->comment_exempt_types();
				foreach ( get_post_types_by_support( 'comments' ) as $type ) {
					if ( ! in_array( $type, $exempt, true ) ) {
						remove_post_type_support( $type, 'comments' );
						remove_post_type_support( $type, 'trackbacks' );
					}
				}
			},
			100
		);

		add_action(
			'admin_menu',
			function (): void {
				if ( ! $this->comment_exempt_types() ) {
					remove_menu_page( 'edit-comments.php' );
				}
			}
		);
		add_action(
			'admin_bar_menu',
			static fn( \WP_Admin_Bar $bar ) => $bar->remove_node( 'comments' ),
			100
		);
		add_action(
			'wp_dashboard_setup',
			static fn() => remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' )
		);
	}

	public function block_search( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	private function disable_emojis(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter(
			'tiny_mce_plugins',
			static fn( $plugins ) => is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : $plugins
		);
	}

	/**
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public function heartbeat_interval( array $settings ): array {
		$settings['interval'] = 60;
		return $settings;
	}

	public function drop_frontend_heartbeat(): void {
		if ( ! is_user_logged_in() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * @param string[] $links Links about to be pinged (by reference).
	 */
	public function drop_self_pings( array &$links ): void {
		$home = home_url();
		foreach ( $links as $index => $link ) {
			if ( str_starts_with( $link, $home ) ) {
				unset( $links[ $index ] );
			}
		}
	}
}
