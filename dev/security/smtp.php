<?php
/** Invalid transport configuration must never silently downgrade encryption. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { exit( 1 ); }
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
define( 'SHOUSE_SMTP_SECURE', 'tlss-typo' );
$mailer = new PHPMailer\PHPMailer\PHPMailer( true );
try {
	( new SafeHouse\Modules\Smtp() )->configure( $mailer );
	throw new RuntimeException( 'Invalid TLS mode accepted.' );
} catch ( PHPMailer\PHPMailer\Exception $error ) {
	if ( 'mail' !== $mailer->Mailer || '' !== $mailer->Username || '' !== $mailer->Password ) {
		throw new RuntimeException( 'SMTP was configured before validation.' );
	}
	WP_CLI::success( 'Invalid SMTP encryption fails before transport and credentials are configured.' );
}

// Exercise the actual wp_mail path: configuration errors must be false, never an HTTP fatal.
remove_all_filters( 'pre_wp_mail' );
define( 'SHOUSE_SMTP_HOST', 'smtp-fixture.invalid' );
$failed = 0;
add_action( 'wp_mail_failed', static function () use ( &$failed ) { ++$failed; } );
( new SafeHouse\Modules\Smtp() )->boot();
if ( false !== wp_mail( 'fixture@example.test', 'Fixture', 'No delivery' ) || 1 !== $failed ) {
	throw new RuntimeException( 'Invalid SMTP configuration must return false and report wp_mail_failed.' );
}
WP_CLI::success( 'The real wp_mail call rejects invalid configuration without an escaped exception.' );
