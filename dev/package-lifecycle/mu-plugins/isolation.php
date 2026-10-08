<?php
/** Disposable ZIP lifecycle isolation: no mail, external HTTP or live update checks. */
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 'api.wordpress.org' === strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) ) {
			// Local empty responses avoid core's connection warnings while keeping every call offline.
			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'offers'       => [],
						'plugins'      => new stdClass(),
						'themes'       => [],
						'translations' => [],
						'no_update'    => new stdClass(),
					]
				),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
			];
		}
		return new WP_Error( 'lifecycle_network', 'External HTTP is disabled in lifecycle fixtures.' );
	},
	PHP_INT_MAX,
	3
);
