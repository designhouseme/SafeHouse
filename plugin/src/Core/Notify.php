<?php
/**
 * Plain-text alert e-mails to the recipients set in the General section.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
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
			return new WP_Error( 'alert_payload', 'Invalid alert payload.', [ 'permanent' => true ] );
		}
		/** @var array{PHPMailer, int, SMTP, int, int}|null $restore */
		$restore = null;
		$bound   = static function ( PHPMailer $mailer ) use ( &$restore ): void {
			// PHPMailer applies Timeout to SMTP connect/read; Timelimit bounds SMTP command reads.
			// Other plugins replacing wp_mail remain outside this cooperative transport boundary.
			$smtp            = $mailer->getSMTPInstance();
			$restore         = [ $mailer, $mailer->Timeout, $smtp, $smtp->Timeout, $smtp->Timelimit ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
			$mailer->Timeout = 5; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$smtp->Timeout   = 5; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$smtp->Timelimit = 5; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $bound, PHP_INT_MAX );
		try {
			return wp_mail( $payload['to'], $payload['subject'], $payload['body'] ) ? true : new WP_Error( 'alert_mail', 'The mail transport did not accept the alert.' );
		} finally {
			remove_action( 'phpmailer_init', $bound, PHP_INT_MAX );
			if ( null !== $restore ) {
				$restore[0]->Timeout   = $restore[1]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$restore[2]->Timeout   = $restore[3]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$restore[2]->Timelimit = $restore[4]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
	}

	/**
	 * True means durably queued. WP-Cron delivers it; exhausted retries remain paused for review.
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
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$body = implode( "\n", $lines ) . "\n\n" . home_url( '/' ) . "\n" . Plugin::settings_url() . "\n\n-- \nSafeHouse, Design House\nhttps://designhouse.me/\n";
		return Queue::add(
			'mail',
			[
				'to'      => $recipients,
				'subject' => sprintf( '[SafeHouse] %s: %s', $site, $subject ),
				'body'    => $body,
			]
		);
	}
}
