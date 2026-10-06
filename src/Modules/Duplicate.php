<?php
/**
 * "Duplicate" row action for posts, pages and custom post types.
 *
 * The copy is a draft owned by the current user. It needs edit and read rights on the original
 * and create rights for the type; content goes through the normal save filters, so users without
 * unfiltered_html cannot smuggle scripts in by duplicating an administrator's post.
 * WooCommerce products are skipped because WooCommerce has its own duplicate action.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_Post;
use SafeHouse\Core\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Duplicate extends AbstractModule {

	private const SKIP_TYPES = [ 'product', 'product_variation', 'shop_order', 'shop_coupon', 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ];
	private const SKIP_META  = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_encloseme', '_pingme' ];

	public function id(): string {
		return 'duplicate';
	}

	public function defaults(): array {
		return [];
	}

	public function label(): string {
		return __( 'Duplicate posts and pages', 'shouse' );
	}

	public function description(): string {
		return __( 'Adds a "Duplicate" link to posts, pages and custom post types. The copy is a draft with the same content, taxonomies and custom fields. WooCommerce products use WooCommerce\'s own duplicate action.', 'shouse' );
	}

	public function fields(): array {
		return [];
	}

	public function boot(): void {
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_action( 'admin_post_shouse_duplicate', [ $this, 'handle' ] );
	}

	/**
	 * @param array<string, string> $actions Row actions.
	 * @param WP_Post               $post    Post.
	 * @return array<string, string>
	 */
	public function row_action( array $actions, WP_Post $post ): array {
		if ( ! $this->allowed( $post ) ) {
			return $actions;
		}
		$url                         = wp_nonce_url( admin_url( 'admin-post.php?action=shouse_duplicate&post=' . $post->ID ), 'shouse_duplicate_' . $post->ID );
		$actions['shouse_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'shouse' ) . '</a>';
		return $actions;
	}

	public function handle(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'shouse_duplicate_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->allowed( $post ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this item.', 'shouse' ), 403 );
		}

		$copy_id = wp_insert_post(
			wp_slash(
				[
					'post_type'      => $post->post_type,
					'post_status'    => 'draft',
					'post_author'    => get_current_user_id(),
					/* translators: %s: original title. */
					'post_title'     => sprintf( __( '%s (copy)', 'shouse' ), $post->post_title ),
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'post_parent'    => $post->post_parent,
					'menu_order'     => $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
					'post_password'  => $post->post_password,
				]
			),
			true
		);
		if ( is_wp_error( $copy_id ) ) {
			wp_die( esc_html( $copy_id->get_error_message() ) );
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'ids' ] );
			if ( is_array( $terms ) && $terms ) {
				wp_set_object_terms( $copy_id, array_map( 'intval', $terms ), $taxonomy );
			}
		}

		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// Values come from our own database, stored by WordPress; unserialize them the way core does.
				add_post_meta( $copy_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		wp_safe_redirect( (string) get_edit_post_link( $copy_id, 'raw' ) );
		exit;
	}

	private function allowed( WP_Post $post ): bool {
		$type = get_post_type_object( $post->post_type );
		return null !== $type
			&& $type->show_ui
			&& ! in_array( $post->post_type, self::SKIP_TYPES, true )
			&& current_user_can( 'edit_post', $post->ID )
			&& current_user_can( 'read_post', $post->ID )
			&& current_user_can( $type->cap->create_posts );
	}
}
