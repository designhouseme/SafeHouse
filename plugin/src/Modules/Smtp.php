<?php
/**
 * Send mail through SMTP. Credentials live only in wp-config.php, never in the database:
 *
 *   define( 'SHOUSE_SMTP_HOST', 'smtp.example.com' );
 *   define( 'SHOUSE_SMTP_PORT', 587 );            // default 587
 *   define( 'SHOUSE_SMTP_SECURE', 'tls' );        // tls (default), ssl or '' for none
 *   define( 'SHOUSE_SMTP_USER', 'user' );         // optional
 *   define( 'SHOUSE_SMTP_PASS', 'secret' );       // optional
 *
 * There is no mail log: mail logs store password-reset links and have been the way into sites
 * (Post SMTP, CVE-2025-11833). Only delivery failures are logged, without content.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use WP_Error;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Log;

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
		return __( 'SMTP mail', 'shouse' );
	}

	public function description(): string {
		return __( 'Sends all WordPress and WooCommerce mail through your SMTP server, with credentials kept in wp-config.php. No mail log, so password-reset links are never stored.', 'shouse' );
	}

	public function fields(): array {
		return [
			'from_email' => [
				'type'       => 'text',
				'label'      => __( 'From address', 'shouse' ),
				'help'       => __( 'Used when the sender is not set otherwise (WooCommerce sets its own). Should be on a domain your SMTP server may send for.', 'shouse' ),
				'max_length' => 190,
			],
			'from_name'  => [
				'type'       => 'text',
				'label'      => __( 'From name', 'shouse' ),
				'max_length' => 190,
			],
		];
	}

	public function sanitize( array $input, array $old ): array {
		$clean               = parent::sanitize( $input, $old );
		$clean['from_email'] = is_email( (string) $clean['from_email'] ) ? sanitize_email( (string) $clean['from_email'] ) : '';
		return $clean;
	}

	/** A SHOUSE_SMTP_* constant from wp-config.php, as a string. */
	private static function config( string $name, string $fallback = '' ): string {
		$constant = 'SHOUSE_SMTP_' . $name;
		return defined( $constant ) ? (string) constant( $constant ) : $fallback;
	}

	public static function configured(): bool {
		return '' !== self::config( 'HOST' );
	}

	public function boot(): void {
		if ( self::configured() ) {
			add_action( 'phpmailer_init', [ $this, 'configure' ] );
			add_filter( 'pre_wp_mail', [ $this, 'preflight' ], PHP_INT_MAX );
		}
		if ( '' !== $this->opt( 'from_email' ) ) {
			add_filter( 'wp_mail_from', [ $this, 'from_email' ] );
		}
		if ( '' !== $this->opt( 'from_name' ) ) {
			add_filter( 'wp_mail_from_name', [ $this, 'from_name' ] );
		}
		add_action( 'wp_mail_failed', [ $this, 'log_failure' ] );
	}

	/** Fail through WordPress's mail API, before phpmailer_init (which core calls outside its try block). */
	public function preflight( mixed $pre ): mixed {
		$error = self::configuration_error();
		if ( null !== $pre || '' === $error ) {
			return $pre;
		}
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- preserve the core mail failure contract.
		return false;
	}

	private static function configuration_error(): string {
		if ( ! in_array( self::config( 'SECURE', 'tls' ), [ 'tls', 'ssl', '' ], true ) ) {
			return 'Invalid SHOUSE_SMTP_SECURE: use tls, ssl, or an explicit empty string.';
		}
		if ( false === filter_var(
			self::config( 'PORT', '587' ),
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 1,
					'max_range' => 65535,
				],
			]
		) ) {
			return 'Invalid SHOUSE_SMTP_PORT.';
		}
		return '';
	}

	public function configure( PHPMailer $mailer ): void {
		$error = self::configuration_error();
		if ( '' !== $error ) {
			throw new Exception( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- fixed configuration messages, no HTML output.
		}
		$secure = self::config( 'SECURE', 'tls' );
		$port   = (int) self::config( 'PORT', '587' );
		$mailer->isSMTP();
		$mailer->Host        = self::config( 'HOST' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		$mailer->Port        = $port; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPSecure  = $secure; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
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
		if ( get_transient( 'shouse_mail_failed_logged' ) ) {
			return;
		}
		set_transient( 'shouse_mail_failed_logged', 1, 10 * MINUTE_IN_SECONDS );
		Log::add( 'mail_failed', 'Mail delivery failed: ' . $error->get_error_message(), [], 'warning' );
	}

	public function tasks(): array {
		return [ 'test' => __( 'Send a test e-mail to me', 'shouse' ) ];
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
			__( 'SafeHouse test e-mail', 'shouse' ),
			__( 'If you can read this, WordPress can send mail.', 'shouse' )
		);
		remove_action( 'wp_mail_failed', $catch );
		Log::add( 'mail_test', $sent ? 'Test e-mail sent' : 'Test e-mail failed' );
		if ( $sent ) {
			/* translators: %s: e-mail address. */
			return sprintf( __( 'Test e-mail sent to %s.', 'shouse' ), $user->user_email );
		}
		/* translators: %s: error message. */
		return sprintf( __( 'Sending failed: %s', 'shouse' ), $error instanceof WP_Error ? $error->get_error_message() : __( 'unknown error', 'shouse' ) );
	}

	public function render_panel(): void {
		echo '<p class="shouse-panel">';
		if ( self::configured() ) {
			$auth = '' !== self::config( 'USER' ) ? __( 'with login', 'shouse' ) : __( 'without login', 'shouse' );
			$port = (int) self::config( 'PORT', '587' );
			/* translators: 1: SMTP host, 2: port, 3: "with login" or "without login". */
			echo esc_html( sprintf( __( 'SMTP: %1$s:%2$d, %3$s (from wp-config.php).', 'shouse' ), self::config( 'HOST' ), $port, $auth ) );
		} else {
			esc_html_e( 'SMTP is not configured: add SHOUSE_SMTP_HOST (and port, user, password) to wp-config.php. Until then mail goes through PHP mail(); the From settings still apply.', 'shouse' );
		}
		echo '</p>';
	}
}
