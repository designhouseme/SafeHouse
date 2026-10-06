<?php
/**
 * Vulnerability alerts for sites without Wordfence: the installed WordPress, plugin and theme
 * versions are matched against signed advisory data from the WPHouse update host, built from
 * Wordfence Intelligence by dev/cve-watch.php. Wordfence does this itself, so the module stands
 * down while Wordfence is active.
 *
 * Downloads happen only in cron (hourly hook, at most every 6 hours): index.json and its Ed25519
 * signature, checked with a data-only key that the updater does not trust, then only the shards
 * for what is installed, each checked against the SHA-256 in the signed index. Matching runs on
 * every view from the stored data, so updating a plugin clears its warning at once.
 *
 * Constants, honoured only on local/development sites: WPHOUSE_ADVISORY_URL, WPHOUSE_ADVISORY_PUBLIC_KEYS.
 *
 * @package WPHouse
 */

namespace WPHouse\Modules;

use WP_CLI;
use WP_Error;
use WPHouse\Core\AbstractModule;
use WPHouse\Core\Compat;
use WPHouse\Core\Log;
use WPHouse\Core\Notify;
use WPHouse\Core\Signature;
use WPHouse\Core\Updater;
use WPHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Vulnerabilities extends AbstractModule {

	private const OPTION    = 'wphouse_vulnerabilities';
	private const FINDINGS  = 'wphouse_vulnerabilities_findings';
	private const INDEX_URL = 'https://updates.designhouse.me/wphouse/advisories/index.json';

	/** Base64 Ed25519 keys for advisory data only. The updater never trusts these. */
	private const PUBLIC_KEYS = [
		'a6vBsYaycKRtBA7v4cySBDY+SQnezuUPXgsjBriP64M=', // 2026-10-06, signs in CI.
	];

	private const MAX_AGE     = 6 * HOUR_IN_SECONDS;
	private const RETRY_AFTER = HOUR_IN_SECONDS;
	private const MAX_INDEX   = 256 * KB_IN_BYTES;
	private const MAX_SHARD   = 4 * MB_IN_BYTES;
	private const URGENT_CVSS = 7.0;
	private const ID_FORMAT   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	public function id(): string {
		return 'vulnerabilities';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function defaults(): array {
		return [];
	}

	public function label(): string {
		return __( 'Vulnerability alerts', 'wphouse' );
	}

	public function description(): string {
		return __( 'Warns when the installed WordPress, a plugin or a theme has a known security vulnerability, and names the version that fixes it. Meant for sites without Wordfence, which does this itself. Data from Wordfence Intelligence, signed and served by the WPHouse update host, checked every 6 hours.', 'wphouse' );
	}

	public function fields(): array {
		return [];
	}

	public function available(): bool {
		return ! Compat::wordfence_active() || Compat::ignore_overlaps();
	}

	public function unavailable_reason(): string {
		return __( 'Wordfence is active and already warns about vulnerable plugins, themes and WordPress versions.', 'wphouse' );
	}

	public function boot(): void {
		add_action( 'wphouse_hourly', [ $this, 'maybe_refresh' ] );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		foreach ( [ 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', '_core_updated_successfully' ] as $hook ) {
			add_action( $hook, [ $this, 'forget' ] );
		}
		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( Updater::is_local() ) {
			add_filter( 'http_request_host_is_external', [ $this, 'allow_local_host' ], 10, 2 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'wphouse vulnerabilities', [ $this, 'cli' ] );
		}
	}

	public function maybe_refresh(): void {
		$state = $this->state();
		$wait  = '' !== ( $state['error'] ?? '' ) ? self::RETRY_AFTER : self::MAX_AGE;
		if ( time() - (int) ( $state['checked_at'] ?? 0 ) > $wait || ( $state['fingerprint'] ?? '' ) !== $this->fingerprint() ) {
			$this->refresh();
			$this->notify_new();
			return;
		}
		$this->findings( true ); // Picks up versions changed outside the updater (FTP, git deploys).
	}

	/** Drop the cached findings; the next view recomputes them. */
	public function forget(): void {
		delete_option( self::FINDINGS );
	}

	/**
	 * Download the signed index and the shards for what is installed.
	 *
	 * @return array<string, mixed> Stored state.
	 */
	public function refresh(): array {
		$state                = $this->state();
		$state['checked_at']  = time();
		$state['fingerprint'] = $this->fingerprint();

		$index = $this->fetch_index();
		if ( is_wp_error( $index ) ) {
			$state['error'] = $index->get_error_message();
			update_option( self::OPTION, $state, false );
			return $state;
		}

		$wanted = [];
		foreach ( array_keys( $this->installed() ) as $item ) {
			$wanted[ substr( md5( $item ), 0, 2 ) ][] = $item;
		}
		$old    = (array) ( $state['shards'] ?? [] );
		$shards = [];
		foreach ( $wanted as $name => $items ) {
			$hash  = (string) ( $index['shards'][ $name ] ?? '' );
			$known = $old[ $name ] ?? null;
			if ( is_array( $known ) && $known['hash'] === $hash && ! array_diff( $items, $known['items'] ) ) {
				$shards[ $name ] = $known;
				continue;
			}
			$entries = $this->fetch_shard( $name, $hash, $items );
			if ( is_wp_error( $entries ) ) {
				$state['error'] = $entries->get_error_message();
				update_option( self::OPTION, $state, false );
				return $state;
			}
			$shards[ $name ] = [
				'hash'    => $hash,
				'items'   => $items,
				'entries' => $entries,
			];
		}

		$state['error']       = '';
		$state['generated']   = $index['generated'];
		$state['attribution'] = $index['attribution'];
		$state['shards']      = $shards;
		update_option( self::OPTION, $state, false );
		$this->forget();
		return $state;
	}

	/**
	 * Installed software with a known vulnerability, most urgent first. Cached, because the admin
	 * notice reads it on every screen and matching needs get_plugins().
	 *
	 * @return list<array{item: string, name: string, version: string, id: string, title: string, cvss: float|null, fixed: string[], urgent: bool}>
	 */
	public function findings( bool $fresh = false ): array {
		$cached = $fresh ? null : get_option( self::FINDINGS );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$findings = $this->match();
		update_option( self::FINDINGS, $findings, false );
		return $findings;
	}

	/**
	 * @return list<array{item: string, name: string, version: string, id: string, title: string, cvss: float|null, fixed: string[], urgent: bool}>
	 */
	private function match(): array {
		$entries = [];
		foreach ( (array) ( $this->state()['shards'] ?? [] ) as $shard ) {
			$entries += (array) ( $shard['entries'] ?? [] );
		}
		$out = [];
		foreach ( $this->installed() as $item => $software ) {
			foreach ( (array) ( $entries[ $item ] ?? [] ) as $vuln ) {
				if ( ! self::affected( $software['version'], $vuln[3] ) ) {
					continue;
				}
				$out[] = [
					'item'    => $item,
					'name'    => $software['name'],
					'version' => $software['version'],
					'id'      => $vuln[0],
					'title'   => $vuln[1],
					'cvss'    => $vuln[2],
					'fixed'   => $vuln[4],
					'urgent'  => ( null !== $vuln[2] && $vuln[2] >= self::URGENT_CVSS ) || ! $vuln[4],
				];
			}
		}
		usort( $out, static fn( $a, $b ) => [ $b['urgent'], $b['cvss'] ?? 0 ] <=> [ $a['urgent'], $a['cvss'] ?? 0 ] );
		return $out;
	}

	/**
	 * Same rule as Wordfence: "*" is an open bound, each bound inclusive or not.
	 *
	 * @param array<int, array{0: string, 1: bool, 2: string, 3: bool}> $ranges Affected ranges.
	 */
	public static function affected( string $version, array $ranges ): bool {
		foreach ( $ranges as [ $from, $from_inclusive, $to, $to_inclusive ] ) {
			$above = '*' === $from || version_compare( $version, $from, $from_inclusive ? '>=' : '>' );
			$below = '*' === $to || version_compare( $version, $to, $to_inclusive ? '<=' : '<' );
			if ( $above && $below ) {
				return true;
			}
		}
		return false;
	}

	/** One e-mail for findings that were not reported before. A finding that comes back is reported again. */
	public function notify_new(): void {
		$findings = $this->findings( true );
		$current  = [];
		foreach ( $findings as $finding ) {
			$current[ $finding['item'] . ':' . $finding['id'] ] = $finding;
		}
		$notified = (array) get_option( self::OPTION . '_notified', [] );
		$new      = array_diff_key( $current, array_flip( $notified ) );
		update_option( self::OPTION . '_notified', array_keys( $current ), false );
		if ( ! $new ) {
			return;
		}
		$lines = [];
		foreach ( $new as $finding ) {
			$lines[] = '- ' . $this->sentence( $finding );
		}
		$lines[] = '';
		$lines[] = (string) ( $this->state()['attribution'] ?? '' );
		Log::add( 'vulnerability_found', sprintf( '%d known vulnerabilities in installed software', count( $new ) ), [ 'items' => array_keys( $new ) ], 'critical' );
		/* translators: %d: number of vulnerabilities. */
		Notify::send( sprintf( _n( '%d known vulnerability in installed software', '%d known vulnerabilities in installed software', count( $new ), 'wphouse' ), count( $new ) ), $lines );
	}

	public function admin_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_core' ) ) {
			return;
		}
		$urgent = array_filter( $this->findings(), static fn( $f ) => $f['urgent'] );
		if ( ! $urgent ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Security vulnerability in installed software', 'wphouse' ) . '</strong></p><ul>';
		foreach ( array_slice( $urgent, 0, 5 ) as $finding ) {
			echo '<li>' . esc_html( $this->sentence( $finding ) ) . $this->details_link( $finding ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- details_link() escapes.
		}
		echo '</ul><p><a class="button button-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to updates', 'wphouse' ) . '</a> ';
		echo '<a href="' . esc_url( Plugin::settings_url( 'vulnerabilities' ) ) . '">' . esc_html__( 'All findings', 'wphouse' ) . '</a></p></div>';
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['wphouse_vulnerabilities'] = [
			'label' => __( 'WPHouse vulnerability alerts', 'wphouse' ),
			'test'  => [ $this, 'site_health_result' ],
		];
		return $tests;
	}

	/** @return array<string, mixed> */
	public function site_health_result(): array {
		$state    = $this->state();
		$findings = $this->findings();
		$urgent   = count( array_filter( $findings, static fn( $f ) => $f['urgent'] ) );
		if ( $findings ) {
			$status = $urgent ? 'critical' : 'recommended';
			$label  = __( 'Installed software has known vulnerabilities', 'wphouse' );
			$items  = '<ul>' . implode( '', array_map( fn( $f ) => '<li>' . esc_html( $this->sentence( $f ) ) . '</li>', $findings ) ) . '</ul>';
		} elseif ( empty( $state['generated'] ) ) {
			$status = 'recommended';
			$label  = __( 'Vulnerability data has not been downloaded yet', 'wphouse' );
			$items  = '' !== ( $state['error'] ?? '' ) ? '<p>' . esc_html( (string) $state['error'] ) . '</p>' : '';
		} else {
			$status = 'good';
			$label  = __( 'No known vulnerabilities in installed software', 'wphouse' );
			$items  = '';
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Security', 'wphouse' ),
				'color' => 'blue',
			],
			'description' => $items . '<p>' . esc_html( (string) ( $state['attribution'] ?? '' ) ) . '</p>',
			'actions'     => '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to updates', 'wphouse' ) . '</a>',
			'test'        => 'wphouse_vulnerabilities',
		];
	}

	public function tasks(): array {
		return [ 'refresh' => __( 'Check now', 'wphouse' ) ];
	}

	public function handle_task( string $task ): string {
		$state = $this->refresh();
		$this->notify_new();
		return '' !== ( $state['error'] ?? '' )
			/* translators: %s: error message. */
			? sprintf( __( 'Could not download vulnerability data: %s', 'wphouse' ), (string) $state['error'] )
			: __( 'Vulnerability data updated.', 'wphouse' );
	}

	public function render_panel(): void {
		$state = $this->state();
		echo '<div class="wphouse-panel"><p>';
		if ( empty( $state['checked_at'] ) ) {
			echo esc_html__( 'Not checked yet. Use "Check now" or wait for the next hourly run.', 'wphouse' );
		} else {
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Last check: %s.', 'wphouse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['checked_at'] ) ) );
			if ( '' !== ( $state['error'] ?? '' ) ) {
				/* translators: %s: error message. */
				echo ' ' . esc_html( sprintf( __( 'The last download failed: %s', 'wphouse' ), (string) $state['error'] ) );
			}
		}
		echo '</p>';
		$findings = $this->findings( true );
		if ( ! empty( $state['generated'] ) && ! $findings ) {
			echo '<p>' . esc_html__( 'No known vulnerabilities in installed software.', 'wphouse' ) . '</p>';
		}
		if ( $findings ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Software', 'wphouse' ) . '</th><th>' . esc_html__( 'Vulnerability', 'wphouse' ) . '</th><th>' . esc_html__( 'Fixed in', 'wphouse' ) . '</th></tr></thead><tbody>';
			foreach ( $findings as $finding ) {
				$severity = $finding['urgent'] ? 'critical' : 'warning';
				echo '<tr><td><strong>' . esc_html( $finding['name'] ) . '</strong> ' . esc_html( $finding['version'] ) . '</td>';
				echo '<td><span class="wphouse-badge wphouse-badge--' . esc_attr( $severity ) . '">' . esc_html( null !== $finding['cvss'] ? 'CVSS ' . number_format_i18n( $finding['cvss'], 1 ) : $severity ) . '</span> ' . esc_html( $finding['title'] ) . $this->details_link( $finding ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- details_link() escapes.
				echo '<td>' . esc_html( $finding['fixed'] ? implode( ', ', $finding['fixed'] ) : __( 'no fix yet', 'wphouse' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $state['attribution'] ) ) {
			echo '<p class="description">' . esc_html( (string) $state['attribution'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Show known vulnerabilities in installed software.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Download the advisory data first.
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
		if ( ! empty( $assoc_args['refresh'] ) || empty( $this->state()['checked_at'] ) ) {
			$state = $this->refresh();
			$this->notify_new();
			if ( '' !== ( $state['error'] ?? '' ) ) {
				WP_CLI::warning( (string) $state['error'] );
			}
		}
		$rows = array_map(
			static fn( $f ) => [
				'software' => $f['item'],
				'version'  => $f['version'],
				'severity' => $f['urgent'] ? 'critical' : 'warning',
				'cvss'     => null !== $f['cvss'] ? number_format( $f['cvss'], 1 ) : '',
				'fixed'    => implode( ', ', $f['fixed'] ),
				'title'    => $f['title'],
			],
			$this->findings()
		);
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, [ 'software', 'version', 'severity', 'cvss', 'fixed', 'title' ] );
	}

	/** Lets wp_safe_remote_get() reach a private-network advisory host, on local/development sites only. */
	public function allow_local_host( bool $external, string $host ): bool {
		return wp_parse_url( $this->index_url(), PHP_URL_HOST ) === $host ? true : $external;
	}

	/**
	 * @param array{name: string, version: string, title: string, cvss: float|null, fixed: string[]} $finding Finding.
	 */
	private function sentence( array $finding ): string {
		$cvss = null !== $finding['cvss'] ? ' (CVSS ' . number_format_i18n( $finding['cvss'], 1 ) . ')' : '';
		return $finding['fixed']
			/* translators: 1: software name, 2: installed version, 3: CVSS score in brackets or empty, 4: vulnerability title, 5: fixed version(s). */
			? sprintf( __( '%1$s %2$s%3$s: %4$s. Update to %5$s or later.', 'wphouse' ), $finding['name'], $finding['version'], $cvss, $finding['title'], implode( ' / ', $finding['fixed'] ) )
			/* translators: 1: software name, 2: installed version, 3: CVSS score in brackets or empty, 4: vulnerability title. */
			: sprintf( __( '%1$s %2$s%3$s: %4$s. No fixed version yet: deactivate it if you can do without it.', 'wphouse' ), $finding['name'], $finding['version'], $cvss, $finding['title'] );
	}

	/**
	 * Link to the Wordfence record, built from a validated id only. Returns escaped HTML.
	 *
	 * @param array{id: string} $finding Finding.
	 */
	private function details_link( array $finding ): string {
		if ( ! preg_match( self::ID_FORMAT, $finding['id'] ) ) {
			return '';
		}
		return ' <a href="' . esc_url( 'https://www.wordfence.com/threat-intel/vulnerabilities/id/' . $finding['id'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Details', 'wphouse' ) . '</a>';
	}

	/**
	 * Verified index: format, generated, attribution and 256 shard hashes.
	 *
	 * @return array{generated: string, attribution: string, shards: array<string, string>}|WP_Error
	 */
	private function fetch_index(): array|WP_Error {
		$url  = $this->index_url();
		$body = $this->get_body( $url, self::MAX_INDEX );
		$sig  = $this->get_body( $url . '.sig', KB_IN_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		if ( ! Signature::verify( $body, $sig, $this->public_keys() ) ) {
			Log::add( 'advisories_rejected', 'Vulnerability data has an invalid signature', [ 'url' => $url ], 'critical' );
			return new WP_Error( 'wphouse_bad_signature', 'Vulnerability data signature is invalid.' );
		}
		$data   = json_decode( $body, true );
		$shards = is_array( $data ) ? (array) ( $data['shards'] ?? [] ) : [];
		if ( 1 !== ( $data['format'] ?? null ) || 256 !== count( $shards ) ) {
			return new WP_Error( 'wphouse_bad_advisories', 'Vulnerability data has an unknown format.' );
		}
		foreach ( $shards as $name => $hash ) {
			if ( ! preg_match( '/^[0-9a-f]{2}$/', (string) $name ) || ! preg_match( '/^[0-9a-f]{64}$/', (string) $hash ) ) {
				return new WP_Error( 'wphouse_bad_advisories', 'Vulnerability data has an unknown format.' );
			}
		}
		return [
			'generated'   => mb_substr( sanitize_text_field( (string) ( $data['generated'] ?? '' ) ), 0, 40 ),
			'attribution' => mb_substr( sanitize_text_field( (string) ( $data['attribution'] ?? '' ) ), 0, 500 ),
			'shards'      => $shards,
		];
	}

	/**
	 * Entries of one shard for the given items, after checking the shard against the signed hash.
	 *
	 * @param string[] $items "type:slug" keys installed on this site.
	 * @return array<string, list<array{0: string, 1: string, 2: float|null, 3: array<int, array{0: string, 1: bool, 2: string, 3: bool}>, 4: string[]}>>|WP_Error
	 */
	private function fetch_shard( string $name, string $hash, array $items ): array|WP_Error {
		$body = $this->get_body( dirname( $this->index_url() ) . '/' . $name . '.json', self::MAX_SHARD );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $body ) ) ) {
			Log::add( 'advisories_rejected', 'Vulnerability data shard does not match the signed index', [ 'shard' => $name ], 'critical' );
			return new WP_Error( 'wphouse_bad_shard', 'Vulnerability data does not match its signed checksum.' );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wphouse_bad_shard', 'Vulnerability data has an unknown format.' );
		}
		$out = [];
		foreach ( $items as $item ) {
			foreach ( (array) ( $data[ $item ] ?? [] ) as $vuln ) {
				$clean = self::clean_entry( $vuln );
				if ( null !== $clean ) {
					$out[ $item ][] = $clean;
				}
			}
		}
		return $out;
	}

	/**
	 * Keep only well-formed fields; anything else in the data is dropped.
	 *
	 * @param mixed $vuln Raw entry.
	 * @return array{0: string, 1: string, 2: float|null, 3: array<int, array{0: string, 1: bool, 2: string, 3: bool}>, 4: string[]}|null
	 */
	private static function clean_entry( mixed $vuln ): ?array {
		if ( ! is_array( $vuln ) || 5 !== count( $vuln ) || ! is_scalar( $vuln[0] ) || ! is_scalar( $vuln[1] ) || ! is_array( $vuln[3] ) || ! is_array( $vuln[4] ) ) {
			return null;
		}
		$ranges = [];
		foreach ( $vuln[3] as $range ) {
			if ( is_array( $range ) && 4 === count( $range ) && is_scalar( $range[0] ) && is_scalar( $range[2] ) ) {
				$ranges[] = [ (string) $range[0], (bool) $range[1], (string) $range[2], (bool) $range[3] ];
			}
		}
		if ( ! $ranges ) {
			return null;
		}
		return [
			mb_substr( (string) $vuln[0], 0, 64 ),
			mb_substr( sanitize_text_field( (string) $vuln[1] ), 0, 300 ),
			is_numeric( $vuln[2] ) ? (float) $vuln[2] : null,
			$ranges,
			array_slice( array_map( static fn( $v ) => mb_substr( sanitize_text_field( (string) $v ), 0, 20 ), array_filter( $vuln[4], 'is_scalar' ) ), 0, 5 ),
		];
	}

	private function get_body( string $url, int $limit ): string|WP_Error {
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 15,
				'redirection'         => 2,
				'limit_response_size' => $limit,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'wphouse_advisories_http', sprintf( 'HTTP %d for %s', wp_remote_retrieve_response_code( $response ), $url ) );
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Installed WordPress, plugins and themes as "type:slug" => name and version.
	 *
	 * @return array<string, array{name: string, version: string}>
	 */
	private function installed(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$items = [
			'core:wordpress' => [
				'name'    => 'WordPress',
				'version' => (string) get_bloginfo( 'version' ),
			],
		];
		foreach ( get_plugins() as $file => $data ) {
			if ( Updater::basename() !== $file ) {
				$items[ 'plugin:' . PluginHealth::slug( (string) $file ) ] = [
					'name'    => (string) $data['Name'],
					'version' => (string) $data['Version'],
				];
			}
		}
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[ 'theme:' . $stylesheet ] = [
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			];
		}
		return $items;
	}

	/** Changes when software is added or removed, so new items get their shard before the 6-hour mark. */
	private function fingerprint(): string {
		$items = array_keys( $this->installed() );
		sort( $items );
		return md5( implode( '|', $items ) );
	}

	/** @return array<string, mixed> */
	private function state(): array {
		$state = get_option( self::OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	private function index_url(): string {
		if ( Updater::is_local() && defined( 'WPHOUSE_ADVISORY_URL' ) ) {
			return (string) WPHOUSE_ADVISORY_URL;
		}
		return self::INDEX_URL;
	}

	/** @return string[] */
	private function public_keys(): array {
		if ( Updater::is_local() && defined( 'WPHOUSE_ADVISORY_PUBLIC_KEYS' ) && is_array( WPHOUSE_ADVISORY_PUBLIC_KEYS ) ) {
			return array_map( 'strval', WPHOUSE_ADVISORY_PUBLIC_KEYS );
		}
		return self::PUBLIC_KEYS;
	}
}
