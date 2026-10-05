<?php
/**
 * Send mail through SMTP. Credentials live only in wp-config.php, never in the database:
 *
 *   define( 'WPHOUSE_SMTP_HOST', 'smtp.example.com' );
 *   define( 'WPHOUSE_SMTP_PORT', 587 );            // default 587
 *   define( 'WPHOUSE_SMTP_SECURE', 'tls' );        // tls (default), ssl or '' for none
 *   define( 'WPHOUSE_SMTP_USER', 'user' );         // optional
 *   define( 'WPHOUSE_SMTP_PASS', 'secret' );       // optional
 *
 * There is no mail log: mail logs store password-reset links and have been the way into sites
 * (Post SMTP, CVE-2025-11833). Only delivery failures are logged, without content.
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;
use WPHouse\Core\AbstractModule;
use WPHouse\Core\Log;

defined( 'ABSPATH' ) || exit;

final class Smtp extends AbstractModule {

	public function id(): string {
		return 'smtp';
	}

	public function defaults(): array {
		return [
			'from_email' => '',
			'from_name'  => '',
		];
	}

	public function label(): string {
		return __( 'SMTP mail', 'wphouse' );
	}

	public function description(): string {
		return __( 'Sends all WordPress and WooCommerce mail through your SMTP server, with credentials kept in wp-config.php. No mail log, so password-reset links are never stored.', 'wphouse' );
	}

	public function fields(): array {
		return [
			'from_email' => [
				'type'       => 'text',
				'label'      => __( 'From address', 'wphouse' ),
				'help'       => __( 'Used when the sender is not set otherwise (WooCommerce sets its own). Should be on a domain your SMTP server may send for.', 'wphouse' ),
				'max_length' => 190,
			],
			'from_name'  => [
				'type'       => 'text',
				'label'      => __( 'From name', 'wphouse' ),
				'max_length' => 190,
			],
		];
	}

	public function sanitize( array $input, array $old ): array {
		$clean               = parent::sanitize( $input, $old );
		$clean['from_email'] = is_email( (string) $clean['from_email'] ) ? sanitize_email( (string) $clean['from_email'] ) : '';
		return $clean;
	}

	/** A WPHOUSE_SMTP_* constant from wp-config.php, as a string. */
	private static function config( string $name, string $fallback = '' ): string {
		$constant = 'WPHOUSE_SMTP_' . $name;
		return defined( $constant ) ? (string) constant( $constant ) : $fallback;
	}

	public static function configured(): bool {
		return '' !== self::config( 'HOST' );
	}

	public function boot(): void {
		if ( self::configured() ) {
			add_action( 'phpmailer_init', [ $this, 'configure' ] );
		}
		if ( '' !== $this->opt( 'from_email' ) ) {
			add_filter( 'wp_mail_from', [ $this, 'from_email' ] );
		}
		if ( '' !== $this->opt( 'from_name' ) ) {
			add_filter( 'wp_mail_from_name', [ $this, 'from_name' ] );
		}
		add_action( 'wp_mail_failed', [ $this, 'log_failure' ] );
	}

	public function configure( PHPMailer $mailer ): void {
		$secure = self::config( 'SECURE', 'tls' );
		$mailer->isSMTP();
		$mailer->Host        = self::config( 'HOST' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		$mailer->Port        = (int) self::config( 'PORT', '587' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPSecure  = in_array( $secure, [ 'tls', 'ssl' ], true ) ? $secure : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPAutoTLS = '' !== $secure; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '' !== self::config( 'USER' ) ) {
			$mailer->SMTPAuth = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Username = self::config( 'USER' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Password = self::config( 'PASS' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		// Envelope sender = From, so bounces and SPF line up with the visible sender.
		if ( is_email( $mailer->From ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Sender = $mailer->From; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	public function from_email( string $email ): string {
		// Only replace WordPress's placeholder sender; respect senders set by other code.
		return str_starts_with( $email, 'wordpress@' ) ? (string) $this->opt( 'from_email' ) : $email;
	}

	public function from_name( string $name ): string {
		return 'WordPress' === $name ? (string) $this->opt( 'from_name' ) : $name;
	}

	/** At most one log entry per 10 minutes, so a flood of failing mails cannot flood the log. */
	public function log_failure( WP_Error $error ): void {
		if ( get_transient( 'wphouse_mail_failed_logged' ) ) {
			return;
		}
		set_transient( 'wphouse_mail_failed_logged', 1, 10 * MINUTE_IN_SECONDS );
		Log::add( 'mail_failed', 'Mail delivery failed: ' . $error->get_error_message(), [], 'warning' );
	}

	public function tasks(): array {
		return [ 'test' => __( 'Send a test e-mail to me', 'wphouse' ) ];
	}

	public function handle_task( string $task ): string {
		$user  = wp_get_current_user();
		$error = null;
		$catch = static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e;
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail(
			$user->user_email,
			__( 'WPHouse test e-mail', 'wphouse' ),
			__( 'If you can read this, WordPress can send mail.', 'wphouse' )
		);
		remove_action( 'wp_mail_failed', $catch );
		Log::add( 'mail_test', $sent ? 'Test e-mail sent' : 'Test e-mail failed' );
		if ( $sent ) {
			/* translators: %s: e-mail address. */
			return sprintf( __( 'Test e-mail sent to %s.', 'wphouse' ), $user->user_email );
		}
		/* translators: %s: error message. */
		return sprintf( __( 'Sending failed: %s', 'wphouse' ), $error instanceof WP_Error ? $error->get_error_message() : __( 'unknown error', 'wphouse' ) );
	}

	public function render_panel(): void {
		echo '<p class="wphouse-panel">';
		if ( self::configured() ) {
			$auth = '' !== self::config( 'USER' ) ? __( 'with login', 'wphouse' ) : __( 'without login', 'wphouse' );
			$port = (int) self::config( 'PORT', '587' );
			/* translators: 1: SMTP host, 2: port, 3: "with login" or "without login". */
			echo esc_html( sprintf( __( 'SMTP: %1$s:%2$d, %3$s (from wp-config.php).', 'wphouse' ), self::config( 'HOST' ), $port, $auth ) );
		} else {
			esc_html_e( 'SMTP is not configured: add WPHOUSE_SMTP_HOST (and port, user, password) to wp-config.php. Until then mail goes through PHP mail(); the From settings still apply.', 'wphouse' );
		}
		echo '</p>';
	}
}
