<?php
/**
 * Plugin health report: closed on wordpress.org, abandoned, not from wordpress.org, one-time
 * tools left active, inactive leftovers, and plugins a WPHouse module replaces.
 *
 * WordPress shows closed plugins as "up to date", which is how abandoned and bought-and-
 * backdoored plugins stay installed for years. One bulk request to api.wordpress.org per check
 * (weekly, or when the plugin list changes). Known vulnerabilities are the vulnerability alerts
 * module's job (or Wordfence's, when it is active).
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use WP_CLI;
use WPHouse\Core\AbstractModule;
use WPHouse\Core\Updater;
use WPHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class PluginHealth extends AbstractModule {

	private const OPTION      = 'wphouse_plugin_health';
	private const API         = 'https://api.wordpress.org/plugins/info/1.2/';
	private const MAX_AGE     = WEEK_IN_SECONDS;
	private const STALE_AFTER = 2 * YEAR_IN_SECONDS;

	/** Tools for migrations, search-replace, imports, file access and debugging. */
	private const ONE_TIME_TOOLS = [
		'all-in-one-wp-migration',
		'duplicator',
		'duplicator-pro',
		'migrate-guru',
		'wp-migrate-db',
		'wp-migrate-db-pro',
		'better-search-replace',
		'search-and-replace',
		'velvet-blues-update-urls',
		'go-live-update-urls',
		'wordpress-importer',
		'wp-file-manager',
		'file-manager-advanced',
		'filester',
		'wp-reset',
		'wp-dbmanager',
		'string-locator',
		'loco-translate',
		'query-monitor',
		'debug-bar',
	];

	/** Plugin slug => WPHouse module that covers its job. Only modules that exist are reported. */
	private const REPLACEABLE = [
		'insert-headers-and-footers'   => 'scripts',
		'header-and-footer-scripts'    => 'scripts',
		'header-footer-code-manager'   => 'scripts',
		'head-footer-code'             => 'scripts',
		'wp-headers-and-footers'       => 'scripts',
		'tracking-code-manager'        => 'scripts',
		'hotjar'                       => 'scripts',
		'microsoft-clarity'            => 'scripts',
		'duplicate-page'               => 'duplicate',
		'duplicate-post'               => 'duplicate',
		'post-duplicator'              => 'duplicate',
		'disable-comments'             => 'tweaks',
		'disable-search'               => 'tweaks',
		'disable-emojis'               => 'tweaks',
		'disable-embeds'               => 'tweaks',
		'heartbeat-control'            => 'tweaks',
		'wp-mail-smtp'                 => 'smtp',
		'post-smtp'                    => 'smtp',
		'fluent-smtp'                  => 'smtp',
		'easy-wp-smtp'                 => 'smtp',
		'smtp-mailer'                  => 'smtp',
		'wp-smtp'                      => 'smtp',
		'wp-maintenance-mode'          => 'maintenance',
		'coming-soon'                  => 'maintenance',
		'maintenance'                  => 'maintenance',
		'under-construction-page'      => 'maintenance',
		'disable-xml-rpc'              => 'hardening',
		'disable-xml-rpc-api'          => 'hardening',
		'stop-user-enumeration'        => 'hardening',
		'simple-cloudflare-turnstile'  => 'bots',
		'recaptcha-woo'                => 'bots',
		'advanced-nocaptcha-recaptcha' => 'bots',
		'google-captcha'               => 'bots',
		'honeypot'                     => 'bots',
	];

	public function id(): string {
		return 'plugin_health';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [];
	}

	public function label(): string {
		return __( 'Plugin health', 'wphouse' );
	}

	public function description(): string {
		return __( 'Weekly check of installed plugins: closed on WordPress.org (often for security reasons), no update for two years, not from WordPress.org, one-time tools left active, inactive leftovers, and plugins a WPHouse module replaces. Results also appear in Tools → Site Health.', 'wphouse' );
	}

	public function fields(): array {
		return [];
	}

	public function boot(): void {
		add_action( 'wphouse_daily', [ $this, 'maybe_refresh' ] );
		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wphouse plugin-health', [ $this, 'cli' ] );
		}
	}

	public function maybe_refresh(): void {
		$report = $this->report();
		if ( ( $report['fingerprint'] ?? '' ) !== $this->fingerprint() || time() - (int) ( $report['checked_at'] ?? 0 ) > self::MAX_AGE ) {
			$this->refresh();
		}
	}

	/**
	 * Query wordpress.org and store findings per plugin.
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(): array {
		$plugins  = $this->installed();
		$external = [];
		$slugs    = [];
		foreach ( $plugins as $file => $data ) {
			if ( $this->has_own_updater( $data ) ) {
				$external[ $file ] = true;
			} else {
				$slugs[ $file ] = self::slug( $file );
			}
		}

		$info  = [];
		$error = '';
		foreach ( array_chunk( array_unique( array_values( $slugs ) ), 50 ) as $chunk ) {
			$result = $this->fetch_info( $chunk );
			if ( is_string( $result ) ) {
				$error = $result;
				break;
			}
			$info += $result;
		}

		$previous = $this->report();
		if ( '' !== $error && ! empty( $previous['items'] ) ) {
			$previous['error'] = $error;
			update_option( self::OPTION, $previous, false );
			return $previous;
		}

		$items = [];
		foreach ( $plugins as $file => $data ) {
			$slug     = self::slug( $file );
			$findings = [];
			if ( isset( $external[ $file ] ) ) {
				$findings[] = [ 'external' ];
			} elseif ( isset( $info[ $slug ] ) ) {
				$entry = $info[ $slug ];
				if ( ! empty( $entry['closed'] ) || 'closed' === ( $entry['error'] ?? '' ) ) {
					$findings[] = [ 'closed', (string) ( $entry['closed_date'] ?? '' ), (string) ( $entry['reason'] ?? '' ) ];
				} elseif ( isset( $entry['error'] ) ) {
					$findings[] = [ 'not_on_wporg' ];
				} elseif ( ! empty( $entry['last_updated'] ) ) {
					$updated = strtotime( (string) $entry['last_updated'] );
					if ( $updated && time() - $updated > self::STALE_AFTER ) {
						$findings[] = [ 'stale', gmdate( 'Y-m-d', $updated ) ];
					}
				}
			}
			if ( in_array( $slug, self::ONE_TIME_TOOLS, true ) ) {
				$findings[] = [ 'one_time_tool' ];
			}
			if ( isset( self::REPLACEABLE[ $slug ] ) ) {
				$findings[] = [ 'replaceable', self::REPLACEABLE[ $slug ] ];
			}
			$items[ $file ] = [
				'name'     => (string) $data['Name'],
				'version'  => (string) $data['Version'],
				'findings' => $findings,
			];
		}

		$report = [
			'checked_at'  => time(),
			'fingerprint' => $this->fingerprint(),
			'error'       => $error,
			'items'       => $items,
		];
		update_option( self::OPTION, $report, false );
		return $report;
	}

	/**
	 * Findings for display, with live active/inactive state. Severity: critical|warning|info.
	 *
	 * @return array<string, array{name: string, active: bool, findings: array<int, array{severity: string, text: string}>}>
	 */
	public function findings(): array {
		$report  = $this->report();
		$active  = array_flip( (array) get_option( 'active_plugins', [] ) );
		$modules = Plugin::instance()->modules();
		$out     = [];
		foreach ( (array) ( $report['items'] ?? [] ) as $file => $item ) {
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue; // Removed since the last check.
			}
			$is_active = isset( $active[ $file ] );
			$list      = [];
			foreach ( (array) $item['findings'] as $finding ) {
				$text = $this->describe( $finding, $modules, $is_active );
				if ( null !== $text ) {
					$list[] = $text;
				}
			}
			if ( ! $is_active ) {
				$list[] = [
					'severity' => 'info',
					'text'     => __( 'Inactive. Inactive plugins can still be attacked through their files; delete it if you do not need it.', 'wphouse' ),
				];
			}
			if ( $list ) {
				$out[ (string) $file ] = [
					'name'     => (string) $item['name'],
					'active'   => $is_active,
					'findings' => $list,
				];
			}
		}
		uasort( $out, static fn( $a, $b ) => self::rank( $b ) <=> self::rank( $a ) );
		return $out;
	}

	/**
	 * @param array<int, mixed>             $finding   Stored finding: code and parameters.
	 * @param array<string, AbstractModule> $modules   Registered modules.
	 * @param bool                          $is_active Whether the plugin is active.
	 * @return array{severity: string, text: string}|null
	 */
	private function describe( array $finding, array $modules, bool $is_active ): ?array {
		switch ( $finding[0] ) {
			case 'closed':
				return [
					'severity' => 'critical',
					/* translators: 1: date, 2: reason code from WordPress.org, e.g. security-issue. */
					'text'     => sprintf( __( 'Closed on WordPress.org (%1$s, reason: %2$s). It gets no more updates; replace or remove it.', 'wphouse' ), ( '' !== $finding[1] ? $finding[1] : '?' ), ( '' !== $finding[2] ? $finding[2] : '?' ) ),
				];
			case 'stale':
				return [
					'severity' => 'warning',
					/* translators: %s: date of the last update. */
					'text'     => sprintf( __( 'No update since %s. Probably abandoned.', 'wphouse' ), $finding[1] ),
				];
			case 'not_on_wporg':
				return [
					'severity' => 'info',
					'text'     => __( 'Not on WordPress.org and no update source declared. Make sure you know where its updates come from.', 'wphouse' ),
				];
			case 'external':
				return [
					'severity' => 'info',
					'text'     => __( 'Updates come from its vendor, not WordPress.org. Keep the licence active so security fixes arrive.', 'wphouse' ),
				];
			case 'one_time_tool':
				return $is_active ? [
					'severity' => 'warning',
					'text'     => __( 'One-time tool (migration, search-replace, import, file access or debugging) left active. Delete it when the job is done.', 'wphouse' ),
				] : null;
			case 'replaceable':
				$module = $modules[ (string) $finding[1] ] ?? null;
				return $module ? [
					'severity' => 'info',
					/* translators: %s: WPHouse module name. */
					'text'     => sprintf( __( 'WPHouse can do this: module "%s". Switch it on, then remove this plugin.', 'wphouse' ), $module->label() ),
				] : null;
		}
		return null;
	}

	/**
	 * @param array{findings: array<int, array{severity: string, text: string}>} $item Display item.
	 */
	private static function rank( array $item ): int {
		$weights = [
			'critical' => 100,
			'warning'  => 10,
			'info'     => 1,
		];
		return array_sum( array_map( static fn( $f ) => $weights[ $f['severity'] ] ?? 0, $item['findings'] ) );
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['wphouse_plugin_health'] = [
			'label' => __( 'WPHouse plugin health', 'wphouse' ),
			'test'  => [ $this, 'site_health_result' ],
		];
		return $tests;
	}

	/** @return array<string, mixed> */
	public function site_health_result(): array {
		$counts = [
			'critical' => 0,
			'warning'  => 0,
		];
		foreach ( $this->findings() as $item ) {
			foreach ( $item['findings'] as $finding ) {
				if ( isset( $counts[ $finding['severity'] ] ) ) {
					++$counts[ $finding['severity'] ];
				}
			}
		}
		$status = $counts['critical'] ? 'critical' : ( $counts['warning'] ? 'recommended' : 'good' );
		$label  = 'good' === $status
			? __( 'No closed, abandoned or forgotten plugins found', 'wphouse' )
			: __( 'Some plugins need attention', 'wphouse' );
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Security', 'wphouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of critical findings, 2: number of warnings. */
					__( '%1$d critical, %2$d warnings. Details are on the WPHouse settings page.', 'wphouse' ),
					$counts['critical'],
					$counts['warning']
				)
			) . '</p>',
			'actions'     => '<a href="' . esc_url( Plugin::settings_url( 'plugin_health' ) ) . '">' . esc_html__( 'Open WPHouse', 'wphouse' ) . '</a>',
			'test'        => 'wphouse_plugin_health',
		];
	}

	public function tasks(): array {
		return [ 'refresh' => __( 'Check now', 'wphouse' ) ];
	}

	public function handle_task( string $task ): string {
		$report = $this->refresh();
		return '' !== ( $report['error'] ?? '' )
			/* translators: %s: error message. */
			? sprintf( __( 'Could not reach WordPress.org: %s', 'wphouse' ), $report['error'] )
			: __( 'Plugin health updated.', 'wphouse' );
	}

	public function render_panel(): void {
		$report = $this->report();
		echo '<div class="wphouse-panel">';
		if ( empty( $report['checked_at'] ) ) {
			echo '<p>' . esc_html__( 'Not checked yet. Use "Check now" or wait for the daily run.', 'wphouse' ) . '</p></div>';
			return;
		}
		/* translators: %s: date and time. */
		echo '<p>' . esc_html( sprintf( __( 'Last check: %s.', 'wphouse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['checked_at'] ) ) );
		if ( ! empty( $report['error'] ) ) {
			echo ' ' . esc_html( sprintf( /* translators: %s: error message. */ __( 'The last refresh failed: %s', 'wphouse' ), (string) $report['error'] ) );
		}
		echo '</p>';
		$findings = $this->findings();
		if ( ! $findings ) {
			echo '<p>' . esc_html__( 'Nothing to report.', 'wphouse' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Plugin', 'wphouse' ) . '</th><th>' . esc_html__( 'Findings', 'wphouse' ) . '</th></tr></thead><tbody>';
		foreach ( $findings as $item ) {
			echo '<tr><td><strong>' . esc_html( $item['name'] ) . '</strong>' . ( $item['active'] ? '' : ' <span class="wphouse-badge">' . esc_html__( 'inactive', 'wphouse' ) . '</span>' ) . '</td><td>';
			foreach ( $item['findings'] as $finding ) {
				echo '<div><span class="wphouse-badge wphouse-badge--' . esc_attr( $finding['severity'] ) . '">' . esc_html( $finding['severity'] ) . '</span> ' . esc_html( $finding['text'] ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Show the plugin health report.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Query WordPress.org first.
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]                   $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function cli( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['refresh'] ) || empty( $this->report()['checked_at'] ) ) {
			$report = $this->refresh();
			if ( '' !== ( $report['error'] ?? '' ) ) {
				WP_CLI::warning( 'WordPress.org: ' . $report['error'] );
			}
		}
		$rows = [];
		foreach ( $this->findings() as $file => $item ) {
			foreach ( $item['findings'] as $finding ) {
				$rows[] = [
					'plugin'   => $file,
					'severity' => $finding['severity'],
					'finding'  => $finding['text'],
				];
			}
		}
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'plugin', 'severity', 'finding' ] );
	}

	/**
	 * @param string[] $slugs Slugs to look up.
	 * @return array<string, array<string, mixed>>|string Info per slug, or an error message.
	 */
	private function fetch_info( array $slugs ): array|string {
		$url      = add_query_arg(
			[
				'action'  => 'plugin_information',
				'request' => [
					'slugs'  => implode( ',', $slugs ),
					'fields' => array_fill_keys( [ 'sections', 'description', 'reviews', 'versions', 'screenshots', 'banners', 'icons', 'contributors', 'tags', 'donate_link', 'ratings', 'compatibility' ], 0 ),
				],
			],
			self::API
		);
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 15,
				'limit_response_size' => MB_IN_BYTES,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 'HTTP ' . wp_remote_retrieve_response_code( $response );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : 'invalid response';
	}

	/** @return array<string, array<string, mixed>> */
	private function installed(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		unset( $plugins[ Updater::basename() ] );
		return $plugins;
	}

	/**
	 * A plugin with an Update URI that is not wordpress.org has its own update channel.
	 *
	 * @param array<string, mixed> $data Plugin headers.
	 */
	private function has_own_updater( array $data ): bool {
		$uri = (string) ( $data['UpdateURI'] ?? '' );
		if ( '' === $uri ) {
			return false;
		}
		$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
		return ! in_array( $host, [ 'w.org', 'wordpress.org' ], true );
	}

	private function fingerprint(): string {
		$parts = [];
		foreach ( $this->installed() as $file => $data ) {
			$parts[] = $file . '@' . $data['Version'];
		}
		sort( $parts );
		return md5( implode( '|', $parts ) );
	}

	/** @return array<string, mixed> */
	private function report(): array {
		$report = get_option( self::OPTION, [] );
		return is_array( $report ) ? $report : [];
	}

	/** WordPress.org slug as core's update check knows it (hello.php is "hello-dolly"), else the folder name. */
	public static function slug( string $file ): string {
		static $known = null;
		if ( null === $known ) {
			$known   = [];
			$updates = get_site_transient( 'update_plugins' );
			foreach ( [ 'response', 'no_update' ] as $list ) {
				foreach ( (array) ( is_object( $updates ) ? ( $updates->$list ?? [] ) : [] ) as $plugin_file => $item ) {
					if ( is_object( $item ) && ! empty( $item->slug ) ) {
						$known[ (string) $plugin_file ] = (string) $item->slug;
					}
				}
			}
		}
		return $known[ $file ] ?? ( str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ) );
	}
}
