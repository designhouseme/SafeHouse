<?php
/** Let isolated browser tests reach SafeHouse's own comment quota without core's flood delay. */
if ( 'local' === wp_get_environment_type() && defined( 'SHOUSE_HP_BROWSER_TEST' ) && SHOUSE_HP_BROWSER_TEST ) {
	add_filter( 'comment_flood_filter', '__return_false', PHP_INT_MAX );
}
