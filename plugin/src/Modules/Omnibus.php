<?php
/**
 * Omnibus: next to every reduced price, the lowest price from the 30 days before the reduction
 * (Directive 98/6/EC art. 6a, added by the Omnibus Directive 2019/2161).
 *
 * History lives in its own table: one row each time the `_price` of a product or variation changes,
 * whoever changes it (the editor, REST, imports, scheduled sales). Nothing is written while a page
 * renders. Prices changed in the database with plain SQL are not seen.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use WC_Product;
use WC_Product_Factory;
use WP_CLI;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Omnibus extends AbstractModule {

	public const WINDOW = 30 * DAY_IN_SECONDS;

	private const DB_VERSION = '1';
	private const DB_OPTION  = 'shouse_omnibus_db_version';
	/** When recording started (Unix time). Reset whenever the module is switched back on. */
	private const SINCE_OPTION = 'shouse_omnibus_since';
	private const KEEP         = YEAR_IN_SECONDS;
	private const MAX_ROWS     = 1000;

	/** Product types whose `_price` WooCommerce derives from their children: only the children are tracked. */
	private const DERIVED_TYPES = [ 'variable', 'grouped', 'variable-subscription' ];

	/** Plugins that already show the lowest price, by folder. */
	private const OTHER_PLUGINS = [
		'omnibus'                 => 'Omnibus — show the lowest price',
		'wc-price-history'        => 'WC Price History',
		'omnibus-by-ilabs'        => 'Omnibus by iLabs',
		'omnibus-for-woocommerce' => 'Omnibus for WooCommerce',
		'product-price-history'   => 'Product Price History for WooCommerce',
	];

	/** @var array<int, array{0: float, 1: string}|null> Lowest price and its source per product, for this request. */
	private static array $memo = [];

	public function id(): string {
		return 'omnibus';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [
			'display' => true,
			'label'   => '',
		];
	}

	public function coverage(): array {
		foreach ( Compat::active_plugins() as $basename ) {
			$folder = dirname( $basename );
			if ( isset( self::OTHER_PLUGINS[ $folder ] ) ) {
				return [ 'display' => self::OTHER_PLUGINS[ $folder ] ];
			}
		}
		return [];
	}

	public function label(): string {
		return __( 'Omnibus price history', 'shouse' );
	}

	public function description(): string {
		return __( 'Records every price change of products and variations, and next to each reduced price shows the lowest price from the 30 days before the reduction, as the EU Omnibus Directive requires. A product that is already reduced when recording starts shows its regular price there until its price next changes.', 'shouse' );
	}

	public function fields(): array {
		return [
			'display' => [
				'type'  => 'toggle',
				'label' => __( 'Show the lowest price next to reduced prices', 'shouse' ),
				'help'  => __( 'Shops that show reduced prices must show this. Prices are still recorded while it is off, so the figures are right when it comes back on.', 'shouse' ),
			],
			'label'   => [
				'type'       => 'text',
				'label'      => __( 'Wording', 'shouse' ),
				/* translators: %s is typed literally by the shop owner; keep it as it is. */
				'help'       => __( 'Leave empty for the standard wording. Write %s where the price goes; without it, the price goes at the end.', 'shouse' ),
				'max_length' => 200,
			],
		];
	}

	public function available(): bool {
		return Compat::woocommerce_active();
	}

	public function unavailable_reason(): string {
		return __( 'Needs WooCommerce.', 'shouse' );
	}

	public function boot(): void {
		self::maybe_install();
		add_action( 'update_post_meta', [ $this, 'before_update' ], 10, 3 );
		add_action( 'delete_post_meta', [ $this, 'before_delete' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'changed' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'changed' ], 10, 4 );
		add_action( 'deleted_post', [ $this, 'forget' ], 10, 2 );
		add_action( 'shouse_daily', [ $this, 'purge' ] );
		if ( $this->feature_on( 'display' ) ) {
			add_filter( 'woocommerce_get_price_html', [ $this, 'price_html' ], 20, 2 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse omnibus', OmnibusCommand::class );
		}
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
		if ( $is_enabled && ! $was_enabled ) {
			// Prices may have changed while nothing was recording: what is on record now is known from this moment only.
			update_option( self::SINCE_OPTION, time(), false );
		}
	}

	/**
	 * Before `_price` is overwritten: put the outgoing price on record if the history does not end with it.
	 *
	 * @param mixed $meta_id Meta row ID.
	 * @param mixed $post_id Post ID.
	 * @param mixed $key     Meta key.
	 */
	public function before_update( mixed $meta_id, mixed $post_id, mixed $key ): void {
		if ( '_price' === $key ) {
			$this->keep_outgoing( (int) $post_id, [ (int) $meta_id ] );
		}
	}

	/**
	 * Some importers delete `_price` and add it again; the outgoing price is only readable now.
	 *
	 * @param mixed $meta_ids Meta row IDs.
	 * @param mixed $post_id  Post ID.
	 * @param mixed $key      Meta key.
	 */
	public function before_delete( mixed $meta_ids, mixed $post_id, mixed $key ): void {
		if ( '_price' === $key && is_array( $meta_ids ) ) {
			$this->keep_outgoing( (int) $post_id, array_map( 'intval', $meta_ids ) );
		}
	}

	/**
	 * @param mixed $meta_id Meta row ID.
	 * @param mixed $post_id Post ID.
	 * @param mixed $key     Meta key.
	 * @param mixed $value   New value.
	 */
	public function changed( mixed $meta_id, mixed $post_id, mixed $key, mixed $value ): void {
		if ( '_price' !== $key || ! is_numeric( $value ) || (float) $value <= 0 || ! self::tracked( (int) $post_id ) ) {
			return;
		}
		$last = self::last( (int) $post_id );
		if ( null === $last || ! self::same( $last['price'], (float) $value ) ) {
			self::insert( (int) $post_id, (float) $value, time() );
		}
	}

	/**
	 * @param mixed $post_id Deleted post ID.
	 * @param mixed $post    Deleted post.
	 */
	public function forget( mixed $post_id, mixed $post = null ): void {
		if ( $post instanceof WP_Post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			global $wpdb;
			$wpdb->delete( self::table(), [ 'product_id' => (int) $post_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Daily: drop rows older than a year, except each product's last one before that, which still applied after it. */
	public function purge(): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE h FROM %i h JOIN ( SELECT product_id, MAX(id) AS keep_id FROM %i WHERE recorded_at < %s GROUP BY product_id ) k ON h.product_id = k.product_id AND h.id < k.keep_id',
				$table,
				$table,
				self::time( time() - self::KEEP )
			)
		);
	}

	/**
	 * Append the lowest price to the price of a reduced product, wherever WooCommerce prints one:
	 * product pages (classic and block), lists, and each variation as it is picked.
	 *
	 * @param mixed $html    Price HTML.
	 * @param mixed $product The product.
	 */
	public function price_html( mixed $html, mixed $product ): mixed {
		if ( ! is_string( $html ) || ! $product instanceof WC_Product || ( is_admin() && ! wp_doing_ajax() ) || ! $product->is_on_sale() ) {
			return $html;
		}
		$target = $product;
		if ( $product->is_type( self::DERIVED_TYPES ) ) {
			// WooCommerce crosses out a variable product's price only when every variation costs the same; then they share one line.
			if ( ! str_contains( $html, '<del' ) ) {
				return $html;
			}
			[ $target, $found ] = self::lowest_of_children( $product );
		} else {
			$found = self::lowest( $product );
		}
		if ( null === $found || $found[0] <= 0 ) {
			return $html;
		}
		return $html . $this->line( $target, $found[0] );
	}

	/**
	 * Lowest price for one product or variation, and where it came from: "history", or "regular" when nothing
	 * is on record from before the current price (the product was already reduced when recording started).
	 *
	 * @return array{0: float, 1: string}|null
	 */
	public static function lowest( WC_Product $product ): ?array {
		$id = $product->get_id();
		if ( ! array_key_exists( $id, self::$memo ) ) {
			$found             = self::lowest_before( self::history( $id ), (float) $product->get_price( 'edit' ), time() );
			$regular           = (float) $product->get_regular_price( 'edit' );
			self::$memo[ $id ] = null !== $found ? [ $found, 'history' ] : ( $regular > 0 ? [ $regular, 'regular' ] : null );
		}
		return self::$memo[ $id ];
	}

	/**
	 * Lowest price in the 30 days before the current price took effect, counting the price that was already in
	 * effect when that window opened. Null when nothing is on record from before the current price.
	 *
	 * @param list<array{time: int, price: float}> $rows    History, oldest first.
	 * @param float                                $current Price on offer now.
	 * @param int                                  $now     Current time.
	 */
	public static function lowest_before( array $rows, float $current, int $now ): ?float {
		$count = count( $rows );
		$start = $now; // When the history does not end with the current price, it took effect unseen: count up to now.
		while ( $count > 0 && self::same( $rows[ $count - 1 ]['price'], $current ) ) {
			--$count;
			$start = $rows[ $count ]['time'];
		}
		$opens  = $start - self::WINDOW;
		$lowest = null;
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$lowest = null === $lowest ? $rows[ $i ]['price'] : min( $lowest, $rows[ $i ]['price'] );
			if ( $rows[ $i ]['time'] <= $opens ) {
				break; // In effect when the window opened; older prices were not.
			}
		}
		return $lowest;
	}

	/**
	 * Price history of one product or variation, oldest first.
	 *
	 * @return list<array{time: int, price: float}>
	 */
	public static function history( int $product_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT price, recorded_at FROM %i WHERE product_id = %d ORDER BY id DESC LIMIT %d', self::table(), $product_id, self::MAX_ROWS ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = [];
		foreach ( array_reverse( (array) $rows ) as $row ) {
			$out[] = [
				'time'  => (int) strtotime( $row['recorded_at'] . ' UTC' ),
				'price' => (float) $row['price'],
			];
		}
		return $out;
	}

	/** When recording started, as a Unix time (0 before the module first ran). */
	public static function since(): int {
		return (int) get_option( self::SINCE_OPTION, 0 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shouse_price_history';
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
				product_id bigint(20) unsigned NOT NULL,
				price decimal(20,6) NOT NULL,
				recorded_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY product_id (product_id,id)
			) {$wpdb->get_charset_collate()};"
		);
		add_option( self::SINCE_OPTION, time(), '', false );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public function render_panel(): void {
		echo '<div class="shouse-panel">';
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			echo '<p>' . esc_html__( 'Nothing recorded yet. Recording starts when the module is on and WooCommerce is active.', 'shouse' ) . '</p></div>';
			return;
		}
		global $wpdb;
		$counts = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS changes, COUNT(DISTINCT product_id) AS products FROM %i', self::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: date and time, 2: number of price changes, 3: number of products and variations. */
					__( 'Recording since %1$s. Price changes on record: %2$s, for %3$s products and variations.', 'shouse' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), self::since() ),
					number_format_i18n( (int) ( $counts['changes'] ?? 0 ) ),
					number_format_i18n( (int) ( $counts['products'] ?? 0 ) )
				)
			)
		);
		echo '<p class="description">' . esc_html__( 'A product that was already reduced when recording started has no earlier price on record, so its regular price is shown as the lowest price until its price next changes. `wp shouse omnibus status` lists every reduced product and where its figure comes from.', 'shouse' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Put the price being replaced on record when the history does not already end with it: the first change
	 * after recording started, or a change made while nothing was listening. It is stamped as early as it is
	 * known to apply, which can only lower the figure shown, never raise it.
	 *
	 * @param int        $post_id  Product or variation.
	 * @param list<int>  $meta_ids Rows about to change.
	 */
	private function keep_outgoing( int $post_id, array $meta_ids ): void {
		if ( 1 !== count( $meta_ids ) || ! self::tracked( $post_id ) ) {
			return;
		}
		$meta = get_metadata_by_mid( 'post', $meta_ids[0] );
		if ( ! is_object( $meta ) || ! is_numeric( $meta->meta_value ) || (float) $meta->meta_value <= 0 ) {
			return;
		}
		$price = (float) $meta->meta_value;
		$last  = self::last( $post_id );
		if ( null !== $last && self::same( $last['price'], $price ) ) {
			return;
		}
		$stamp = max( self::since(), null === $last ? 0 : $last['time'] + 1 );
		self::insert( $post_id, $price, min( $stamp, time() ) );
	}

	/** Products and variations with a price of their own. */
	private static function tracked( int $post_id ): bool {
		$type = get_post_type( $post_id );
		if ( 'product_variation' === $type ) {
			return true;
		}
		if ( 'product' !== $type || in_array( WC_Product_Factory::get_product_type( $post_id ), self::DERIVED_TYPES, true ) ) {
			return false;
		}
		return count( (array) get_post_meta( $post_id, '_price', false ) ) <= 1; // Several values: a derived price range.
	}

	/**
	 * Lowest figure among the reduced variations of a variable product, with the variation to format it for.
	 *
	 * @return array{0: WC_Product, 1: array{0: float, 1: string}|null}
	 */
	private static function lowest_of_children( WC_Product $product ): array {
		$target = $product;
		$found  = null;
		foreach ( $product->get_visible_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child instanceof WC_Product || ! $child->is_on_sale() ) {
				continue;
			}
			$lowest = self::lowest( $child );
			if ( null !== $lowest && ( null === $found || $lowest[0] < $found[0] ) ) {
				$target = $child;
				$found  = $lowest;
			}
		}
		return [ $target, $found ];
	}

	private function line( WC_Product $product, float $price ): string {
		$wording = trim( (string) $this->opt( 'label' ) );
		if ( '' === $wording ) {
			/* translators: %s: a price. */
			$wording = __( 'Lowest price in the 30 days before the reduction: %s', 'shouse' );
		} elseif ( ! str_contains( $wording, '%s' ) ) {
			$wording .= ' %s';
		}
		$parts  = explode( '%s', $wording, 2 );
		$amount = wc_price( (float) wc_get_price_to_display( $product, [ 'price' => $price ] ) );
		return '<small class="shouse-omnibus" style="display:block">' . esc_html( $parts[0] ) . wp_kses_post( $amount ) . esc_html( $parts[1] ?? '' ) . '</small>';
	}

	/** @return array{price: float, time: int}|null */
	private static function last( int $product_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT price, recorded_at FROM %i WHERE product_id = %d ORDER BY id DESC LIMIT 1', self::table(), $product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? [
			'price' => (float) $row['price'],
			'time'  => (int) strtotime( $row['recorded_at'] . ' UTC' ),
		] : null;
	}

	private static function insert( int $product_id, float $price, int $time ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'product_id'  => $product_id,
				'price'       => $price,
				'recorded_at' => self::time( $time ),
			],
			[ '%d', '%f', '%s' ]
		);
		unset( self::$memo[ $product_id ] );
	}

	/** Prices are stored with six decimals. */
	private static function same( float $a, float $b ): bool {
		return abs( $a - $b ) < 0.0000005;
	}

	private static function time( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
