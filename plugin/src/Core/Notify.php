<?php
/**
 * Plain-text alert e-mails to the recipients set in the General section.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use SafeHouse\Plugin;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Notify {

	public static function register(): void {
		Queue::handler( 'mail', [ self::class, 'deliver' ] );
	}

	/**
	 * @param array<string, mixed> $payload Pending alert.
	 * @return true|WP_Error
	 */
	public static function deliver( array $payload ): bool|WP_Error {
		if ( ! isset( $payload['to'], $payload['subject'], $payload['body'] ) || ! is_string( $payload['to'] ) || ! is_string( $payload['subject'] ) || ! is_string( $payload['body'] ) ) {
			return new WP_Error( 'alert_payload', 'Invalid alert payload.' );
		}
		return wp_mail( $payload['to'], $payload['subject'], $payload['body'] ) ? true : new WP_Error( 'alert_mail', 'The mail transport did not accept the alert.' );
	}

	/**
	 * True means durably queued; delivery is retried by WP-Cron until wp_mail accepts it.
	 *
	 * @param string   $subject Subject without the site prefix.
	 * @param string[] $lines   Body lines.
	 * @param string   $also    Extra recipient, e.g. the previous admin e-mail after it was changed.
	 */
	public static function send( string $subject, array $lines, string $also = '' ): bool {
		$recipients = Plugin::instance()->settings->alert_recipients();
		if ( is_email( $also ) ) {
			$recipients = AbstractModule::clean_email_list( $recipients . ', ' . $also );
		}
		if ( '' === $recipients ) {
			return false;
		}
		$site   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$body   = implode( "\n", $lines ) . "\n\n" . home_url( '/' ) . "\n" . Plugin::settings_url() . "\n\n-- \nSafeHouse, Design House\nhttps://designhouse.me/\n";
		$queued = Queue::add(
			'mail',
			[
				'to'      => $recipients,
				'subject' => sprintf( '[SafeHouse] %s: %s', $site, $subject ),
				'body'    => $body,
			]
		);
		if ( $queued ) {
			Queue::run( 'mail', 1 );
		}
		return $queued;
	}
}
