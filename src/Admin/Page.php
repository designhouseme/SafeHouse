<?php
/**
 * The WPHouse admin page: one form for all modules, rendered from each module's field schema,
 * split into views (modules, integrations, general) with the activity log beside them.
 *
 * @package WPHouse
 */

namespace WPHouse\Admin;

use WPHouse\Core\AbstractModule;
use WPHouse\Core\Compat;
use WPHouse\Core\Integrations;
use WPHouse\Core\Log;
use WPHouse\Core\ObjectCache;
use WPHouse\Core\SafeMode;
use WPHouse\Core\Settings;
use WPHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Page {

	private const SLUG = 'wphouse';

	private const VIEWS = [ 'modules', 'integrations', 'general' ];

	/** The Design House blocks from assets/icon.svg. WordPress paints a base64 SVG in the colour scheme's icon colour. */
	private const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNTEgMTA2Ij48ZyBmaWxsPSJibGFjayI+PHJlY3QgeD0iNzMuMTgiIHdpZHRoPSIzMS43MyIgaGVpZ2h0PSIzMS43MyIgcng9IjUuODgiLz48cmVjdCB5PSIzMS44MiIgd2lkdGg9IjczLjM5IiBoZWlnaHQ9IjczLjM5IiByeD0iNS44OCIvPjxyZWN0IHg9IjEwNC44IiB5PSIzMS44MSIgd2lkdGg9IjQ1LjUiIGhlaWdodD0iNDUuNSIgcng9IjUuODgiLz48L2c+PC9zdmc+';

	public function __construct( private Plugin $plugin ) {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'load-toplevel_page_' . self::SLUG, [ $this, 'redirect_old_url' ] );
		add_action( 'admin_init', [ $this->plugin->settings, 'register' ] );
		add_action( 'admin_post_wphouse_task', [ $this, 'handle_task' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_filter( 'admin_footer_text', [ $this, 'footer_text' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WPHOUSE_FILE ), [ $this, 'action_links' ] );
	}

	public function menu(): void {
		add_menu_page( 'WPHouse', 'WPHouse', 'manage_options', self::SLUG, [ $this, 'render' ], self::MENU_ICON, 81 );
	}

	/**
	 * The page used to live under Settings, and old bookmarks and alert e-mails still link to
	 * options-general.php?page=wphouse. WordPress serves a top-level page under any parent file,
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

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( 'wphouse-admin', plugins_url( 'assets/admin.css', WPHOUSE_FILE ), [], WPHOUSE_VERSION );
			wp_enqueue_script(
				'wphouse-admin',
				plugins_url( 'assets/admin.js', WPHOUSE_FILE ),
				[],
				WPHOUSE_VERSION,
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
		array_unshift( $links, '<a href="' . esc_url( Plugin::settings_url() ) . '">' . esc_html__( 'Settings', 'wphouse' ) . '</a>' );
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
		return 'WPHouse, <a href="https://designhouse.me/" target="_blank" rel="noopener">Design House</a>';
	}

	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( SafeMode::active() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'WPHouse safe mode is on: every module is stopped. Remove wp-content/wphouse-safe-mode or the WPHOUSE_SAFE_MODE constant to resume.', 'wphouse' )
			);
		}
		$key     = 'wphouse_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	public function handle_task(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wphouse' ), 403 );
		}
		check_admin_referer( 'wphouse_task' );

		$raw           = isset( $_POST['task'] ) ? sanitize_text_field( wp_unslash( $_POST['task'] ) ) : '';
		[ $id, $task ] = array_map( 'sanitize_key', array_pad( explode( ':', $raw, 2 ), 2, '' ) );
		$module        = $this->plugin->module( $id );
		if ( null === $module || ! $this->plugin->is_running( $id ) || ! array_key_exists( $task, $module->tasks() ) ) {
			wp_die( esc_html__( 'Unknown task.', 'wphouse' ), 400 );
		}

		set_transient( 'wphouse_notice_' . get_current_user_id(), $module->handle_task( $task ), MINUTE_IN_SECONDS );
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
			'modules'      => __( 'Modules', 'wphouse' ),
			'integrations' => __( 'Integrations', 'wphouse' ),
			'general'      => __( 'General', 'wphouse' ),
		];
		$modules = $this->plugin->modules();
		$running = count( array_filter( array_keys( $modules ), [ $this->plugin, 'is_running' ] ) );
		?>
		<div class="wrap wphouse">
			<div class="wphouse-head">
				<h1><img class="wphouse-head__icon" src="<?php echo esc_url( plugins_url( 'assets/icon.svg', WPHOUSE_FILE ) ); ?>" width="32" height="32" alt="">WPHouse <span class="wphouse-version"><?php echo esc_html( WPHOUSE_VERSION ); ?></span></h1>
				<nav class="wphouse-tabs" aria-label="WPHouse">
					<?php foreach ( self::VIEWS as $key ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', $key, Plugin::settings_url() ) ); ?>" data-view="<?php echo esc_attr( $key ); ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $labels[ $key ] ); ?></a>
					<?php endforeach; ?>
				</nav>
				<span class="wphouse-count">
					<?php
					/* translators: 1: number of modules running, 2: number of modules. */
					echo esc_html( sprintf( __( '%1$d of %2$d running', 'wphouse' ), $running, count( $modules ) ) );
					?>
				</span>
				<a class="wphouse-maker" href="https://designhouse.me/" target="_blank" rel="noopener"><img src="<?php echo esc_url( plugins_url( 'assets/designhouse.svg', WPHOUSE_FILE ) ); ?>" width="128" height="16" alt="Design House"></a>
			</div>
			<hr class="wp-header-end">

			<?php if ( $locked ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Settings are locked by WPHOUSE_LOCK_SETTINGS in wp-config.php. Change them in code.', 'wphouse' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Compat::ignore_overlaps() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WPHOUSE_IGNORE_OVERLAPS is on: features run even where another plugin already provides them.', 'wphouse' ); ?></p></div>
			<?php endif; ?>

			<div class="wphouse-layout">
				<div class="wphouse-main">
					<form method="post" action="options.php" class="wphouse-form">
						<?php settings_fields( 'wphouse' ); ?>

						<div class="wphouse-view" id="wphouse-view-modules"<?php echo 'modules' === $view ? '' : ' hidden'; ?>>
							<p class="wphouse-intro"><?php esc_html_e( 'Small, audited replacements for single-purpose plugins. Every module is a switch. Features that Wordfence or wp-config already handle are skipped automatically.', 'wphouse' ); ?></p>
							<div class="wphouse-modules">
								<?php
								foreach ( $modules as $id => $module ) {
									$this->render_module( $id, $module, $locked );
								}
								?>
							</div>
						</div>

						<div class="wphouse-view" id="wphouse-view-integrations"<?php echo 'integrations' === $view ? '' : ' hidden'; ?>>
							<?php $this->render_integrations(); ?>
						</div>

						<div class="wphouse-view" id="wphouse-view-general"<?php echo 'general' === $view ? '' : ' hidden'; ?>>
							<section class="wphouse-card" id="wphouse-general">
								<header class="wphouse-card__head"><h2><?php esc_html_e( 'General', 'wphouse' ); ?></h2></header>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="wphouse-general-alert_emails"><?php esc_html_e( 'Alert recipients', 'wphouse' ); ?></label></th>
										<td>
											<input type="text" class="regular-text" id="wphouse-general-alert_emails" name="<?php echo esc_attr( Settings::OPTION ); ?>[general][alert_emails]" value="<?php echo esc_attr( (string) $this->plugin->settings->value( 'general', 'alert_emails', '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" <?php disabled( $locked ); ?>>
											<p class="description"><?php esc_html_e( 'Comma-separated. Empty means the site admin address.', 'wphouse' ); ?></p>
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

					<form id="wphouse-task-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="wphouse_task">
						<?php wp_nonce_field( 'wphouse_task' ); ?>
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
			$status = [ 'muted', __( 'Unavailable', 'wphouse' ) ];
		} elseif ( $running ) {
			$status = [ 'on', __( 'Running', 'wphouse' ) ];
		} elseif ( $enabled ) {
			$status = [ 'warn', __( 'Enabled, not running', 'wphouse' ) ];
		} else {
			$status = [ 'off', __( 'Off', 'wphouse' ) ];
		}
		$name = Settings::OPTION . '[modules][' . $id . ']';
		$body = 'wphouse-' . $id . '-settings';
		?>
		<section class="wphouse-module<?php echo $enabled ? '' : ' is-off'; ?>" id="wphouse-<?php echo esc_attr( $id ); ?>">
			<div class="wphouse-module__head">
				<h2 class="wphouse-module__title"><label class="wphouse-switch">
					<input type="checkbox" role="switch" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $enabled ); ?> <?php disabled( $locked || null !== $forced ); ?>>
					<span class="wphouse-module__name"><?php echo esc_html( $module->label() ); ?></span>
				</label></h2>
				<span class="wphouse-status wphouse-status--<?php echo esc_attr( $status[0] ); ?>"><?php echo esc_html( $status[1] ); ?></span>
				<button type="button" class="button-link wphouse-module__more" aria-expanded="true" aria-controls="<?php echo esc_attr( $body ); ?>" hidden><?php esc_html_e( 'Details', 'wphouse' ); ?><span class="screen-reader-text"> <?php echo esc_html( $module->label() ); ?></span></button>
			</div>
			<div class="wphouse-module__body" id="<?php echo esc_attr( $body ); ?>">
				<p class="wphouse-module__desc"><?php echo esc_html( $module->description() ); ?></p>
				<?php if ( null !== $forced ) : ?>
					<p class="wphouse-note"><?php esc_html_e( 'Pinned by WPHOUSE_MODULES in wp-config.php.', 'wphouse' ); ?></p>
				<?php endif; ?>
				<?php if ( ! $available ) : ?>
					<p class="wphouse-note"><?php echo esc_html( $module->unavailable_reason() ); ?></p>
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
						<p class="wphouse-tasks">
							<?php foreach ( $module->tasks() as $task => $label ) : ?>
								<button type="submit" class="button" form="wphouse-task-form" name="task" value="<?php echo esc_attr( $id . ':' . $task ); ?>"><?php echo esc_html( $label ); ?></button>
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
		$dom_id    = 'wphouse-' . $id . '-' . $key;
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
					echo '<span class="wphouse-covered">' . esc_html( sprintf( __( 'Handled by %s, skipped here.', 'wphouse' ), (string) $field['covered_by'] ) ) . '</span>';
				}
				if ( ! empty( $field['help'] ) ) {
					echo '<p class="description">' . esc_html( (string) $field['help'] ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	/** What WPHouse does alongside the plugins it is built for, and which of them this site has. */
	private function render_integrations(): void {
		$found = Integrations::detected();
		$rows  = [
			'wordfence'   => [ 'Wordfence', __( 'WPHouse skips what Wordfence already does (for example username discovery and login error masking) and leaves the firewall, two-factor login and malware scans to it. Vulnerability alerts stand down, because Wordfence warns about vulnerable plugins itself.', 'wphouse' ) ],
			'woocommerce' => [ 'WooCommerce', __( 'Compatible with HPOS and the block checkout. Generic login errors also cover the My Account form, maintenance mode lets the Store API and payment callbacks through, and product reviews survive "disable comments".', 'wphouse' ) ],
			'payments'    => [ __( 'Payment gateways', 'wphouse' ), __( 'Autopay, Przelewy24, PayU, imoje, Paynow, Stripe, PayPal and WooPayments. Their callbacks (?wc-api= and the REST API) pass maintenance mode, and XML-RPC stays on for WooPayments.', 'wphouse' ) ],
			'redis'       => [ __( 'Redis object cache', 'wphouse' ), __( 'WPHouse has its own Redis object cache: install it with "wp wphouse object-cache enable". Every cached value is signed, so other sites on a shared Redis cannot plant data. A site that uses the Redis Object Cache plugin instead shows its status here.', 'wphouse' ) ],
			'elementor'   => [ 'Elementor', __( 'Elementor and Elementor Pro. Watched for new vulnerabilities like everything on this list.', 'wphouse' ) ],
		];
		?>
		<section class="wphouse-card" id="wphouse-integrations">
			<header class="wphouse-card__head"><h2><?php esc_html_e( 'Integrations', 'wphouse' ); ?></h2></header>
			<p class="wphouse-card__desc"><?php esc_html_e( 'WPHouse is built to run alongside these plugins. Design House watches all of them for new vulnerabilities (CVE) and tells you when an update cannot wait.', 'wphouse' ); ?></p>
			<table class="widefat striped wphouse-integrations">
				<thead><tr>
					<th><?php esc_html_e( 'Integration', 'wphouse' ); ?></th>
					<th><?php esc_html_e( 'On this site', 'wphouse' ); ?></th>
					<th><?php esc_html_e( 'What WPHouse does', 'wphouse' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $group => [ $label, $text ] ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td>
							<?php
							$items = $found[ $group ] ?? [];
							if ( 'redis' === $group ) {
								$this->render_object_cache_status( $items );
							} elseif ( ! $items ) {
								echo '<span class="wphouse-badge">' . esc_html__( 'not installed', 'wphouse' ) . '</span>';
								if ( 'wordfence' === $group ) {
									echo '<p class="description">' . esc_html(
										$this->plugin->is_running( 'vulnerabilities' )
											? __( 'WPHouse vulnerability alerts cover this site. For a firewall and two-factor login, install Wordfence.', 'wphouse' )
											: __( 'Nothing warns about vulnerable plugins on this site: switch on WPHouse vulnerability alerts below, or install Wordfence.', 'wphouse' )
									) . '</p>';
								}
							}
							if ( 'redis' !== $group ) {
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
		echo '<div><span class="wphouse-status wphouse-status--' . ( $item['active'] ? 'on' : 'off' ) . '">' . esc_html( $item['active'] ? __( 'active', 'wphouse' ) : __( 'inactive', 'wphouse' ) ) . '</span> ' . esc_html( $item['name'] ) . '</div>';
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
			echo '<div><span class="wphouse-status wphouse-status--' . ( $up ? 'on' : 'warn' ) . '">' . esc_html( $up ? __( 'connected', 'wphouse' ) : __( 'Redis not reachable', 'wphouse' ) ) . '</span> ' . esc_html__( 'WPHouse object cache', 'wphouse' ) . '</div>';
			return;
		}
		foreach ( $items as $item ) {
			$this->render_plugin_status( $item );
		}
		$other = Integrations::object_cache();
		if ( 'redis' === $other['type'] ) {
			$note = $other['connected'] ? __( 'Object cache connected to Redis.', 'wphouse' ) : __( 'Object cache drop-in installed, but Redis is not reachable.', 'wphouse' );
		} elseif ( 'other' === $other['type'] ) {
			$note = __( 'Another persistent object cache is in use.', 'wphouse' );
		} elseif ( ! $items ) {
			echo '<span class="wphouse-badge">' . esc_html__( 'not installed', 'wphouse' ) . '</span>';
			return;
		} else {
			return;
		}
		echo '<p class="description">' . esc_html( $note ) . '</p>';
	}

	private function render_log(): void {
		$rows = Log::recent( 50 );
		?>
		<aside class="wphouse-log" id="wphouse-log" aria-labelledby="wphouse-log-title">
			<h2 id="wphouse-log-title">
				<?php esc_html_e( 'Activity log', 'wphouse' ); ?>
				<?php if ( $rows ) : ?>
					<span class="wphouse-log__count"><?php echo esc_html( count( $rows ) < 50 ? (string) count( $rows ) : '50+' ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'No events yet.', 'wphouse' ); ?></p>
			<?php else : ?>
				<ol class="wphouse-log__list">
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$user = (int) $row->user_id ? get_userdata( (int) $row->user_id ) : false;
						$time = strtotime( $row->created_at . ' UTC' );
						?>
						<li class="wphouse-log__item wphouse-log__item--<?php echo esc_attr( $row->severity ); ?>">
							<span class="wphouse-log__msg"><?php echo esc_html( $row->message ); ?></span>
							<span class="wphouse-log__meta">
								<code class="wphouse-log__event"><?php echo esc_html( $row->event ); ?></code>
								<?php if ( (int) $row->user_id ) : ?>
									<span><?php echo esc_html( $user ? $user->user_login : '#' . $row->user_id ); ?></span>
								<?php endif; ?>
								<time datetime="<?php echo esc_attr( $time ? gmdate( 'c', $time ) : '' ); ?>" title="<?php echo esc_attr( $row->created_at . ' UTC' ); ?>">
									<?php
									/* translators: %s: time since the event, e.g. "5 mins". */
									echo esc_html( $time ? sprintf( __( '%s ago', 'wphouse' ), human_time_diff( $time ) ) : $row->created_at );
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
