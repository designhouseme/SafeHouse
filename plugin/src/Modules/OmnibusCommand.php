<?php
/**
 * `wp shouse omnibus`: price history and the lowest price shown next to reduced prices.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WC_Product;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect the Omnibus price history.
 */
final class OmnibusCommand {

	/**
	 * List reduced products, the lowest price shown for each and where it comes from.
	 *
	 * "history" means a complete recorded window; "partial" means a shorter observed period.
	 * "none" means there is no evidenced minimum; no regular-price estimate is substituted.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		if ( '' !== Omnibus::recording_error() ) {
			WP_CLI::warning( Omnibus::recording_error() );
		}
		$rows = [];
		foreach ( wc_get_product_ids_on_sale() as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || $product->is_type( [ 'variable', 'grouped', 'variable-subscription' ] ) || ! $product->is_on_sale() ) {
				continue;
			}
			$lowest = Omnibus::lowest( $product );
			$rows[] = [
				'id'      => $id,
				'name'    => $product->get_name(),
				'price'   => $product->get_price( 'edit' ),
				'regular' => $product->get_regular_price( 'edit' ),
				'lowest'  => null === $lowest ? '' : wc_format_decimal( $lowest[0] ),
				'source'  => null === $lowest ? 'none' : $lowest[1],
			];
		}
		WP_CLI::log( sprintf( 'Recording since %s UTC.', gmdate( 'Y-m-d H:i', Omnibus::since() ) ) );
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'id', 'name', 'price', 'regular', 'lowest', 'source' ] );
	}

	/**
	 * Show the recorded prices of one product or variation and the lowest price it shows.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product or variation ID.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function history( array $args, array $assoc_args ): void {
		$product = wc_get_product( (int) $args[0] );
		if ( ! $product instanceof WC_Product ) {
			WP_CLI::error( 'No such product.' );
		}
		$rows = array_map(
			static fn( $row ) => [
				'recorded' => gmdate( 'Y-m-d H:i:s', $row['time'] ) . ' UTC',
				'price'    => wc_format_decimal( $row['price'] ),
			],
			Omnibus::history( $product->get_id() )
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'recorded', 'price' ] );
		if ( 'table' === ( $assoc_args['format'] ?? 'table' ) ) {
			$lowest = Omnibus::lowest( $product );
			WP_CLI::log(
				null === $lowest
					? 'No lowest price: verified history is unavailable.'
					: sprintf( 'Lowest price before the current one: %s (%s)%s.', wc_format_decimal( $lowest[0] ), $lowest[1], $product->is_on_sale() ? '' : '; not shown, the product is not reduced' )
			);
		}
	}
}
