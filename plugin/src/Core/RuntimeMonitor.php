<?php
/**
 * Best-effort request observations, with bounded storage and no request payloads.
 * A shutdown callback observes a failure; it cannot interrupt stuck PHP or survive every OOM.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use Throwable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- bounded local observations and atomic sampling gate.
final class RuntimeMonitor {

	private const VERSION                 = '1';
	private const SLOTS                   = 256;
	private static bool $started          = false;
	private static bool $finished         = false;
	private static bool $detailed         = false;
	private static float $start           = 0.0;
	private static string $reserve        = ''; // @phpstan-ignore property.onlyWritten (allocated for release during fatal handling)
	private static int $http_count        = 0;
	private static int $http_failures     = 0;
	private static float $http_ms         = 0.0;
	private static string $http_component = '';
	private static float $slowest_http    = 0.0;
	private static bool $read_available   = false;
	/** @var array<string, float> */
	private static array $http_starts = [];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shouse_incidents';
	}

	/** Called from the main plugin file, before plugins_loaded. No schema changes here. */
	public static function start(): void {
		if ( self::$started || SafeMode::active() ) {
			return;
		}
		if ( self::VERSION !== get_option( 'shouse_stability_db_version' ) ) {
			return;
		}
		// Avoid constructing Plugin/Settings before the legacy migration has run.
		$settings = get_option( Settings::OPTION, [] );
		$enabled  = defined( 'SHOUSE_MODULES' ) && is_array( SHOUSE_MODULES ) && array_key_exists( 'stability', SHOUSE_MODULES ) ? (bool) SHOUSE_MODULES['stability'] : (bool) ( $settings['modules']['stability'] ?? true );
		if ( ! $enabled ) {
			return;
		}
		self::$started = true;
		self::$start   = WorkBudget::clock();
		self::$reserve = str_repeat( 'r', 32768 );
		register_shutdown_function( [ self::class, 'finish' ] );
		// Preserve the original error if core's earlier handler performs further work.
		add_filter( 'wp_php_error_args', [ self::class, 'fatal_template' ], PHP_INT_MAX, 2 );
		$until          = (int) get_option( 'shouse_stability_session', 0 );
		self::$detailed = $until > time() && $until <= time() + 15 * MINUTE_IN_SECONDS && 1 === mt_rand( 1, 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- sampling before pluggable.php, not a security decision.
		if ( self::$detailed ) {
			add_filter( 'http_request_args', [ self::class, 'http_start' ], PHP_INT_MAX, 2 );
			add_action( 'http_api_debug', [ self::class, 'http_end' ], PHP_INT_MAX, 5 );
		}
	}

	/** Installed explicitly by module boot, never in the fatal handler. */
	public static function install(): void {
		if ( self::VERSION === get_option( 'shouse_stability_db_version' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				slot smallint unsigned NOT NULL,
				fingerprint char(64) NOT NULL,
				kind varchar(24) NOT NULL,
				context varchar(16) NOT NULL,
				component varchar(100) NOT NULL DEFAULT '',
				line_number int unsigned NOT NULL DEFAULT 0,
				first_seen bigint unsigned NOT NULL,
				last_seen bigint unsigned NOT NULL,
				observations bigint unsigned NOT NULL DEFAULT 1,
				elapsed_ms bigint unsigned NOT NULL DEFAULT 0,
				peak_bytes bigint unsigned NOT NULL DEFAULT 0,
				http_count int unsigned NOT NULL DEFAULT 0,
				http_ms bigint unsigned NOT NULL DEFAULT 0,
				http_failures int unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (slot),
				KEY last_seen (last_seen)
			) {$wpdb->get_charset_collate()};"
		);
		if ( '' !== $wpdb->last_error || $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}
		if ( ! add_option( 'shouse_stability_write_after', '0', '', false ) && null === get_option( 'shouse_stability_write_after', null ) ) {
			return;
		}
		update_option( 'shouse_stability_db_version', self::VERSION, false );
	}

	/**
	 * @param array<string, mixed> $args HTTP arguments; passed through unchanged.
	 * @return array<string, mixed>
	 */
	public static function http_start( array $args, string $url ): array {
		if ( self::$http_count + count( self::$http_starts ) < 20 ) {
			self::$http_starts[ hash( 'sha256', $url ) ] = WorkBudget::clock();
		}
		return $args;
	}

	/**
	 * @param mixed $response HTTP response.
	 * @param array<string, mixed> $args Request arguments.
	 */
	public static function http_end( mixed $response, string $context, string $transport, array $args, string $url ): void {
		$key = hash( 'sha256', $url );
		if ( 'response' !== $context || ! isset( self::$http_starts[ $key ] ) ) {
			return;
		}
		$elapsed = max( 0.0, ( WorkBudget::clock() - self::$http_starts[ $key ] ) * 1000 );
		unset( self::$http_starts[ $key ] );
		++self::$http_count;
		self::$http_ms       += $elapsed;
		self::$http_failures += is_wp_error( $response ) ? 1 : 0;
		if ( $elapsed > self::$slowest_http && $elapsed >= 500 ) {
			self::$slowest_http = $elapsed;
			// Arguments are deliberately excluded: they may contain passwords or payment payloads.
			foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- capped, opt-in diagnostic samples only.
				$component = self::component( (string) ( $frame['file'] ?? '' ) );
				if ( str_starts_with( $component, 'plugin:' ) && 'plugin:shouse' !== $component ) {
					self::$http_component = $component;
					break;
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $args Core template arguments.
	 * @param array{type:int,message:string,file:string,line:int} $error PHP error.
	 * @return array<string, mixed>
	 */
	public static function fatal_template( array $args, array $error ): array {
		self::finish( $error );
		return $args;
	}

	/**
	 * Called once by PHP or the core template; no mail, scans or raw error text.
	 * @param array{type:int,message:string,file:string,line:int}|null $error Original error, if supplied.
	 */
	public static function finish( ?array $error = null ): void {
		if ( self::$finished || ! self::$started ) {
			return;
		}
		self::$finished = true;
		self::$reserve  = '';
		$error          = $error ?? error_get_last();
		$fatal          = is_array( $error ) && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ], true );
		$elapsed        = max( 0, (int) ( ( WorkBudget::clock() - self::$start ) * 1000 ) );
		$peak           = memory_get_peak_usage( true );
		$limit          = WorkBudget::memory_limit();
		$pressure       = $limit > 0 && $peak >= $limit * 0.8;
		if ( ! $fatal && $elapsed < 3000 && ! $pressure && ! self::$detailed ) {
			return;
		}
		$kind = $fatal ? 'fatal' : ( $pressure ? 'memory_pressure' : ( $elapsed >= 3000 ? 'slow' : 'sample' ) );
		if ( $fatal && str_starts_with( $error['message'], 'Allowed memory size' ) ) {
			$kind = 'memory_exhausted';
		} elseif ( $fatal && str_starts_with( $error['message'], 'Maximum execution time' ) ) {
			$kind = 'time_limit';
		}
		self::record( $kind, self::context(), $fatal ? self::component( $error['file'] ) : self::$http_component, $fatal ? (int) $error['line'] : 0, $elapsed, $peak );
	}

	/** A site-wide write gate and fixed slots bound event storms and storage cardinality. */
	public static function record( string $kind, string $context, string $component, int $line, int $elapsed, int $peak ): bool {
		if ( ! in_array( $kind, [ 'fatal', 'memory_exhausted', 'time_limit', 'slow', 'memory_pressure', 'sample' ], true ) ) {
			return false;
		}
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$now = time();
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'shouse_stability_write_after' AND CAST(option_value AS UNSIGNED) <= %d", (string) ( $now + 10 ), $now ) ) ) {
				return false;
			}
			$context     = in_array( $context, [ 'web', 'admin', 'ajax', 'rest', 'cron', 'cli', 'login' ], true ) ? $context : 'web';
			$component   = preg_match( '/^(?:plugin|theme|mu):[a-zA-Z0-9_.-]{1,80}$|^core$/D', $component ) ? $component : '';
			$fingerprint = hash( 'sha256', implode( '|', [ $kind, $context, $component, $line, gmdate( 'Y-m-d' ) ] ) );
			$slot        = hexdec( substr( $fingerprint, 0, 2 ) ) % self::SLOTS;
			// Assignment order matters: compare the old fingerprint before assigning its replacement.
			return false !== $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (slot,fingerprint,kind,context,component,line_number,first_seen,last_seen,observations,elapsed_ms,peak_bytes,http_count,http_ms,http_failures) VALUES (%d,%s,%s,%s,%s,%d,%d,%d,1,%d,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE observations=IF(fingerprint=VALUES(fingerprint),observations+1,1),first_seen=IF(fingerprint=VALUES(fingerprint),first_seen,VALUES(first_seen)),elapsed_ms=IF(fingerprint=VALUES(fingerprint),GREATEST(elapsed_ms,VALUES(elapsed_ms)),VALUES(elapsed_ms)),peak_bytes=IF(fingerprint=VALUES(fingerprint),GREATEST(peak_bytes,VALUES(peak_bytes)),VALUES(peak_bytes)),fingerprint=VALUES(fingerprint),kind=VALUES(kind),context=VALUES(context),component=VALUES(component),line_number=VALUES(line_number),last_seen=VALUES(last_seen),http_count=VALUES(http_count),http_ms=VALUES(http_ms),http_failures=VALUES(http_failures)',
					self::table(),
					$slot,
					$fingerprint,
					$kind,
					$context,
					$component,
					max( 0, $line ),
					$now,
					$now,
					min( 86400000, max( 0, $elapsed ) ),
					max( 0, $peak ),
					self::$http_count,
					(int) self::$http_ms,
					self::$http_failures
				)
			);
		} catch ( Throwable $error ) {
			return false;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** @return list<array<string, mixed>> */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		self::$read_available = false;
		if ( self::VERSION !== get_option( 'shouse_stability_db_version' ) ) {
			return [];
		}
		$errors = $wpdb->suppress_errors( true );
		$gate   = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'shouse_stability_write_after'" );
		if ( null === $gate || self::query_failed() ) {
			$wpdb->suppress_errors( $errors );
			return [];
		}
		$rows                 = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE last_seen >= %d ORDER BY last_seen DESC LIMIT %d', self::table(), time() - 7 * DAY_IN_SECONDS, max( 1, min( 100, $limit ) ) ), ARRAY_A );
		self::$read_available = ! self::query_failed() && is_array( $rows );
		$wpdb->suppress_errors( $errors );
		return is_array( $rows ) ? $rows : [];
	}

	/** Result of the most recent read, not an independent database probe. */
	public static function storage_available(): bool {
		return self::$read_available;
	}

	/** @phpstan-impure */
	private static function query_failed(): bool {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}

	public static function purge(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %d LIMIT 256', self::table(), time() - 7 * DAY_IN_SECONDS ) );
	}

	/** Report a component boundary, never an absolute path or a URL. This is location, not blame. */
	public static function component( string $file ): string {
		$file = str_replace( '\\', '/', $file );
		foreach ( [
			'plugin' => WP_PLUGIN_DIR,
			'mu'     => WPMU_PLUGIN_DIR,
			'theme'  => WP_CONTENT_DIR . '/themes',
		] as $kind => $directory ) {
			$prefix = rtrim( str_replace( '\\', '/', $directory ), '/' ) . '/';
			if ( str_starts_with( $file, $prefix ) ) {
				$name = explode( '/', substr( $file, strlen( $prefix ) ) )[0];
				return $kind . ':' . substr( preg_replace( '/[^a-zA-Z0-9_.-]/', '', $name ) ?? '', 0, 80 );
			}
		}
		return str_starts_with( $file, str_replace( '\\', '/', ABSPATH ) . 'wp-includes/' ) || str_starts_with( $file, str_replace( '\\', '/', ABSPATH ) . 'wp-admin/' ) ? 'core' : '';
	}

	private static function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) { // @phpstan-ignore phpstanWP.wpConstant.fetch (avoid running arbitrary filters in fatal handling)
			return 'cron';
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { // @phpstan-ignore phpstanWP.wpConstant.fetch (avoid running arbitrary filters in fatal handling)
			return 'ajax';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return defined( 'WP_ADMIN' ) && WP_ADMIN ? 'admin' : ( 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) ? 'login' : 'web' ); // @phpstan-ignore phpstanWP.wpConstant.fetch (avoid running arbitrary filters in fatal handling)
	}
}
