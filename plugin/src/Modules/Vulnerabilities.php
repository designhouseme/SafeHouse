<?php
/**
 * Vulnerability alerts for sites without Wordfence: the installed WordPress, plugin and theme
 * versions are matched against signed advisory data from the SafeHouse update host, built from
 * Wordfence Intelligence by dev/cve-watch.php. Wordfence does this itself, so the module stands
 * down while Wordfence is active.
 *
 * Downloads happen only in cron (hourly hook, at most every 6 hours): index.json and its Ed25519
 * signature, checked with a data-only key that the updater does not trust, then only the shards
 * for what is installed, each checked against the SHA-256 in the signed index. Matching runs on
 * every view from the stored data, so updating a plugin clears its warning at once.
 *
 * Constants, honoured only on local/development sites: SHOUSE_ADVISORY_URL, SHOUSE_ADVISORY_PUBLIC_KEYS.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use WP_Error;
use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Compat;
use SafeHouse\Core\Log;
use SafeHouse\Core\Notify;
use SafeHouse\Core\Signature;
use SafeHouse\Core\Updater;
use SafeHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Vulnerabilities extends AbstractModule {

	private const OPTION    = 'shouse_vulnerabilities';
	private const FINDINGS  = 'shouse_vulnerabilities_findings';
	private const FLOOR     = 'shouse_advisory_floor';
	private const INDEX_URL = 'https://updates.designhouse.me/shouse/advisories/index.json';

	/** Base64 Ed25519 keys for advisory data only. The updater never trusts these. */
	private const PUBLIC_KEYS = [
		'a6vBsYaycKRtBA7v4cySBDY+SQnezuUPXgsjBriP64M=', // 2026-10-06, signs in CI.
	];

	private const MAX_AGE     = 6 * HOUR_IN_SECONDS;
	private const SOURCE_AGE  = 48 * HOUR_IN_SECONDS;
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
		return __( 'Vulnerability alerts', 'shouse' );
	}

	public function description(): string {
		return __( 'Warns when the installed WordPress, a plugin or a theme has a known security vulnerability, and names the version that fixes it. Meant for sites without Wordfence, which does this itself. Data from Wordfence Intelligence, signed and served by the SafeHouse update host, checked every 6 hours.', 'shouse' );
	}

	public function fields(): array {
		return [];
	}

	public function available(): bool {
		return ! Compat::wordfence_active() || Compat::ignore_overlaps();
	}

	public function unavailable_reason(): string {
		return __( 'Wordfence is active and already warns about vulnerable plugins, themes and WordPress versions.', 'shouse' );
	}

	public function boot(): void {
		add_action( 'shouse_hourly', [ $this, 'maybe_refresh' ] );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		foreach ( [ 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', '_core_updated_successfully' ] as $hook ) {
			add_action( $hook, [ $this, 'forget' ] );
		}
		add_filter( 'site_status_tests', [ $this, 'site_health_test' ] );
		if ( Updater::is_local() ) {
			add_filter( 'http_request_host_is_external', [ $this, 'allow_local_host' ], 10, 2 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'shouse vulnerabilities', [ $this, 'cli' ] );
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
		$baseline             = $state;
		$state['checked_at']  = time();
		$state['fingerprint'] = $this->fingerprint();

		$index = $this->fetch_index();
		if ( is_wp_error( $index ) ) {
			$state['error'] = $index->get_error_message();
			return $this->save_state( $state, $baseline );
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
			$entries = $this->fetch_shard( (string) $name, $hash, $items, 2 === $index['protocol'] );
			if ( is_wp_error( $entries ) ) {
				$state['error'] = $entries->get_error_message();
				return $this->save_state( $state, $baseline );
			}
			$shards[ $name ] = [
				'hash'    => $hash,
				'items'   => $items,
				'entries' => $entries,
			];
		}

		$floor = [
			'protocol'   => $index['protocol'],
			'generation' => $index['generation'],
			'digest'     => $index['_digest'],
		];
		if ( ! Signature::advance_floor( self::FLOOR, $floor ) ) {
			$state['error'] = 'Vulnerability data would roll back an accepted generation or could not be persisted.';
			return $this->save_state( $state, $baseline );
		}
		$state['error']        = '';
		$state['last_success'] = time();
		$state['expires_at']   = $index['expires_at'];
		$state['generation']   = $index['generation'];
		$state['protocol']     = $index['protocol'];
		$state['digest']       = $index['_digest'];
		$state['generated']    = $index['generated'];
		$state['attribution']  = $index['attribution'];
		$state['shards']       = $shards;

		$state = $this->save_state( $state, $baseline, $floor );
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
		if ( ! $new ) {
			update_option( self::OPTION . '_notified', array_keys( $current ), false );
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
		if ( Notify::send( sprintf( _n( '%d known vulnerability in installed software', '%d known vulnerabilities in installed software', count( $new ), 'shouse' ), count( $new ) ), $lines ) ) {
			update_option( self::OPTION . '_notified', array_keys( $current ), false );
		}
	}

	public function admin_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_core' ) ) {
			return;
		}
		$urgent = array_filter( $this->findings(), static fn( $f ) => $f['urgent'] );
		if ( ! $urgent ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Security vulnerability in installed software', 'shouse' ) . '</strong></p><ul>';
		foreach ( array_slice( $urgent, 0, 5 ) as $finding ) {
			echo '<li>' . esc_html( $this->sentence( $finding ) ) . $this->details_link( $finding ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- details_link() escapes.
		}
		echo '</ul><p><a class="button button-primary" href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to updates', 'shouse' ) . '</a> ';
		echo '<a href="' . esc_url( Plugin::settings_url( 'vulnerabilities' ) ) . '">' . esc_html__( 'All findings', 'shouse' ) . '</a></p></div>';
	}

	/**
	 * @param array<string, callable[]|array<string, mixed>> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['shouse_vulnerabilities'] = [
			'label' => __( 'SafeHouse vulnerability alerts', 'shouse' ),
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
			$label  = __( 'Installed software has known vulnerabilities', 'shouse' );
			$items  = '<ul>' . implode( '', array_map( fn( $f ) => '<li>' . esc_html( $this->sentence( $f ) ) . '</li>', $findings ) ) . '</ul>';
		} elseif ( ! $this->current_data( $state ) ) {
			$status = 'recommended';
			$label  = __( 'Vulnerability data is unavailable or out of date', 'shouse' );
			$items  = '<p>' . esc_html( (string) ( $state['error'] ?? __( 'A successful recent vulnerability check is required.', 'shouse' ) ) ) . '</p>';
		} else {
			$status = 'good';
			$label  = __( 'No known vulnerabilities in installed software', 'shouse' );
			$items  = '';
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Security', 'shouse' ),
				'color' => 'blue',
			],
			'description' => $items . '<p>' . esc_html( (string) ( $state['attribution'] ?? '' ) ) . '</p>',
			'actions'     => '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Go to updates', 'shouse' ) . '</a>',
			'test'        => 'shouse_vulnerabilities',
		];
	}

	public function tasks(): array {
		return [ 'refresh' => __( 'Check now', 'shouse' ) ];
	}

	public function handle_task( string $task ): string {
		$state = $this->refresh();
		$this->notify_new();
		return '' !== ( $state['error'] ?? '' )
			/* translators: %s: error message. */
			? sprintf( __( 'Could not download vulnerability data: %s', 'shouse' ), (string) $state['error'] )
			: __( 'Vulnerability data updated.', 'shouse' );
	}

	public function render_panel(): void {
		$state = $this->state();
		echo '<div class="shouse-panel"><p>';
		if ( empty( $state['checked_at'] ) ) {
			echo esc_html__( 'Not checked yet. Use "Check now" or wait for the next hourly run.', 'shouse' );
		} else {
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Last check: %s.', 'shouse' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['checked_at'] ) ) );
			if ( '' !== ( $state['error'] ?? '' ) ) {
				/* translators: %s: error message. */
				echo ' ' . esc_html( sprintf( __( 'The last download failed: %s', 'shouse' ), (string) $state['error'] ) );
			}
		}
		echo '</p>';
		$findings = $this->findings( true );
		if ( ! $this->current_data( $state ) ) {
			echo '<p>' . esc_html__( 'Vulnerability data is unavailable or out of date. Previous findings remain visible; a clean result cannot be confirmed.', 'shouse' ) . '</p>';
		} elseif ( ! $findings ) {
			echo '<p>' . esc_html__( 'No known vulnerabilities in installed software.', 'shouse' ) . '</p>';
		}
		if ( $findings ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Software', 'shouse' ) . '</th><th>' . esc_html__( 'Vulnerability', 'shouse' ) . '</th><th>' . esc_html__( 'Fixed in', 'shouse' ) . '</th></tr></thead><tbody>';
			foreach ( $findings as $finding ) {
				$severity = $finding['urgent'] ? 'critical' : 'warning';
				echo '<tr><td><strong>' . esc_html( $finding['name'] ) . '</strong> ' . esc_html( $finding['version'] ) . '</td>';
				echo '<td><span class="shouse-badge shouse-badge--' . esc_attr( $severity ) . '">' . esc_html( null !== $finding['cvss'] ? 'CVSS ' . number_format_i18n( $finding['cvss'], 1 ) : $severity ) . '</span> ' . esc_html( $finding['title'] ) . $this->details_link( $finding ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- details_link() escapes.
				echo '<td>' . esc_html( $finding['fixed'] ? implode( ', ', $finding['fixed'] ) : __( 'no fix yet', 'shouse' ) ) . '</td></tr>';
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
			? sprintf( __( '%1$s %2$s%3$s: %4$s. Update to %5$s or later.', 'shouse' ), $finding['name'], $finding['version'], $cvss, $finding['title'], implode( ' / ', $finding['fixed'] ) )
			/* translators: 1: software name, 2: installed version, 3: CVSS score in brackets or empty, 4: vulnerability title. */
			: sprintf( __( '%1$s %2$s%3$s: %4$s. No fixed version yet: deactivate it if you can do without it.', 'shouse' ), $finding['name'], $finding['version'], $cvss, $finding['title'] );
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
		return ' <a href="' . esc_url( 'https://www.wordfence.com/threat-intel/vulnerabilities/id/' . $finding['id'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Details', 'shouse' ) . '</a>';
	}

	/**
	 * Verified index: format, generated, attribution and 256 shard hashes.
	 *
	 * @return array{generated: string, attribution: string, shards: array<string, string>, protocol: int, generation: int, issued_at: int, expires_at: int, _digest: string}|WP_Error
	 */
	private function fetch_index(): array|WP_Error {
		$url      = $this->index_url();
		$envelope = $this->get_body( dirname( $url ) . '/feed.json', 2 * self::MAX_INDEX );
		if ( is_wp_error( $envelope ) ) {
			$floor = get_option( self::FLOOR, [] );
			if ( 'shouse_advisories_not_found' !== $envelope->get_error_code() || ( is_array( $floor ) && ( $floor['protocol'] ?? 1 ) >= 2 ) ) {
				return $envelope;
			}
			$body = $this->get_body( $url, self::MAX_INDEX );
			$sig  = $this->get_body( $url . '.sig', KB_IN_BYTES );
			if ( is_wp_error( $body ) || is_wp_error( $sig ) ) {
				return is_wp_error( $body ) ? $body : $sig;
			}
			$body = Signature::verify( $body, $sig, $this->public_keys() ) ? $body : null;
		} else {
			$body = Signature::unpack( $envelope, $this->public_keys(), self::MAX_INDEX );
		}
		if ( null === $body ) {
			Log::add( 'advisories_rejected', 'Vulnerability data has an invalid signature', [ 'url' => $url ], 'critical' );
			return new WP_Error( 'shouse_bad_signature', 'Vulnerability data signature is invalid.' );
		}
		$data   = json_decode( $body, true );
		$shards = is_array( $data ) && is_array( $data['shards'] ?? null ) ? $data['shards'] : [];
		if ( ! is_array( $data ) || 1 !== ( $data['format'] ?? null ) || 256 !== count( $shards ) ) {
			return new WP_Error( 'shouse_bad_advisories', 'Vulnerability data has an unknown format.' );
		}
		foreach ( $shards as $name => $hash ) {
			if ( ! preg_match( '/^[0-9a-f]{2}$/', (string) $name ) || ! is_string( $hash ) || ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
				return new WP_Error( 'shouse_bad_advisories', 'Vulnerability data has an unknown format.' );
			}
		}
		$freshness = Signature::freshness( $data, 'advisories', self::SOURCE_AGE, 'generated', 'Y-m-d\TH:i:s\Z' );
		if ( null === $freshness || ! is_string( $data['generated'] ?? null ) || ! is_string( $data['attribution'] ?? null ) ) {
			return new WP_Error( 'shouse_stale_advisories', 'Vulnerability data is expired, future-dated or malformed.' );
		}
		$digest = hash( 'sha256', $body );
		if ( ! Signature::advance_floor(
			self::FLOOR,
			[
				'protocol'   => $freshness['protocol'],
				'generation' => $freshness['generation'],
				'digest'     => $digest,
			]
		) ) {
			return new WP_Error( 'shouse_advisories_rollback', 'Vulnerability data would roll back an accepted generation or could not be persisted.' );
		}
		return [
			'generated'   => mb_substr( sanitize_text_field( $data['generated'] ), 0, 40 ),
			'attribution' => mb_substr( sanitize_text_field( $data['attribution'] ), 0, 500 ),
			'shards'      => $shards,
			'_digest'     => $digest,
		] + $freshness;
	}

	/**
	 * Entries of one shard for the given items, after checking the shard against the signed hash.
	 *
	 * @param string[] $items "type:slug" keys installed on this site.
	 * @return array<string, list<array{0: string, 1: string, 2: float|null, 3: array<int, array{0: string, 1: bool, 2: string, 3: bool}>, 4: string[]}>>|WP_Error
	 */
	private function fetch_shard( string $name, string $hash, array $items, bool $immutable ): array|WP_Error {
		$path = $immutable ? 'sha256/' . $hash : $name;
		$body = $this->get_body( dirname( $this->index_url() ) . '/' . $path . '.json', self::MAX_SHARD );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		if ( ! hash_equals( $hash, hash( 'sha256', $body ) ) ) {
			Log::add( 'advisories_rejected', 'Vulnerability data shard does not match the signed index', [ 'shard' => $name ], 'critical' );
			return new WP_Error( 'shouse_bad_shard', 'Vulnerability data does not match its signed checksum.' );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'shouse_bad_shard', 'Vulnerability data has an unknown format.' );
		}
		$out = [];
		foreach ( $items as $item ) {
			if ( isset( $data[ $item ] ) && ! is_array( $data[ $item ] ) ) {
				return new WP_Error( 'shouse_bad_shard', 'Vulnerability data contains an invalid entry.' );
			}
			foreach ( (array) ( $data[ $item ] ?? [] ) as $vuln ) {
				$clean = self::clean_entry( $vuln );
				if ( null === $clean ) {
					return new WP_Error( 'shouse_bad_shard', 'Vulnerability data contains an invalid entry.' );
				}
				$out[ $item ][] = $clean;
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
		if ( ! is_array( $vuln ) || ! array_is_list( $vuln ) || 5 !== count( $vuln ) || ! is_string( $vuln[0] ) || ! preg_match( self::ID_FORMAT, $vuln[0] )
			|| ! is_string( $vuln[1] ) || ! is_array( $vuln[3] ) || ! is_array( $vuln[4] )
			|| ( null !== $vuln[2] && ( ! is_numeric( $vuln[2] ) || $vuln[2] < 0 || $vuln[2] > 10 ) ) ) {
			return null;
		}
		$ranges = [];
		foreach ( $vuln[3] as $range ) {
			if ( ! is_array( $range ) || ! array_is_list( $range ) || 4 !== count( $range ) || ! is_string( $range[0] ) || ! is_string( $range[2] )
				|| '' === $range[0] || '' === $range[2] || strlen( $range[0] ) > 64 || strlen( $range[2] ) > 64 || ! is_bool( $range[1] ) || ! is_bool( $range[3] ) ) {
				return null;
			}
			$ranges[] = [ $range[0], $range[1], $range[2], $range[3] ];
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
			return new WP_Error( 404 === wp_remote_retrieve_response_code( $response ) ? 'shouse_advisories_not_found' : 'shouse_advisories_http', sprintf( 'HTTP %d for %s', wp_remote_retrieve_response_code( $response ), $url ) );
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

	/**
	 * Publish a complete snapshot atomically. A slower old refresh cannot erase newer findings.
	 *
	 * @param array<string, mixed>      $state          Candidate state.
	 * @param array<string, mixed>|null $baseline       Snapshot at the beginning of the check.
	 * @param array<string, mixed>|null $accepted_floor Exact floor required to publish a success.
	 * @return array<string, mixed> State kept in the database.
	 */
	private function save_state( array $state, ?array $baseline = null, ?array $accepted_floor = null ): array {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$floor_raw = null;
			if ( null !== $accepted_floor ) {
				$floor_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::FLOOR ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- must match the authoritative accepted generation.
			}
			$raw      = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- snapshot compare-and-swap, not cacheable.
			$previous = null === $raw ? [] : maybe_unserialize( $raw );
			if ( null !== $accepted_floor && maybe_unserialize( $floor_raw ?? '' ) !== $accepted_floor ) {
				$kept = is_array( $previous ) ? $previous : [];
				if ( ! $this->current_data( $kept ) ) {
					$kept['error'] = 'Vulnerability data changed while this check was running.';
				}
				return $kept;
			}
			if ( ! empty( $state['error'] ) && null !== $baseline && is_array( $previous ) && $previous !== $baseline ) {
				return $previous; // An older failed request must not erase a concurrent success, even for the same generation.
			}
			if ( null === $raw ) {
				if ( null !== $accepted_floor ) {
					$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'off' FROM {$wpdb->options} AS floor WHERE floor.option_name = %s AND BINARY floor.option_value = %s", self::OPTION, maybe_serialize( $state ), self::FLOOR, $floor_raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic first snapshot conditioned on its accepted floor.
				} else {
					$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION, maybe_serialize( $state ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an initial error must never overwrite a concurrent first success.
				}
				if ( 1 === $inserted ) {
					wp_cache_delete( self::OPTION, 'options' );
					wp_cache_delete( 'notoptions', 'options' );
					return $state;
				}
				continue;
			}
			if ( is_array( $previous ) && (int) ( $previous['generation'] ?? 0 ) > (int) ( $state['generation'] ?? 0 ) ) {
				return $previous;
			}
			$encoded = maybe_serialize( $state );
			if ( $encoded === $raw ) {
				return $state;
			}
			if ( null !== $accepted_floor ) {
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} AS snapshot INNER JOIN {$wpdb->options} AS floor ON floor.option_name = %s SET snapshot.option_value = %s WHERE snapshot.option_name = %s AND BINARY snapshot.option_value = %s AND BINARY floor.option_value = %s", self::FLOOR, $encoded, self::OPTION, $raw, $floor_raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- floor and complete snapshot must still match in one atomic statement.
			} else {
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $encoded, self::OPTION, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- snapshot compare-and-swap cannot use update_option().
			}
			if ( 1 === $updated ) {
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return $state;
			}
		}
		$state['error'] = 'Could not persist the complete vulnerability check.';
		return $state;
	}

	/** @param array<string, mixed> $state Last downloaded data, retained even after an error. */
	private function current_data( array $state ): bool {
		$floor = get_option( self::FLOOR, [] );
		return '' === ( $state['error'] ?? '' ) && ! empty( $state['last_success'] )
			&& time() - (int) $state['last_success'] <= self::MAX_AGE + HOUR_IN_SECONDS
			&& (int) ( $state['expires_at'] ?? 0 ) > time()
			&& (int) ( $state['generation'] ?? 0 ) >= (int) ( is_array( $floor ) ? ( $floor['generation'] ?? 0 ) : 0 )
			&& is_array( $floor ) && ( $state['protocol'] ?? null ) === ( $floor['protocol'] ?? null ) && ( $state['digest'] ?? null ) === ( $floor['digest'] ?? null )
			&& ( $state['fingerprint'] ?? '' ) === $this->fingerprint();
	}

	private function index_url(): string {
		if ( Updater::is_local() && defined( 'SHOUSE_ADVISORY_URL' ) ) {
			return (string) SHOUSE_ADVISORY_URL;
		}
		return self::INDEX_URL;
	}

	/** @return string[] */
	private function public_keys(): array {
		if ( Updater::is_local() && defined( 'SHOUSE_ADVISORY_PUBLIC_KEYS' ) && is_array( SHOUSE_ADVISORY_PUBLIC_KEYS ) ) {
			return array_map( 'strval', SHOUSE_ADVISORY_PUBLIC_KEYS );
		}
		return self::PUBLIC_KEYS;
	}
}
