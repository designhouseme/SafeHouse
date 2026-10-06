<?php
/**
 * Before 0.2 the plugin was called WPHouse and its wp-config.php constants WPHOUSE_*. Existing
 * wp-config.php files keep working: every WPHOUSE_* constant gets its SHOUSE_* twin, unless the new
 * name is set too. Loaded by shouse.php and by the object cache, which starts before any plugin.
 *
 * @package SafeHouse
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	foreach ( get_defined_constants( true )['user'] ?? [] as $name => $value ) {
		if ( ! str_starts_with( $name, 'WPHOUSE_' ) || in_array( $name, [ 'WPHOUSE_FILE', 'WPHOUSE_VERSION' ], true ) ) {
			continue;
		}
		$new = 'SHOUSE_' . substr( $name, strlen( 'WPHOUSE_' ) );
		if ( ! defined( $new ) ) {
			define( $new, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- SHOUSE_* by construction.
		}
	}
} )();
