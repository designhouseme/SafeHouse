<?php
/**
 * Header, body and footer snippets (analytics, pixels, verification tags, chat widgets).
 *
 * HTML and JavaScript only, never PHP: snippet plugins that run PHP have been a direct path to
 * remote code execution (WPCode CVE-2026-8832, Code Snippets CVE-2020-8417). Output is raw by
 * design, so only users with unfiltered_html can change it, and the module is unavailable
 * when DISALLOW_UNFILTERED_HTML is set. Every change is logged with the user.
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use WPHouse\Core\AbstractModule;
use WPHouse\Core\Compat;
use WPHouse\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Scripts extends AbstractModule {

	private const MAX_LENGTH = 20000;

	public function id(): string {
		return 'scripts';
	}

	public function defaults(): array {
		return [
			'head'        => '',
			'body_open'   => '',
			'footer'      => '',
			'skip_admins' => false,
		];
	}

	public function label(): string {
		return __( 'Header and footer scripts', 'wphouse' );
	}

	public function description(): string {
		return __( 'Adds tracking codes, verification tags and widgets to every front-end page. HTML and JavaScript only, never PHP. Only administrators who may post unfiltered HTML can edit them.', 'wphouse' );
	}

	public function fields(): array {
		return [
			'head'        => [
				'type'       => 'code',
				'label'      => __( 'In <head>', 'wphouse' ),
				'help'       => __( 'Printed early in <head>. Google Tag Manager, analytics, verification meta tags.', 'wphouse' ),
				'max_length' => self::MAX_LENGTH,
			],
			'body_open'   => [
				'type'       => 'code',
				'label'      => __( 'After <body>', 'wphouse' ),
				'help'       => __( 'Needs a theme that calls wp_body_open() (all current themes do). GTM noscript iframe.', 'wphouse' ),
				'max_length' => self::MAX_LENGTH,
			],
			'footer'      => [
				'type'       => 'code',
				'label'      => __( 'Before </body>', 'wphouse' ),
				'help'       => __( 'Chat widgets, pixels and other scripts that can load last.', 'wphouse' ),
				'max_length' => self::MAX_LENGTH,
			],
			'skip_admins' => [
				'type'  => 'toggle',
				'label' => __( 'Do not output for logged-in administrators', 'wphouse' ),
				'help'  => __( 'Keeps your own visits out of analytics.', 'wphouse' ),
			],
		];
	}

	public function available(): bool {
		return ! Compat::constant_on( 'DISALLOW_UNFILTERED_HTML' );
	}

	public function unavailable_reason(): string {
		return __( 'DISALLOW_UNFILTERED_HTML is set in wp-config.php, so nobody may add raw scripts.', 'wphouse' );
	}

	public function boot(): void {
		add_action( 'wp_head', [ $this, 'print_head' ], 1 );
		add_action( 'wp_body_open', [ $this, 'print_body_open' ], 1 );
		add_action( 'wp_footer', [ $this, 'print_footer' ], 99 );
	}

	public function print_head(): void {
		$this->output( 'head' );
	}

	public function print_body_open(): void {
		$this->output( 'body_open' );
	}

	public function print_footer(): void {
		$this->output( 'footer' );
	}

	private function output( string $slot ): void {
		$code = (string) $this->opt( $slot );
		if ( '' === trim( $code ) || is_admin() || is_feed() || is_embed() ) {
			return;
		}
		if ( $this->opt( 'skip_admins' ) && current_user_can( 'manage_options' ) ) {
			return;
		}
		echo "\n" . $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw snippets by design; only unfiltered_html users can save them.
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
		$changed = array_keys(
			array_filter(
				[
					'head'      => ( $before['head'] ?? '' ) !== ( $after['head'] ?? '' ),
					'body_open' => ( $before['body_open'] ?? '' ) !== ( $after['body_open'] ?? '' ),
					'footer'    => ( $before['footer'] ?? '' ) !== ( $after['footer'] ?? '' ),
				]
			)
		);
		if ( $changed ) {
			Log::add(
				'scripts_changed',
				'Front-end snippets changed: ' . implode( ', ', $changed ),
				[ 'sha256' => array_map( static fn( $slot ) => hash( 'sha256', (string) ( $after[ $slot ] ?? '' ) ), array_combine( $changed, $changed ) ) ],
				'warning'
			);
		}
	}
}
