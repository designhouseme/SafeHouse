<?php
/** Run with wp eval-file on a disposable local WordPress; Woo product formatting may be stubbed. */
use SafeHouse\Modules\Omnibus;
use SafeHouse\Plugin;

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable WP_ENVIRONMENT_TYPE=local WordPress.' );
}
if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		public function __construct( private int $id ) {}
		public function get_id(): int { return $this->id; }
		public function get_price( $context = 'view' ) { return get_post_meta( $this->id, '_price', true ); }
		public function get_regular_price( $context = 'view' ) { return get_post_meta( $this->id, '_regular_price', true ); }
		public function is_on_sale(): bool { return (float) $this->get_price() < (float) $this->get_regular_price(); }
		public function is_type( $types ): bool { return in_array( 'simple', (array) $types, true ); }
	}
	class WC_Product_Factory { public static function get_product_type( int $id ): string { return 'simple'; } }
	class WC_Product_Variable extends WC_Product {
		public function is_type( $types ): bool { return in_array( 'variable', (array) $types, true ); }
		public function get_visible_children(): array { return (array) get_post_meta( $this->get_id(), '_fixture_children', true ); }
	}
	function wc_price( $price ): string { return number_format( (float) $price, 2, '.', '' ); }
	function wc_get_price_to_display( $product, $args ): float { return (float) $args['price']; }
	function wc_get_product( $id ): WC_Product { return get_post_meta( $id, '_fixture_children', true ) ? new WC_Product_Variable( $id ) : new WC_Product( $id ); }
	function wc_get_product_ids_on_sale(): array { return $GLOBALS['shouse_omnibus_fixture_ids'] ?? []; }
}

global $wpdb;
$backup = [];
foreach ( [ 'shouse_omnibus_since', 'shouse_omnibus_db_version', 'shouse_omnibus_error' ] as $option ) { $backup[ $option ] = get_option( $option, null ); }
$module = new Omnibus();
if ( ! Plugin::instance()->is_running( 'omnibus' ) ) { $module->boot(); }
$ids = [];
$messages = [];
$failed = [];
$assert = static function ( bool $passed, string $name ) use ( &$messages, &$failed ): void {
	$messages[] = ( $passed ? 'ok   ' : 'FAIL ' ) . $name;
	if ( ! $passed ) { $failed[] = $name; }
};
$memo = new ReflectionProperty( Omnibus::class, 'memo' );
$memo->setAccessible( true );
$clear = static fn() => $memo->setValue( null, [] );
$new_product = static function ( string $price = '80' ) use ( &$ids ): WC_Product {
	$id = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Local Omnibus regression' ] );
	$ids[] = $id;
	$GLOBALS['shouse_omnibus_fixture_ids'] = $ids;
	update_post_meta( $id, '_regular_price', '999' );
	update_post_meta( $id, '_price', $price );
	return new WC_Product( $id );
};
$now = time();
$fixture = static function ( WC_Product $product, array $rows ) use ( $wpdb, $now, $clear ): void {
	$wpdb->delete( Omnibus::table(), [ 'product_id' => $product->get_id() ] );
	foreach ( $rows as [ $offset, $price ] ) {
		$wpdb->insert( Omnibus::table(), [ 'product_id' => $product->get_id(), 'price' => $price, 'recorded_at' => gmdate( 'Y-m-d H:i:s', $now + $offset ) ] );
	}
	update_option( 'shouse_omnibus_since', $now - 1000 * DAY_IN_SECONDS, false );
	$clear();
};
$query_fault = null;
$old_prefix = $wpdb->prefix;
$old_suppress = $wpdb->suppress_errors( true );
try {
	delete_option( 'shouse_omnibus_error' );
	$assert( Omnibus::maybe_install( true ), 'price history schema and time index verified' );
	$product = $new_product();
	$assert( null === Omnibus::lowest( $product ), 'already reduced product has no invented regular-price minimum' );
	$html = $module->price_html( '<del>999</del><ins>80</ins>', $product );
	$assert( str_contains( $html, 'unavailable' ) && ! str_contains( $html, 'reduction: 999' ), 'shopper sees unavailable history rather than a fabricated minimum' );
	$assert( null === Omnibus::lowest_before( [ [ 'time' => $now - 40 * DAY_IN_SECONDS, 'price' => 100.0 ] ], 80, $now ), 'an unseen price change has no assumed start date' );

	$id = $product->get_id();
	update_post_meta( $id, '_price', '0' );
	update_post_meta( $id, '_price', '50' );
	$product = new WC_Product( $id );
	$lowest = Omnibus::lowest( $product );
	$assert( [ 0.0, 'partial' ] === $lowest, 'a free period is recorded as zero and counted in the minimum' );
	$html = $module->price_html( '<del>999</del><ins>50</ins>', $product );
	$assert( str_contains( $html, '0.00' ) && str_contains( $html, 'incomplete 30-day history' ), 'zero minimum is displayed with the partial-history qualification' );

	$dense = $new_product( '90' );
	$rows = [ [ -50 * DAY_IN_SECONDS, 100 ], [ -20 * DAY_IN_SECONDS, 10 ] ];
	for ( $i = 0; $i < 1200; ++$i ) { $rows[] = [ -19 * DAY_IN_SECONDS + $i, 100 + $i % 2 ]; }
	$rows[] = [ 0, 90 ];
	$fixture( $dense, $rows );
	$assert( [ 10.0, 'history' ] === Omnibus::lowest( $dense ), 'minimum survives more than 1000 changes in the lookback window' );
	$assert( 1203 === count( Omnibus::history( $dense->get_id() ) ), 'CLI history is not silently truncated at 1000 rows' );

	$long = $new_product( '90' );
	$fixture( $long, [ [ -700 * DAY_IN_SECONDS, 1 ], [ -500 * DAY_IN_SECONDS, 100 ], [ -410 * DAY_IN_SECONDS, 10 ], [ -405 * DAY_IN_SECONDS, 100 ], [ -400 * DAY_IN_SECONDS, 90 ] ] );
	$assert( [ 10.0, 'history' ] === Omnibus::lowest( $long ), 'a sale older than one year still looks back from its own start' );
	$module->purge();
	$clear();
	$assert( [ 10.0, 'history' ] === Omnibus::lowest( $long ), 'purge preserves the complete window before a long active price' );
	$assert( [ 100.0, 10.0, 100.0, 90.0 ] === array_column( Omnibus::history( $long->get_id() ), 'price' ), 'purge removes only rows before the retained boundary price' );

	$boundary = $new_product( '90' );
	$fixture( $boundary, [ [ -70 * DAY_IN_SECONDS, 10 ], [ -50 * DAY_IN_SECONDS, 200 ], [ -30 * DAY_IN_SECONDS, 120 ], [ 0, 90 ] ] );
	$assert( [ 120.0, 'history' ] === Omnibus::lowest( $boundary ), 'price exactly on the window boundary counts, older expired lows do not' );
	$steady = $new_product( '90' );
	$fixture( $steady, [ [ -400 * DAY_IN_SECONDS, 100 ], [ 0, 90 ] ] );
	$assert( [ 100.0, 'history' ] === Omnibus::lowest( $steady ), 'a long unchanged price supplies the window boundary' );

	$known_child = $new_product( '80' );
	$fixture( $known_child, [ [ -50 * DAY_IN_SECONDS, 100 ], [ 0, 80 ] ] );
	$unknown_child = $new_product( '80' );
	$parent = $new_product( '80' );
	update_post_meta( $parent->get_id(), '_fixture_children', [ $known_child->get_id(), $unknown_child->get_id() ] );
	$parent = new WC_Product_Variable( $parent->get_id() );
	$assert( str_contains( $module->price_html( '<del>999</del><ins>80</ins>', $parent ), 'unavailable' ), 'unknown variation prevents an invented minimum on the shared price line' );
	$fixture( $unknown_child, [ [ -5 * DAY_IN_SECONDS, 100 ], [ 0, 80 ] ] );
	$assert( str_contains( $module->price_html( '<del>999</del><ins>80</ins>', $parent ), 'incomplete 30-day history' ), 'partial variation qualifies the shared minimum as incomplete' );
	ob_start();
	$module->render_panel();
	$panel = ob_get_clean();
	$assert( str_contains( $panel, 'reduced products have missing or incomplete price history' ), 'admin panel identifies reduced products needing history review' );
	$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Omnibus::table() );
	$module->price_html( '<del>999</del><ins>90</ins>', $dense );
	$assert( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Omnibus::table() ), 'rendering a price never inserts historical observations' );

	$observed = $new_product( '100' );
	$wpdb->delete( Omnibus::table(), [ 'product_id' => $observed->get_id() ] );
	update_option( 'shouse_omnibus_since', $now - 20 * DAY_IN_SECONDS, false );
	update_post_meta( $observed->get_id(), '_price', '80' );
	$rows = Omnibus::history( $observed->get_id() );
	$assert( 2 === count( $rows ) && $rows[0]['time'] >= $now, 'outgoing price is recorded only when observed, never backdated to activation' );
	$clear();
	$assert( [ 100.0, 'partial' ] === Omnibus::lowest( new WC_Product( $observed->get_id() ) ), 'same-second outgoing/incoming observations retain order without inventing coverage' );

	$fixture( $steady, [ [ -400 * DAY_IN_SECONDS, 100 ], [ -10 * DAY_IN_SECONDS, 90 ] ] );
	$assert( [ 100.0, 'history' ] === Omnibus::lowest( $steady ), 'recorded minimum can be memoized before disabling' );
	$module->settings_saved( [], [], false, true );
	$assert( null === Omnibus::lowest( $steady ), 're-enabling recording does not bridge an unobserved gap' );
	update_post_meta( $steady->get_id(), '_price', '80' );
	$assert( [ 90.0, 'partial' ] === Omnibus::lowest( new WC_Product( $steady->get_id() ) ), 'first change after a recording gap observes the outgoing price at the new date' );

	$broken = $new_product( '100' );
	$table = Omnibus::table();
	$query_fault = static fn( string $query ): string => str_starts_with( $query, 'INSERT INTO `' . $table . '`' ) ? str_replace( '`' . $table . '`', '`shouse_regression_missing_table`', $query ) : $query;
	add_filter( 'query', $query_fault );
	update_post_meta( $broken->get_id(), '_price', '80' );
	remove_filter( 'query', $query_fault );
	$query_fault = null;
	$assert( '' !== Omnibus::recording_error(), 'failed durable insert leaves a persistent recording error' );
	$clear();
	$assert( null === Omnibus::lowest( $dense ), 'recording failure pauses historical claims instead of silently using incomplete data' );
	ob_start();
	$module->render_panel();
	$panel = ob_get_clean();
	$assert( str_contains( $panel, 'could not be recorded' ), 'administrator can see the recording failure' );
	$module->handle_task( 'restart_recording' );
	$assert( '' === Omnibus::recording_error() && Omnibus::since() >= $now, 'explicit restart verifies storage and starts a new observation period' );
	$assert( null === Omnibus::lowest( $long ), 'restart never restores claims based on the incomplete earlier period' );

	delete_option( 'shouse_omnibus_db_version' );
	$wpdb->prefix = $old_prefix . 'omnibus_denied_';
	$denied_table = Omnibus::table();
	$query_fault = static fn( string $query ): string => str_contains( $query, 'CREATE TABLE ' . $denied_table ) ? 'INVALID SCHEMA REGRESSION FIXTURE' : $query;
	add_filter( 'query', $query_fault );
	$assert( ! Omnibus::maybe_install(), 'schema creation failure is reported' );
	$assert( false === get_option( 'shouse_omnibus_db_version' ), 'schema version is not marked installed after failure' );
	$assert( '' !== Omnibus::recording_error(), 'schema failure persists an administrator-visible error' );
	remove_filter( 'query', $query_fault );
	$query_fault = null;
	$wpdb->prefix = $old_prefix;
} finally {
	if ( $query_fault ) { remove_filter( 'query', $query_fault ); }
	$wpdb->prefix = $old_prefix;
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); $wpdb->delete( Omnibus::table(), [ 'product_id' => $id ] ); }
	foreach ( $backup as $option => $value ) { if ( null === $value ) { delete_option( $option ); } else { update_option( $option, $value, false ); } }
	$wpdb->suppress_errors( $old_suppress );
	$clear();
}
echo implode( "\n", $messages ) . "\n" . count( $messages ) . ' checks, ' . count( $failed ) . " failures.\n";
if ( $failed ) { throw new RuntimeException( implode( '; ', $failed ) ); }
