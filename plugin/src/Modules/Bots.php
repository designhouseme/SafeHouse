<?php
/**
 * Bot protection for public forms: a honeypot that needs no outside service, and Cloudflare
 * Turnstile when its keys are in wp-config.php (see Core\Turnstile).
 *
 * Every check is tied to the handler of one form (wp-login.php, the WooCommerce form handler,
 * wp-comments-post.php, the Store API checkout route), never to a field the client could leave
 * out, and never to wp_signon() or retrieve_password() in general. Other plugins, wp-admin,
 * WP-CLI, cron and REST with application passwords are not affected.
 *
 * Deliberately absent: the honeypot on login forms (password managers submit at once) and on
 * checkout (browser autofill fills hidden fields, and the Store API cannot carry the field).
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_Error;
use WP_REST_Request;
use WP_User;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use SafeHouse\Core\Honeypot;
use SafeHouse\Core\Log;
use SafeHouse\Core\Turnstile;

defined( 'ABSPATH' ) || exit;

final class Bots extends AbstractModule {

	private const STORE_CHECKOUT = '#^/wc/store(?:/v\d+)?/checkout(?:/|$)#i';
	private const FORMS          = [ 'login', 'register', 'lostpassword', 'comments', 'checkout' ];
	private const WIDGET_HOOKS   = [
		'login_form'                             => 'login',
		'woocommerce_login_form'                 => 'login',
		'register_form'                          => 'register',
		'woocommerce_register_form'              => 'register',
		'lostpassword_form'                      => 'lostpassword',
		'woocommerce_lostpassword_form'          => 'lostpassword',
		'comment_form_after_fields'              => 'comments',
		'woocommerce_review_order_before_submit' => 'checkout',
	];
	private const HONEYPOT_HOOKS = [ 'register_form', 'woocommerce_register_form', 'lostpassword_form', 'woocommerce_lostpassword_form', 'comment_form_after_fields' ];

	private bool $honeypot = false;

	/** @var array<string, true> Forms with Turnstile on in this request. */
	private array $turnstile = [];

	/** @var list<object> One scope per authenticate call, including nested calls. */
	private array $login_operations = [];

	/** @var \WeakMap<object, true>|null Only scopes created for a particular wp_new_comment() call. */
	private ?\WeakMap $comment_operations = null;

	public function id(): string {
		return 'bots';
	}

	public function defaults(): array {
		return [
			'honeypot'               => true,
			'turnstile_login'        => true,
			'turnstile_register'     => true,
			'turnstile_lostpassword' => true,
			'turnstile_comments'     => true,
			'turnstile_checkout'     => true,
			'when_unavailable'       => 'allow',
		];
	}

	public function coverage(): array {
		$covered = [];
		if ( Compat::wordfence_login_captcha() ) {
			$covered['turnstile_login']    = 'Wordfence Login Security';
			$covered['turnstile_register'] = 'Wordfence Login Security';
		}
		return $covered;
	}

	public function label(): string {
		return __( 'Bot protection', 'shouse' );
	}

	public function description(): string {
		return __( 'Stops automated sign-ups, spam comments, password-reset floods, login attempts and fake orders. A honeypot works on its own; Cloudflare Turnstile is added when its keys are in wp-config.php.', 'shouse' );
	}

	public function fields(): array {
		return [
			'honeypot'               => [
				'type'  => 'toggle',
				'label' => __( 'Honeypot on registration, lost password and comments', 'shouse' ),
				'help'  => __( 'A rotating trap field, one-use browser challenges and limits on repeated submissions. Needs JavaScript and cookies, but no outside service. Automated browsers can still pass within the limits; use Turnstile for stronger checks.', 'shouse' ),
			],
			'turnstile_login'        => [
				'type'  => 'toggle',
				'label' => __( 'Turnstile on login', 'shouse' ),
				'help'  => __( 'wp-login.php, login forms made with wp_login_form() and the WooCommerce login form.', 'shouse' ),
			],
			'turnstile_register'     => [
				'type'  => 'toggle',
				'label' => __( 'Turnstile on registration', 'shouse' ),
				'help'  => __( 'WordPress and WooCommerce My Account registration.', 'shouse' ),
			],
			'turnstile_lostpassword' => [
				'type'  => 'toggle',
				'label' => __( 'Turnstile on lost password', 'shouse' ),
			],
			'turnstile_comments'     => [
				'type'  => 'toggle',
				'label' => __( 'Turnstile on comments and reviews', 'shouse' ),
				'help'  => __( 'For visitors who are not logged in.', 'shouse' ),
			],
			'turnstile_checkout'     => [
				'type'  => 'toggle',
				'label' => __( 'Turnstile on checkout', 'shouse' ),
				'help'  => __( 'Classic and block checkout, including orders sent straight to the Store API. Express payment buttons outside the checkout page (such as Apple Pay or Google Pay on product pages) cannot pass this check, so turn it off on sites that use them.', 'shouse' ),
			],
			'when_unavailable'       => [
				'type'    => 'select',
				'label'   => __( 'When Cloudflare cannot be reached', 'shouse' ),
				'help'    => __( 'Applies only when this server cannot reach Cloudflare, or Cloudflare rejects the secret key. A visitor whose browser blocks Turnstile is still asked to try again.', 'shouse' ),
				'options' => [
					'allow' => __( 'Let the submission through and log it', 'shouse' ),
					'block' => __( 'Block the submission', 'shouse' ),
				],
			],
		];
	}

	public function boot(): void {
		$this->honeypot = (bool) $this->opt( 'honeypot' );
		if ( Turnstile::configured() ) {
			foreach ( self::FORMS as $form ) {
				if ( $this->feature_on( 'turnstile_' . $form ) ) {
					$this->turnstile[ $form ] = true;
				}
			}
		}

		if ( $this->honeypot ) {
			Honeypot::boot();
			foreach ( self::HONEYPOT_HOOKS as $hook ) {
				add_action( $hook, [ $this, 'print_honeypot' ] );
			}
		}
		foreach ( self::WIDGET_HOOKS as $hook => $form ) {
			if ( isset( $this->turnstile[ $form ] ) ) {
				add_action( $hook, [ $this, 'print_widget' ] );
			}
		}

		if ( isset( $this->turnstile['login'] ) ) {
			add_filter( 'login_form_middle', [ $this, 'login_form_middle' ] );
			add_filter( 'authenticate', [ $this, 'begin_login' ], 1 );
			// Core honours this error before hashing; authenticate alone cannot short-circuit core.
			add_filter( 'wp_authenticate_user', [ $this, 'check_before_password' ], PHP_INT_MAX );
			add_filter( 'authenticate', [ $this, 'check_wp_login' ], 30, 3 );
			add_filter( 'authenticate', [ $this, 'end_login' ], PHP_INT_MAX );
			add_filter( 'woocommerce_process_login_errors', [ $this, 'check_woo_login' ] );
		}
		if ( $this->honeypot || isset( $this->turnstile['register'] ) ) {
			add_filter( 'registration_errors', [ $this, 'check_wp_register' ] );
			add_filter( 'woocommerce_process_registration_errors', [ $this, 'check_woo_register' ] );
		}
		if ( $this->honeypot || isset( $this->turnstile['lostpassword'] ) ) {
			add_action( 'lostpassword_post', [ $this, 'check_lostpassword' ] );
		}
		if ( $this->honeypot || isset( $this->turnstile['comments'] ) ) {
			add_filter( 'preprocess_comment', [ $this, 'begin_comment' ], 1 );
			add_filter( 'pre_comment_approved', [ $this, 'check_comment' ], 99, 2 );
		}
		if ( isset( $this->turnstile['checkout'] ) ) {
			add_action( 'woocommerce_after_checkout_validation', [ $this, 'check_classic_checkout' ], 10, 2 );
			add_filter( 'rest_request_before_callbacks', [ $this, 'check_store_api' ], 10, 3 );
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout' ] );
		}
	}

	public function print_honeypot(): void {
		$this->enqueue();
		$form = self::WIDGET_HOOKS[ current_action() ] ?? '';
		echo Honeypot::markup( $form, 'comments' === $form ? (int) get_the_ID() : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Honeypot::markup().
	}

	public function print_widget(): void {
		$form = self::WIDGET_HOOKS[ current_action() ] ?? '';
		if ( isset( $this->turnstile[ $form ] ) ) {
			$this->enqueue();
			echo Turnstile::widget( 'shouse_' . $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Turnstile::widget().
		}
	}

	/** Login forms built with wp_login_form() post to wp-login.php, so they need the widget too. */
	public function login_form_middle( mixed $html ): string {
		$this->enqueue();
		return (string) $html . Turnstile::widget( 'shouse_login' );
	}

	public function begin_login( mixed $user ): mixed {
		$this->login_operations[] = new \stdClass();
		return $user;
	}

	public function end_login( mixed $user ): mixed {
		array_pop( $this->login_operations );
		return $user;
	}

	public function check_before_password( mixed $user ): mixed {
		return $user instanceof WP_User ? $this->check_wp_login( $user, $user->user_login ) : $user;
	}

	/**
	 * @param mixed $user     WP_User, WP_Error or null so far.
	 * @param mixed $username Submitted username.
	 * @param mixed $password Submitted password.
	 */
	public function check_wp_login( mixed $user, mixed $username = '', mixed $password = '' ): mixed {
		// wp-login.php also calls wp_signon() with empty credentials just to show the form.
		if ( ! self::posted_to_wp_login() || ( '' === (string) $username && '' === (string) $password ) ) {
			return $user;
		}
		$operation = $this->login_operations ? $this->login_operations[ array_key_last( $this->login_operations ) ] : null;
		$error     = $this->verdict( 'login', false, $operation );
		return '' === $error ? $user : new WP_Error( 'shouse_bots', $error );
	}

	public function check_woo_login( WP_Error $errors ): WP_Error {
		return $this->add_verdict( $errors, 'login', false );
	}

	public function check_wp_register( WP_Error $errors ): WP_Error {
		return self::posted_to_wp_login() ? $this->add_verdict( $errors, 'register', true ) : $errors;
	}

	public function check_woo_register( WP_Error $errors ): WP_Error {
		return $this->add_verdict( $errors, 'register', true );
	}

	/** retrieve_password() also runs for "Send password reset" in wp-admin; only the two public forms are checked. */
	public function check_lostpassword( WP_Error $errors ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checks its nonce; we only read which form this is.
		if ( self::posted_to_wp_login() || isset( $_POST['wc_reset_password'] ) ) {
			$this->add_verdict( $errors, 'lostpassword', true );
		}
	}

	/**
	 * Core can check approval twice, including after content filtering. Carry one server-created
	 * scope through that call; nested or subsequent wp_new_comment() calls get their own scope.
	 * The private marker is not a comment column or metadata and is not persisted by core.
	 *
	 * @param array<string, mixed> $data Comment data.
	 * @return array<string, mixed>
	 */
	public function begin_comment( array $data ): array {
		if ( ! is_user_logged_in() && did_action( 'pre_comment_on_post' ) ) {
			$scope                              = new \stdClass();
			$this->comment_operations         ??= new \WeakMap();
			$this->comment_operations[ $scope ] = true;
			$data['_shouse_bot_operation']      = $scope;
		}
		return $data;
	}

	/**
	 * Only submissions through wp-comments-post.php (wp_handle_comment_submission) by visitors.
	 *
	 * @param mixed                $approved Approval so far.
	 * @param array<string, mixed> $data     Comment data, including the internal operation marker.
	 */
	public function check_comment( mixed $approved, array $data = [] ): mixed {
		if ( is_wp_error( $approved ) || is_user_logged_in() || ! did_action( 'pre_comment_on_post' ) ) {
			return $approved;
		}
		$scope  = $data['_shouse_bot_operation'] ?? null;
		$scope  = is_object( $scope ) && isset( $this->comment_operations[ $scope ] ) ? $scope : null;
		$target = isset( $data['comment_post_ID'] ) && is_scalar( $data['comment_post_ID'] ) ? absint( $data['comment_post_ID'] ) : null;
		$error  = $this->verdict( 'comments', true, $scope, $target );
		return '' === $error ? $approved : new WP_Error( 'shouse_bots', $error, 403 );
	}

	/**
	 * @param array<string, mixed> $data   Posted checkout data.
	 * @param WP_Error             $errors Checkout errors.
	 */
	public function check_classic_checkout( array $data, WP_Error $errors ): void {
		// No order is placed anyway; keep the token for the corrected submission.
		if ( ! $errors->has_errors() ) {
			$this->add_verdict( $errors, 'checkout', false );
		}
	}

	/**
	 * Every POST to the Store API checkout routes places or pays for an order, whatever its query
	 * string says (the ?__experimental_calc_totals bypass). Method and route come from the request
	 * object, so method overrides and batch requests are seen as the route sees them.
	 *
	 * @param mixed                                 $response Response so far.
	 * @param array<string, mixed>                  $handler  Matched route handler.
	 * @param WP_REST_Request<array<string, mixed>> $request  Request.
	 */
	public function check_store_api( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		if ( is_wp_error( $response ) || 'POST' !== $request->get_method() || ! preg_match( self::STORE_CHECKOUT, $request->get_route() ) ) {
			return $response;
		}
		$error = $this->turnstile_verdict( 'checkout', sanitize_text_field( (string) $request->get_header( Turnstile::HEADER ) ), $request );
		return '' === $error ? $response : new WP_Error( 'shouse_bots', $error, [ 'status' => 403 ] );
	}

	/** The block checkout prints no PHP form hook, so its widget is placed by assets/bots.js. */
	public function enqueue_checkout(): void {
		// Not has_block(): a block theme can put the checkout block in a template instead of the page,
		// and a checkout without the script would turn every order away. bots.js looks at the DOM.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$this->enqueue( true );
		}
	}

	private function enqueue( bool $checkout = false ): void {
		if ( wp_script_is( 'shouse-bots' ) ) {
			return;
		}
		wp_enqueue_script( 'shouse-bots', plugins_url( 'assets/bots.js', SHOUSE_FILE ), $checkout ? [ 'wp-api-fetch' ] : [], SHOUSE_VERSION . '-forms-v3', [ 'in_footer' => true ] );
		$config = [
			'sitekey'                   => $this->turnstile ? Turnstile::site_key() : '',
			'checkout'                  => $checkout,
			'header'                    => Turnstile::HEADER,
			'challengeUrl'              => admin_url( 'admin-ajax.php', 'relative' ),
			'challengeError'            => __( 'The form check could not finish. Enable cookies and JavaScript, then submit again.', 'shouse' ),
			'challengeRateError'        => __( 'Too many attempts. Please wait a few minutes and try again.', 'shouse' ),
			'challengeUnavailableError' => __( 'The form check is unavailable right now. Please try again in a few minutes.', 'shouse' ),
		];
		wp_add_inline_script( 'shouse-bots', 'window.shouseBots = ' . wp_json_encode( $config ) . ';', 'before' );
		if ( $this->turnstile ) {
			wp_enqueue_script(
				'shouse-turnstile',
				Turnstile::SCRIPT_URL,
				[ 'shouse-bots' ],
				null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare asks for the URL as is.
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}
	}

	private function add_verdict( WP_Error $errors, string $form, bool $honeypot ): WP_Error {
		// Validation already failed: keep the one-use token available for a corrected request.
		if ( $errors->has_errors() ) {
			return $errors;
		}
		$error = $this->verdict( $form, $honeypot, $errors );
		if ( '' !== $error ) {
			$errors->add( 'shouse_bots', $error );
		}
		return $errors;
	}

	/** '' when the submission may go on, otherwise the message to show. */
	private function verdict( string $form, bool $honeypot, ?object $operation = null, ?int $target = null ): string {
		if ( $honeypot && $this->honeypot ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bound to the challenge; core validates the target post.
			$target ??= 'comments' === $form && isset( $_POST['comment_post_ID'] ) && is_scalar( $_POST['comment_post_ID'] ) ? absint( $_POST['comment_post_ID'] ) : 0;
			$reason   = Honeypot::check( $form, $target, $operation );
			if ( '' !== $reason ) {
				$this->note( $form, 'honeypot_' . $reason );
				if ( 'rate_limited' === $reason ) {
					return __( 'Too many attempts. Please wait a few minutes and try again.', 'shouse' );
				}
				if ( in_array( $reason, [ 'storage', 'capacity' ], true ) ) {
					return __( 'The form check is unavailable right now. Please try again in a few minutes.', 'shouse' );
				}
				return __( 'The form check did not pass. Reload the page, allow cookies and JavaScript, and try again.', 'shouse' );
			}
		}
		if ( ! isset( $this->turnstile[ $form ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token is the check; each form keeps its own nonce.
		$token = isset( $_POST[ Turnstile::FIELD ] ) && is_string( $_POST[ Turnstile::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Turnstile::FIELD ] ) ) : '';
		return $this->turnstile_verdict( $form, $token, $operation );
	}

	private function turnstile_verdict( string $form, string $token, ?object $operation = null ): string {
		$result = Turnstile::verify( $token, 'shouse_' . $form, $operation );
		if ( Turnstile::PASSED === $result ) {
			return '';
		}
		if ( Turnstile::UNAVAILABLE === $result ) {
			$this->note( $form, 'cloudflare unavailable', 'warning' );
			return 'allow' === $this->opt( 'when_unavailable' ) ? '' : __( 'The anti-bot check is unavailable right now. Please try again in a few minutes.', 'shouse' );
		}
		$this->note( $form, 'turnstile' );
		return __( 'The anti-bot check did not pass. Please wait for it to finish and try again.', 'shouse' );
	}

	private static function posted_to_wp_login(): bool {
		global $pagenow;
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return 'wp-login.php' === $pagenow && 'POST' === $method;
	}

	/** One log line per form and reason every 10 minutes, so a flood of bots cannot flood the log. */
	private function note( string $form, string $reason, string $severity = 'info' ): void {
		$key = 'shouse_bots_' . md5( $form . $reason );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		Log::add( 'bot_blocked', sprintf( 'Bot protection on %s: %s. Repeats are not logged for 10 minutes.', $form, $reason ), [], $severity );
	}

	public function render_panel(): void {
		$problem = Turnstile::problem();
		if ( 'missing' === $problem ) {
			$text = __( 'Turnstile is off: add SHOUSE_TURNSTILE_SITE_KEY and SHOUSE_TURNSTILE_SECRET_KEY to wp-config.php. The honeypot works without them.', 'shouse' );
		} elseif ( 'test_keys' === $problem ) {
			$text = __( 'Turnstile is off: wp-config.php has Cloudflare test keys, which always give the same answer and are not allowed on a production site.', 'shouse' );
		} else {
			$text = __( 'Turnstile is on, with keys from wp-config.php.', 'shouse' );
		}
		echo '<p class="shouse-panel">' . esc_html( $text ) . '</p>';
	}
}
