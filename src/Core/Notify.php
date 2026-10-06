<?php
/**
 * Plain-text alert e-mails to the recipients set in the General section.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

use WPHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Notify {

	/**
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
		$body = implode( "\n", $lines ) . "\n\n" . home_url( '/' ) . "\n" . Plugin::settings_url() . "\n\n-- \nWPHouse, Design House\nhttps://designhouse.me/\n";
		return wp_mail( $recipients, sprintf( '[WPHouse] %s: %s', $site, $subject ), $body );
	}
}
