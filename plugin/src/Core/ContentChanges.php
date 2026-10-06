<?php
/**
 * One place that knows when published content changed, for every cache SafeHouse clears (LiteSpeed,
 * Cloudflare; maintenance mode triggers it through `litespeed_purge_all`). It fires
 * `shouse_content_changed` with the addresses that changed, or with an empty list when the change can
 * show on any page: menus, widgets, theme, plugins, SafeHouse settings, unpublishing.
 *
 * Comments count only once approved, so spam does not empty caches.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_Post;

defined( 'ABSPATH' ) || exit;

final class ContentChanges {

	public const ACTION = 'shouse_content_changed';

	private const SITE_WIDE = [ 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'update_option_shouse_settings', 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', '_core_updated_successfully', 'litespeed_purge_all' ];

	private const STOCK = [ 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ];

	public static function register(): void {
		foreach ( self::SITE_WIDE as $hook ) {
			add_action( $hook, [ self::class, 'everything' ] );
		}
		add_action( 'transition_post_status', [ self::class, 'post_status' ], 10, 3 );
		add_action( 'before_delete_post', [ self::class, 'post_deleted' ], 10, 2 );
		add_action( 'wp_insert_comment', [ self::class, 'comment_inserted' ], 10, 2 ); // Also under wp_new_comment(); covers imports and WP-CLI.
		add_action( 'wp_set_comment_status', [ self::class, 'comment_changed' ] );
		add_action( 'edit_comment', [ self::class, 'comment_changed' ] );
		foreach ( self::STOCK as $hook ) {
			add_action( $hook, [ self::class, 'stock_changed' ] );
		}
	}

	/** Something that can show on every page changed. */
	public static function everything(): void {
		do_action( 'shouse_content_changed', [] ); // Literal name: ACTION is for listeners.
	}

	/** A post's own page and the listings it appears on changed. */
	public static function post( int|WP_Post $post ): void {
		$urls = self::post_urls( $post );
		if ( $urls ) {
			do_action( 'shouse_content_changed', $urls );
		}
	}

	public static function post_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ( 'publish' !== $new_status && 'publish' !== $old_status ) || wp_is_post_revision( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			self::post( $post );
		} else {
			self::everything(); // Unpublished or trashed: its old address is gone and may be linked anywhere.
		}
	}

	public static function post_deleted( int $post_id, WP_Post $post ): void {
		if ( 'publish' === $post->post_status ) {
			self::everything();
		}
	}

	public static function comment_inserted( int $comment_id, mixed $comment ): void {
		if ( is_object( $comment ) && '1' === (string) ( $comment->comment_approved ?? '' ) ) {
			self::comment_changed( $comment_id );
		}
	}

	public static function comment_changed( mixed $comment_id ): void {
		$comment = get_comment( (int) $comment_id );
		if ( $comment && (int) $comment->comment_post_ID ) {
			self::post( (int) $comment->comment_post_ID );
		}
	}

	/** WooCommerce stock changes pass the product, or its ID first. */
	public static function stock_changed( mixed $product ): void {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			self::post( $parent ? $parent : (int) $product->get_id() );
		}
	}

	/**
	 * The post's page and the listings it appears on: home, its post type archive, the blog page and its terms.
	 *
	 * @return string[]
	 */
	public static function post_urls( int|WP_Post $post ): array {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return [];
		}
		$urls    = [ home_url( '/' ), (string) get_permalink( $post ) ];
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}
		if ( 'post' === $post->post_type && (int) get_option( 'page_for_posts' ) ) {
			$urls[] = (string) get_permalink( (int) get_option( 'page_for_posts' ) );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}
			$terms = wp_get_post_terms( $post->ID, $taxonomy->name );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}
}
