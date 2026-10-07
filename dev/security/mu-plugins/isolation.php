<?php
/** Never send real mail or make external HTTP calls from the disposable regression site. */
add_filter( 'pre_wp_mail', '__return_true', 999 );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'fixture_network', 'External network is disabled in security tests.' ), 999 );
