<?php
/** Permission regression; run with wp eval-file on a disposable local/development site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	throw new RuntimeException( 'This test requires a disposable local/development WP-CLI site.' );
}
register_taxonomy( 'shouse_restricted', [ 'post' ], [ 'capabilities' => [ 'assign_terms' => 'manage_options' ] ] );
register_post_meta( 'post', '_shouse_restricted', [ 'single' => true, 'type' => 'string', 'auth_callback' => static fn() => current_user_can( 'manage_options' ) ] );
register_post_meta( 'post', 'shouse_restricted_public', [ 'single' => true, 'type' => 'string', 'auth_callback' => static fn() => current_user_can( 'manage_options' ) ] );
register_post_meta( 'post', '_shouse_allowed', [ 'single' => true, 'type' => 'string', 'auth_callback' => static fn() => current_user_can( 'edit_posts' ) ] );
$user_id = wp_insert_user( [ 'user_login' => 'shouse-duplicate-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'author' ] );
if ( is_wp_error( $user_id ) ) {
	throw new RuntimeException( $user_id->get_error_message() );
}
$source = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Duplicate permission fixture', 'post_author' => $user_id ] );
register_post_meta( 'post', '_shouse_source_only', [ 'single' => true, 'type' => 'string', 'auth_callback' => static fn( $allowed, $key, $post_id ) => $post_id === $source ] );
add_filter( 'auth_post_meta__wp_page_template', '__return_false' );
$values = [ '_shouse_source_only' => 'private', '_wp_page_template' => 'restricted-template.php', '_shouse_restricted' => 'private', 'shouse_restricted_public' => 'private', '_shouse_unknown' => 'private', '_shouse_allowed' => 'allowed', 'shouse_public' => 'allowed', '_thumbnail_id' => 123 ];
foreach ( $values as $key => $value ) {
	update_post_meta( $source, $key, $value );
}
$restricted = wp_insert_term( wp_generate_password( 10, false ), 'shouse_restricted' );
$allowed = wp_insert_term( wp_generate_password( 10, false ), 'category' );
wp_set_object_terms( $source, [ (int) $restricted['term_id'] ], 'shouse_restricted' );
wp_set_object_terms( $source, [ (int) $allowed['term_id'] ], 'category' );
wp_set_current_user( $user_id );
$_GET['post'] = $source;
$_REQUEST['_wpnonce'] = wp_create_nonce( 'shouse_duplicate_' . $source );
register_shutdown_function( static function () use ( $user_id, $source, $restricted, $allowed ) {
	global $wpdb;
	$copy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author=%d AND ID<>%d ORDER BY ID DESC LIMIT 1", $user_id, $source ) );
	$checks = [
		'created draft owned by author' => $copy && 'draft' === get_post_status( $copy ) && $user_id === (int) get_post_field( 'post_author', $copy ),
		'protected authorization retained' => '' === get_post_meta( $copy, '_shouse_restricted', true ),
		'public metadata authorization retained' => '' === get_post_meta( $copy, 'shouse_restricted_public', true ),
		'destination metadata authorization retained' => '' === get_post_meta( $copy, '_shouse_source_only', true ),
		'custom core field policy retained' => '' === get_post_meta( $copy, '_wp_page_template', true ),
		'unknown protected state omitted' => '' === get_post_meta( $copy, '_shouse_unknown', true ),
		'authorized registered metadata copied' => 'allowed' === get_post_meta( $copy, '_shouse_allowed', true ),
		'public metadata copied' => 'allowed' === get_post_meta( $copy, 'shouse_public', true ),
		'core presentation metadata copied' => '123' === get_post_meta( $copy, '_thumbnail_id', true ),
		'restricted taxonomy omitted' => [] === wp_get_object_terms( $copy, 'shouse_restricted', [ 'fields' => 'ids' ] ),
		'allowed taxonomy copied' => [ (int) $allowed['term_id'] ] === wp_get_object_terms( $copy, 'category', [ 'fields' => 'ids' ] ),
	];
	foreach ( $checks as $name => $passed ) {
		echo ( $passed ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	}
	wp_set_current_user( 1 );
	if ( $copy ) {
		wp_delete_post( $copy, true );
	}
	wp_delete_post( $source, true );
	wp_delete_term( (int) $restricted['term_id'], 'shouse_restricted' );
	wp_delete_term( (int) $allowed['term_id'], 'category' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );
	if ( in_array( false, $checks, true ) ) {
		exit( 1 );
	}
} );
remove_filter( 'wp_redirect', 'WP_CLI\\Utils\\wp_redirect_handler' );
( new SafeHouse\Modules\Duplicate() )->handle();
