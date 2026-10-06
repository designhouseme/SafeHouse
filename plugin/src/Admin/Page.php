<?php
/**
 * The SafeHouse admin page: one form for all modules, rendered from each module's field schema,
 * split into views (modules, integrations, general) with the activity log beside them.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Admin;

use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use SafeHouse\Core\Integrations;
use SafeHouse\Core\Log;
use SafeHouse\Core\Net;
use SafeHouse\Core\ObjectCache;
use SafeHouse\Core\SafeMode;
use SafeHouse\Core\Settings;
use SafeHouse\Core\Turnstile;
use SafeHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Page {

	private const SLUG = 'shouse';

	private const VIEWS = [ 'modules', 'integrations', 'general' ];

	/** The Design House blocks from assets/icon.svg. WordPress paints a base64 SVG in the colour scheme's icon colour. */
	private const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNTEgMTA2Ij48ZyBmaWxsPSJibGFjayI+PHJlY3QgeD0iNzMuMTgiIHdpZHRoPSIzMS43MyIgaGVpZ2h0PSIzMS43MyIgcng9IjUuODgiLz48cmVjdCB5PSIzMS44MiIgd2lkdGg9IjczLjM5IiBoZWlnaHQ9IjczLjM5IiByeD0iNS44OCIvPjxyZWN0IHg9IjEwNC44IiB5PSIzMS44MSIgd2lkdGg9IjQ1LjUiIGhlaWdodD0iNDUuNSIgcng9IjUuODgiLz48L2c+PC9zdmc+';

	public function __construct( private Plugin $plugin ) {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'load-toplevel_page_' . self::SLUG, [ $this, 'redirect_old_url' ] );
		add_action( 'admin_page_access_denied', [ $this, 'redirect_legacy_slug' ] );
		add_action( 'admin_init', [ $this->plugin->settings, 'register' ] );
		add_action( 'admin_post_shouse_task', [ $this, 'handle_task' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_filter( 'admin_footer_text', [ $this, 'footer_text' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( SHOUSE_FILE ), [ $this, 'action_links' ] );
	}

	public function menu(): void {
		add_menu_page( 'SafeHouse', 'SafeHouse', 'manage_options', self::SLUG, [ $this, 'render' ], self::MENU_ICON, 81 );
	}

	/**
	 * The page used to live under Settings, and old bookmarks and alert e-mails still link to
	 * options-general.php?page=shouse. WordPress serves a top-level page under any parent file,
	 * so that URL still loads; move it to the real address so the menu highlights correctly.
	 */
	public function redirect_old_url(): void {
		global $pagenow;
		if ( 'admin.php' !== $pagenow ) {
			$view = $this->current_view();
			wp_safe_redirect( 'modules' === $view ? Plugin::settings_url() : add_query_arg( 'tab', $view, Plugin::settings_url() ) );
			exit;
		}
	}

	/**
	 * Before the rename to SafeHouse the page was admin.php?page=wphouse, and alert e-mails sent then
	 * still link there. WordPress refuses an unknown page before any of our hooks, except this one.
	 */
	public function redirect_legacy_slug(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a redirect target.
		if ( 'wphouse' === $page && current_user_can( 'manage_options' ) ) {
			$view = $this->current_view();
			wp_safe_redirect( 'modules' === $view ? Plugin::settings_url() : add_query_arg( 'tab', $view, Plugin::settings_url() ) );
			exit;
		}
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'shouse-admin', plugins_url( 'assets/admin.css', SHOUSE_FILE ), [], SHOUSE_VERSION );
			wp_enqueue_script(
				'shouse-admin',
				plugins_url( 'assets/admin.js', SHOUSE_FILE ),
				[],
				SHOUSE_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( Plugin::settings_url() ) . '">' . esc_html__( 'Settings', 'shouse' ) . '</a>' );
		return $links;
	}

	/**
	 * Replaces "Thank you for creating with WordPress" on our page only.
	 *
	 * @param mixed $text Footer text so far.
	 * @return mixed
	 */
	public function footer_text( mixed $text ): mixed {
		$screen = get_current_screen();
		if ( null === $screen || 'toplevel_page_' . self::SLUG !== $screen->id ) {
			return $text;
		}
		return 'SafeHouse, <a href="https://designhouse.me/" target="_blank" rel="noopener">Design House</a>';
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( SafeMode::active() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'SafeHouse safe mode is on: every module is stopped. Remove wp-content/shouse-safe-mode or the SHOUSE_SAFE_MODE constant to resume.', 'shouse' )
			);
		}
		$key     = 'shouse_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	public function handle_task(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'shouse' ), 403 );
		}
		check_admin_referer( 'shouse_task' );

		$raw           = isset( $_POST['task'] ) ? sanitize_text_field( wp_unslash( $_POST['task'] ) ) : '';
		[ $id, $task ] = array_map( 'sanitize_key', array_pad( explode( ':', $raw, 2 ), 2, '' ) );
		$module        = $this->plugin->module( $id );
		if ( null === $module || ! $this->plugin->is_running( $id ) || ! array_key_exists( $task, $module->tasks() ) ) {
			wp_die( esc_html__( 'Unknown task.', 'shouse' ), 400 );
		}

		set_transient( 'shouse_notice_' . get_current_user_id(), $module->handle_task( $task ), MINUTE_IN_SECONDS );
		wp_safe_redirect( Plugin::settings_url( $id ) );
		exit;
	}

	/** The view named by ?tab=. options.php returns there after saving, because the form's referer keeps it. */
	private function current_view(): string {
		$view = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a view.
		return in_array( $view, self::VIEWS, true ) ? $view : 'modules';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$locked  = Settings::locked();
		$view    = $this->current_view();
		$labels  = [
			'modules'      => __( 'Modules', 'shouse' ),
			'integrations' => __( 'Integrations', 'shouse' ),
			'general'      => __( 'General', 'shouse' ),
		];
		$modules = $this->plugin->modules();
		$running = count( array_filter( array_keys( $modules ), [ $this->plugin, 'is_running' ] ) );
		?>
		<div class="wrap shouse">
			<div class="shouse-head">
				<h1><img class="shouse-head__icon" src="<?php echo esc_url( plugins_url( 'assets/icon.svg', SHOUSE_FILE ) ); ?>" width="32" height="32" alt="">SafeHouse <span class="shouse-version"><?php echo esc_html( SHOUSE_VERSION ); ?></span></h1>
				<nav class="shouse-tabs" aria-label="SafeHouse">
					<?php foreach ( self::VIEWS as $key ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', $key, Plugin::settings_url() ) ); ?>" data-view="<?php echo esc_attr( $key ); ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $labels[ $key ] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<span class="shouse-count">
					<?php
					/* translators: 1: number of modules running, 2: number of modules. */
					echo esc_html( sprintf( __( '%1$d of %2$d running', 'shouse' ), $running, count( $modules ) ) );
					?>
				</span>
				<a class="shouse-maker" href="https://designhouse.me/" target="_blank" rel="noopener"><img src="<?php echo esc_url( plugins_url( 'assets/designhouse.svg', SHOUSE_FILE ) ); ?>" width="128" height="16" alt="Design House"></a>
			</div>
			<hr class="wp-header-end">

			<?php if ( $locked ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Settings are locked by SHOUSE_LOCK_SETTINGS in wp-config.php. Change them in code.', 'shouse' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Compat::ignore_overlaps() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'SHOUSE_IGNORE_OVERLAPS is on: features run even where another plugin already provides them.', 'shouse' ); ?></p></div>
			<?php endif; ?>

			<div class="shouse-layout">
				<div class="shouse-main">
					<form method="post" action="options.php" class="shouse-form">
						<?php settings_fields( 'shouse' ); ?>

						<div class="shouse-view" id="shouse-view-modules"<?php echo 'modules' === $view ? '' : ' hidden'; ?>>
							<p class="shouse-intro"><?php esc_html_e( 'Small, audited replacements for single-purpose plugins. Every module is a switch. Features that Wordfence or wp-config already handle are skipped automatically.', 'shouse' ); ?></p>
							<div class="shouse-modules">
								<?php
								foreach ( $modules as $id => $module ) {
									$this->render_module( $id, $module, $locked );
								}
								?>
							</div>
						</div>

						<div class="shouse-view" id="shouse-view-integrations"<?php echo 'integrations' === $view ? '' : ' hidden'; ?>>
							<?php $this->render_integrations(); ?>
						</div>

						<div class="shouse-view" id="shouse-view-general"<?php echo 'general' === $view ? '' : ' hidden'; ?>>
							<section class="shouse-card" id="shouse-general">
								<header class="shouse-card__head"><h2><?php esc_html_e( 'General', 'shouse' ); ?></h2></header>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="shouse-general-alert_emails"><?php esc_html_e( 'Alert recipients', 'shouse' ); ?></label></th>
										<td>
											<input type="text" class="regular-text" id="shouse-general-alert_emails" name="<?php echo esc_attr( Settings::OPTION ); ?>[general][alert_emails]" value="<?php echo esc_attr( (string) $this->plugin->settings->value( 'general', 'alert_emails', '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" <?php disabled( $locked ); ?>>
											<p class="description"><?php esc_html_e( 'Comma-separated. Empty means the site admin address.', 'shouse' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="shouse-general-proxy"><?php esc_html_e( 'Proxy in front of the site', 'shouse' ); ?></label></th>
										<td>
											<?php $proxy_pinned = defined( 'SHOUSE_TRUSTED_PROXIES' ); ?>
											<select id="shouse-general-proxy" name="<?php echo esc_attr( Settings::OPTION ); ?>[general][proxy]" <?php disabled( $locked || $proxy_pinned ); ?>>
												<option value=""><?php esc_html_e( 'None: visitors connect directly', 'shouse' ); ?></option>
												<option value="cloudflare" <?php selected( Net::behind_cloudflare() ); ?>>Cloudflare</option>
											</select>
											<p class="description">
												<?php
												echo esc_html(
													$proxy_pinned
														? __( 'Set in wp-config.php with SHOUSE_TRUSTED_PROXIES.', 'shouse' )
														: __( 'With Cloudflare, SafeHouse takes the visitor address from Cloudflare\'s header, but only on connections that really come from Cloudflare. Login limits and the activity log depend on it.', 'shouse' )
												);
												?>
											</p>
										</td>
									</tr>
								</table>
							</section>
						</div>

						<?php
						if ( ! $locked ) {
							submit_button();
						}
						?>
					</form>

					<form id="shouse-task-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="shouse_task">
						<?php wp_nonce_field( 'shouse_task' ); ?>
					</form>
				</div>

				<?php $this->render_log(); ?>
			</div>
		</div>
		<?php
	}

	private function render_module( string $id, AbstractModule $module, bool $locked ): void {
		$settings  = $this->plugin->settings;
		$enabled   = $settings->module_enabled( $module );
		$forced    = $settings->forced( $id );
		$available = $module->available();
		$running   = $this->plugin->is_running( $id );

		if ( ! $available ) {
			$status = [ 'muted', __( 'Unavailable', 'shouse' ) ];
		} elseif ( $running ) {
			$status = [ 'on', __( 'Running', 'shouse' ) ];
		} elseif ( $enabled ) {
			$status = [ 'warn', __( 'Enabled, not running', 'shouse' ) ];
		} else {
			$status = [ 'off', __( 'Off', 'shouse' ) ];
		}
		$name = Settings::OPTION . '[modules][' . $id . ']';
		$body = 'shouse-' . $id . '-settings';
		?>
		<section class="shouse-module<?php echo $enabled ? '' : ' is-off'; ?>" id="shouse-<?php echo esc_attr( $id ); ?>">
			<div class="shouse-module__head">
				<h2 class="shouse-module__title"><label class="shouse-switch">
					<input type="checkbox" role="switch" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $enabled ); ?> <?php disabled( $locked || null !== $forced ); ?>>
					<span class="shouse-module__name"><?php echo esc_html( $module->label() ); ?></span>
				</label></h2>
				<span class="shouse-status shouse-status--<?php echo esc_attr( $status[0] ); ?>"><?php echo esc_html( $status[1] ); ?></span>
				<button type="button" class="button-link shouse-module__more" aria-expanded="true" aria-controls="<?php echo esc_attr( $body ); ?>" hidden><?php esc_html_e( 'Details', 'shouse' ); ?><span class="screen-reader-text"> <?php echo esc_html( $module->label() ); ?></span></button>
			</div>
			<div class="shouse-module__body" id="<?php echo esc_attr( $body ); ?>">
				<p class="shouse-module__desc"><?php echo esc_html( $module->description() ); ?></p>
				<?php if ( null !== $forced ) : ?>
					<p class="shouse-note"><?php esc_html_e( 'Pinned by SHOUSE_MODULES in wp-config.php.', 'shouse' ); ?></p>
				<?php endif; ?>
				<?php if ( ! $available ) : ?>
					<p class="shouse-note"><?php echo esc_html( $module->unavailable_reason() ); ?></p>
				<?php endif; ?>

				<?php $fields = $module->schema(); ?>
				<?php if ( $fields ) : ?>
					<table class="form-table" role="presentation">
						<?php foreach ( $fields as $key => $field ) : ?>
							<?php $this->render_field( $id, $key, $field, $module, $locked ); ?>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>

				<?php if ( $running ) : ?>
					<?php $module->render_panel(); ?>
					<?php if ( $module->tasks() ) : ?>
						<p class="shouse-tasks">
							<?php foreach ( $module->tasks() as $task => $label ) : ?>
								<button type="submit" class="button" form="shouse-task-form" name="task" value="<?php echo esc_attr( $id . ':' . $task ); ?>"><?php echo esc_html( $label ); ?></button>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $field Field schema.
	 */
	private function render_field( string $id, string $key, array $field, AbstractModule $module, bool $locked ): void {
		$value     = $this->plugin->settings->value( $id, $key, $module->defaults()[ $key ] ?? null );
		$name      = Settings::OPTION . '[' . $id . '][' . $key . ']';
		$dom_id    = 'shouse-' . $id . '-' . $key;
		$is_locked = $locked || ! empty( $field['locked'] );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $dom_id ); ?>"><?php echo esc_html( (string) $field['label'] ); ?></label></th>
			<td>
				<?php
				switch ( $field['type'] ) {
					case 'toggle':
						printf(
							'<input type="checkbox" id="%s" name="%s" value="1" %s %s>',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							checked( (bool) $value, true, false ),
							disabled( $is_locked, true, false )
						);
						break;
					case 'textarea':
					case 'html':
					case 'code':
						printf(
							'<textarea id="%s" name="%s" rows="%d" class="large-text%s" %s>%s</textarea>',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							'code' === $field['type'] ? 8 : 4,
							'code' === $field['type'] ? ' code' : '',
							disabled( $is_locked, true, false ),
							esc_textarea( (string) $value )
						);
						break;
					case 'number':
						printf(
							'<input type="number" id="%s" name="%s" value="%s" class="small-text" %s %s %s>',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							esc_attr( (string) $value ),
							isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : '',
							isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : '',
							disabled( $is_locked, true, false )
						);
						break;
					case 'select':
						printf( '<select id="%s" name="%s" %s>', esc_attr( $dom_id ), esc_attr( $name ), disabled( $is_locked, true, false ) );
						foreach ( (array) $field['options'] as $option_value => $option_label ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $option_value ), selected( (string) $value, (string) $option_value, false ), esc_html( (string) $option_label ) );
						}
						echo '</select>';
						break;
					default:
						printf(
							'<input type="text" id="%s" name="%s" value="%s" class="regular-text" %s>',
							esc_attr( $dom_id ),
							esc_attr( $name ),
							esc_attr( (string) $value ),
							disabled( $is_locked, true, false )
						);
				}
				if ( ! empty( $field['covered_by'] ) ) {
					/* translators: %s: plugin or setting name, e.g. Wordfence. */
					echo '<span class="shouse-covered">' . esc_html( sprintf( __( 'Handled by %s, skipped here.', 'shouse' ), (string) $field['covered_by'] ) ) . '</span>';
				}
				if ( ! empty( $field['help'] ) ) {
					echo '<p class="description">' . esc_html( (string) $field['help'] ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	/** What SafeHouse does alongside the plugins it is built for, and which of them this site has. */
	private function render_integrations(): void {
		$found = Integrations::detected();
		$rows  = [
			'wordfence'   => [ 'Wordfence', __( 'SafeHouse skips what Wordfence already does (for example username discovery and login error masking) and leaves the firewall, two-factor login and malware scans to it. Vulnerability alerts stand down, because Wordfence warns about vulnerable plugins itself.', 'shouse' ) ],
			'woocommerce' => [ 'WooCommerce', __( 'Compatible with HPOS and the block checkout. Reduced prices show the lowest price from the previous 30 days (Omnibus). Generic login errors also cover the My Account form, maintenance mode lets the Store API and payment callbacks through, and product reviews survive "disable comments".', 'shouse' ) ],
			'payments'    => [ __( 'Payment gateways', 'shouse' ), __( 'Autopay, Przelewy24, PayU, imoje, Paynow, Stripe, PayPal and WooPayments. Their callbacks (?wc-api= and the REST API) pass maintenance mode, and XML-RPC stays on for WooPayments.', 'shouse' ) ],
			'redis'       => [ __( 'Redis object cache', 'shouse' ), __( 'SafeHouse has its own Redis object cache: install it with "wp shouse object-cache enable". Every cached value is signed, so other sites on a shared Redis cannot plant data. A site that uses the Redis Object Cache plugin instead shows its status here.', 'shouse' ) ],
			'cloudflare'  => [ 'Cloudflare', __( 'Real visitor addresses behind Cloudflare (General, "Proxy in front of the site"), cache clearing after changes (the Cloudflare cache module, token in wp-config.php) and Turnstile on forms (Bot protection). SafeHouse contacts Cloudflare only for the parts that are on.', 'shouse' ) ],
			'elementor'   => [ 'Elementor', __( 'Elementor and Elementor Pro. Watched for new vulnerabilities like everything on this list.', 'shouse' ) ],
		];
		?>
		<section class="shouse-card" id="shouse-integrations">
			<header class="shouse-card__head"><h2><?php esc_html_e( 'Integrations', 'shouse' ); ?></h2></header>
			<p class="shouse-card__desc"><?php esc_html_e( 'SafeHouse is built to run alongside these plugins. Design House watches all of them for new vulnerabilities (CVE) and tells you when an update cannot wait.', 'shouse' ); ?></p>
			<table class="widefat striped shouse-integrations">
				<thead><tr>
					<th><?php esc_html_e( 'Integration', 'shouse' ); ?></th>
					<th><?php esc_html_e( 'On this site', 'shouse' ); ?></th>
					<th><?php esc_html_e( 'What SafeHouse does', 'shouse' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $group => [ $label, $text ] ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td>
							<?php
							$items = $found[ $group ] ?? [];
							if ( 'cloudflare' === $group ) {
								$this->render_cloudflare_status();
							} elseif ( 'redis' === $group ) {
								$this->render_object_cache_status( $items );
							} elseif ( ! $items ) {
								echo '<span class="shouse-badge">' . esc_html__( 'not installed', 'shouse' ) . '</span>';
								if ( 'wordfence' === $group ) {
									echo '<p class="description">' . esc_html(
										$this->plugin->is_running( 'vulnerabilities' )
											? __( 'SafeHouse vulnerability alerts cover this site. For a firewall and two-factor login, install Wordfence.', 'shouse' )
											: __( 'Nothing warns about vulnerable plugins on this site: switch on SafeHouse vulnerability alerts below, or install Wordfence.', 'shouse' )
									) . '</p>';
								}
							}
							if ( ! in_array( $group, [ 'redis', 'cloudflare' ], true ) ) {
								foreach ( $items as $item ) {
									$this->render_plugin_status( $item );
								}
							}
							?>
						</td>
						<td><?php echo esc_html( $text ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * @param array{name: string, active: bool} $item Detected plugin.
	 */
	private function render_plugin_status( array $item ): void {
		echo '<div><span class="shouse-status shouse-status--' . ( $item['active'] ? 'on' : 'off' ) . '">' . esc_html( $item['active'] ? __( 'active', 'shouse' ) : __( 'inactive', 'shouse' ) ) . '</span> ' . esc_html( $item['name'] ) . '</div>';
	}

	/** Which Cloudflare parts are on: visitor addresses, cache clearing, Turnstile. */
	private function render_cloudflare_status(): void {
		$parts = [
			[ Net::behind_cloudflare(), __( 'visitor addresses', 'shouse' ) ],
			[ $this->plugin->is_running( 'cloudflare' ), __( 'cache clearing', 'shouse' ) ],
			[ $this->plugin->is_running( 'bots' ) && Turnstile::configured(), 'Turnstile' ],
		];
		foreach ( $parts as [ $on, $label ] ) {
			echo '<div><span class="shouse-status shouse-status--' . ( $on ? 'on' : 'off' ) . '">' . esc_html( $on ? __( 'on', 'shouse' ) : __( 'off', 'shouse' ) ) . '</span> ' . esc_html( $label ) . '</div>';
		}
	}

	/**
	 * Our drop-in first; otherwise the Redis Object Cache plugin or any other object cache.
	 *
	 * @param list<array{name: string, active: bool}> $items Redis Object Cache plugin, if installed.
	 */
	private function render_object_cache_status( array $items ): void {
		if ( 'ours' === ObjectCache::dropin_state() ) {
			$cache = ObjectCache::active();
			$up    = null !== $cache && $cache->redis_status();
			echo '<div><span class="shouse-status shouse-status--' . ( $up ? 'on' : 'warn' ) . '">' . esc_html( $up ? __( 'connected', 'shouse' ) : __( 'Redis not reachable', 'shouse' ) ) . '</span> ' . esc_html__( 'SafeHouse object cache', 'shouse' ) . '</div>';
			return;
		}
		foreach ( $items as $item ) {
			$this->render_plugin_status( $item );
		}
		$other = Integrations::object_cache();
		if ( 'redis' === $other['type'] ) {
			$note = $other['connected'] ? __( 'Object cache connected to Redis.', 'shouse' ) : __( 'Object cache drop-in installed, but Redis is not reachable.', 'shouse' );
		} elseif ( 'other' === $other['type'] ) {
			$note = __( 'Another persistent object cache is in use.', 'shouse' );
		} elseif ( ! $items ) {
			echo '<span class="shouse-badge">' . esc_html__( 'not installed', 'shouse' ) . '</span>';
			return;
		} else {
			return;
		}
		echo '<p class="description">' . esc_html( $note ) . '</p>';
	}

	private function render_log(): void {
		$rows = Log::recent( 50 );
		?>
		<aside class="shouse-log" id="shouse-log" aria-labelledby="shouse-log-title">
			<h2 id="shouse-log-title">
				<?php esc_html_e( 'Activity log', 'shouse' ); ?>
				<?php if ( $rows ) : ?>
					<span class="shouse-log__count"><?php echo esc_html( count( $rows ) < 50 ? (string) count( $rows ) : '50+' ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'No events yet.', 'shouse' ); ?></p>
			<?php else : ?>
				<ol class="shouse-log__list">
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$user = (int) $row->user_id ? get_userdata( (int) $row->user_id ) : false;
						$time = strtotime( $row->created_at . ' UTC' );
						?>
						<li class="shouse-log__item shouse-log__item--<?php echo esc_attr( $row->severity ); ?>">
							<span class="shouse-log__msg"><?php echo esc_html( $row->message ); ?></span>
							<span class="shouse-log__meta">
								<code class="shouse-log__event"><?php echo esc_html( $row->event ); ?></code>
								<?php if ( (int) $row->user_id ) : ?>
									<span><?php echo esc_html( $user ? $user->user_login : '#' . $row->user_id ); ?></span>
								<?php endif; ?>
								<time datetime="<?php echo esc_attr( $time ? gmdate( 'c', $time ) : '' ); ?>" title="<?php echo esc_attr( $row->created_at . ' UTC' ); ?>">
									<?php
									/* translators: %s: time since the event, e.g. "5 mins". */
									echo esc_html( $time ? sprintf( __( '%s ago', 'shouse' ), human_time_diff( $time ) ) : $row->created_at );
									?>
								</time>
								<?php if ( '' !== (string) $row->ip ) : ?>
									<span><?php echo esc_html( $row->ip ); ?></span>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</aside>
		<?php
	}
}
