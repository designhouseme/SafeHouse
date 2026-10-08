<?php
/**
 * Bounded, read-only observations of third-party schedulers.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use Throwable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- bounded diagnostics must avoid hydrating unbounded scheduler options and payloads.
final class StabilityDiagnostics {

	private const OPTION     = 'shouse_stability_diagnostics';
	private const CRON_BYTES = 1048576;
	private const LIMIT      = 1000;

	/**
	 * Cheap UI read; collecting is reserved for explicit actions and scheduled work.
	 * @return array<string, mixed>
	 */
	public static function cached(): array {
		$raw = get_option( self::OPTION, '' );
		if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) {
			return [];
		}
		$data = json_decode( $raw, true, 16 );
		return is_array( $data ) ? $data : [];
	}

	/** @return array<string, mixed> */
	public static function collect(): array {
		global $wpdb;
		$budget   = new WorkBudget( 1.0, 0.8 );
		$previous = self::cached();
		$now      = time();
		$errors   = $wpdb->suppress_errors( true );
		try {
			$cron    = self::cron( $budget, $now );
			$actions = self::actions( $budget, $now );
		} finally {
			$wpdb->suppress_errors( $errors );
		}
		$cron['trend']    = self::trend( $previous, $cron, 'cron', $now );
		$actions['trend'] = self::trend( $previous, $actions, 'actions', $now );
		$warnings         = [];
		foreach ( [
			'cron'    => $cron,
			'actions' => $actions,
		] as $name => $part ) {
			if ( ! in_array( $part['status'], [ 'ok', 'missing' ], true ) ) {
				$warnings[] = $name . '_' . $part['status'];
			}
			if ( ! $part['exact'] ) {
				$warnings[] = $name . '_partial';
			}
		}
		$snapshot = [
			'collected_at' => $now,
			'complete'     => [] === $warnings,
			'storage'      => 'saved',
			'cron'         => $cron,
			'actions'      => $actions,
			'warnings'     => $warnings,
		];
		$json     = wp_json_encode( $snapshot );
		if ( false === $json || ( ! update_option( self::OPTION, $json, false ) && get_option( self::OPTION ) !== $json ) ) {
			$snapshot['storage']    = 'unavailable';
			$snapshot['complete']   = false;
			$snapshot['warnings'][] = 'snapshot_storage_unavailable';
		}
		return $snapshot;
	}

	/** @return array<string, mixed> */
	private static function cron( WorkBudget $budget, int $now ): array {
		global $wpdb;
		$heartbeat = get_option( 'shouse_stability_heartbeat', 0 );
		$result    = [
			'status'            => 'budget_exhausted',
			'event_count'       => 0,
			'overdue_count'     => 0,
			'exact'             => false,
			'truncated'         => false,
			'limit'             => self::LIMIT,
			'oldest_overdue_at' => null,
			'top_hooks'         => [],
			'disabled'          => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'heartbeat_at'      => is_numeric( $heartbeat ) && (int) $heartbeat > 0 ? (int) $heartbeat : null,
			'serialized_bytes'  => null,
		];
		if ( $budget->exhausted() ) {
			return $result;
		}
		// One statement prevents a size/read race; oversized values never leave the database.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT LENGTH(option_value) AS bytes, CASE WHEN LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS value FROM %i WHERE option_name = %s LIMIT 1', self::CRON_BYTES, $wpdb->options, 'cron' ), ARRAY_A );
		if ( self::query_failed() ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		if ( ! is_array( $row ) ) {
			$result['status'] = 'missing';
			$result['exact']  = true;
			return $result;
		}
		$result['serialized_bytes'] = (int) $row['bytes'];
		if ( (int) $row['bytes'] > self::CRON_BYTES ) {
			$result['status'] = 'oversized';
			return $result;
		}
		if ( $budget->exhausted() ) {
			return $result;
		}
		$memory_limit = WorkBudget::memory_limit();
		if ( $memory_limit > 0 && (int) $row['bytes'] * 32 > max( 0, $memory_limit * 0.8 - memory_get_usage( true ) ) ) {
			// Serialized arrays expand in memory; leave generous headroom before decoding.
			return $result;
		}
		// Never instantiate objects embedded in another plugin's cron arguments.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- size/depth limited and objects disabled; malformed data is reported below.
		$data = @unserialize(
			(string) $row['value'],
			[
				'allowed_classes' => false,
				'max_depth'       => 16,
			]
		);
		if ( ! is_array( $data ) ) {
			$result['status'] = 'invalid';
			return $result;
		}
		$result['status'] = 'ok';
		$result['exact']  = true;
		$counts           = [];
		$visits           = 0;
		foreach ( $data as $timestamp => $hooks ) {
			if ( 'version' === $timestamp ) {
				continue;
			}
			if ( self::visit_exhausted( $budget, $visits ) ) {
				$result['truncated'] = true;
				break;
			}
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
				$result['status'] = 'invalid';
				break;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( self::visit_exhausted( $budget, $visits ) ) {
					$result['truncated'] = true;
					break 2;
				}
				if ( ! is_array( $events ) ) {
					$result['status'] = 'invalid';
					break 2;
				}
				foreach ( $events as $event ) {
					if ( self::visit_exhausted( $budget, $visits ) || $result['event_count'] >= self::LIMIT ) {
						$result['truncated'] = true;
						break 3;
					}
					if ( ! is_array( $event ) || ! array_key_exists( 'schedule', $event ) || ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
						$result['status'] = 'invalid';
						break 3;
					}
					++$result['event_count'];
					$name            = self::hook( (string) $hook );
					$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
					if ( (int) $timestamp > 0 && (int) $timestamp < $now ) {
						++$result['overdue_count'];
						$result['oldest_overdue_at'] = null === $result['oldest_overdue_at'] ? (int) $timestamp : min( $result['oldest_overdue_at'], (int) $timestamp );
					}
				}
			}
		}
		$result['exact']     = 'ok' === $result['status'] && ! $result['truncated'];
		$result['top_hooks'] = self::top_hooks( $counts );
		return $result;
	}

	/**
	 * The public query API hydrates action objects/arguments for dates and hooks.
	 * Use only the known DB store and verified index prefixes; custom stores remain explicitly unsupported.
	 * Schema: https://github.com/woocommerce/action-scheduler/blob/trunk/classes/schema/ActionScheduler_StoreSchema.php
	 * @return array<string, mixed>
	 */
	private static function actions( WorkBudget $budget, int $now ): array {
		global $wpdb;
		$result = [
			'status'            => 'missing',
			'exact'             => true,
			'truncated'         => false,
			'limit'             => self::LIMIT,
			'pending'           => null,
			'running'           => null,
			'failed'            => null,
			'oldest_overdue_at' => null,
			'oldest_claim_at'   => null,
			'top_hooks'         => [],
		];
		if ( ! class_exists( 'ActionScheduler', false ) ) {
			return $result;
		}
		$result['status'] = 'unsupported';
		$result['exact']  = false;
		if ( ! did_action( 'action_scheduler_init' ) || ! is_callable( [ 'ActionScheduler', 'store' ] ) ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		try {
			$store = call_user_func( [ 'ActionScheduler', 'store' ] );
		} catch ( Throwable $error ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		if ( ! is_object( $store ) || 0 !== strcasecmp( 'ActionScheduler_DBStore', get_class( $store ) ) ) {
			return $result;
		}
		$result['status'] = 'budget_exhausted';
		if ( $budget->exhausted() ) {
			return $result;
		}
		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$claims = $wpdb->prefix . 'actionscheduler_claims';
		$index  = self::index( $table, [ 'status', 'scheduled_date_gmt' ] );
		if ( null === $index ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		$counts = [];
		foreach ( [
			'pending' => 'pending',
			'running' => 'in-progress',
			'failed'  => 'failed',
		] as $key => $status ) {
			if ( $budget->exhausted() ) {
				return $result;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT LEFT(hook, 120) AS hook, scheduled_date_gmt FROM %i FORCE INDEX (%i) WHERE status = %s ORDER BY scheduled_date_gmt ASC LIMIT %d', $table, $index, $status, self::LIMIT + 1 ), ARRAY_A );
			if ( self::query_failed() || ! is_array( $rows ) ) {
				$result['status'] = 'unavailable';
				return $result;
			}
			$truncated           = count( $rows ) > self::LIMIT;
			$result[ $key ]      = [
				'count'     => min( count( $rows ), self::LIMIT ),
				'exact'     => ! $truncated,
				'truncated' => $truncated,
				'limit'     => self::LIMIT,
			];
			$result['truncated'] = $result['truncated'] || $truncated;
			if ( 'pending' === $key ) {
				foreach ( array_slice( $rows, 0, self::LIMIT ) as $row ) {
					$name            = self::hook( (string) $row['hook'] );
					$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
				}
				$result['top_hooks'] = self::top_hooks( $counts );
			}
		}
		if ( $budget->exhausted() ) {
			return $result;
		}
		$oldest = $wpdb->get_var( $wpdb->prepare( 'SELECT scheduled_date_gmt FROM %i FORCE INDEX (%i) WHERE status = %s AND scheduled_date_gmt > %s AND scheduled_date_gmt < %s ORDER BY scheduled_date_gmt ASC LIMIT 1', $table, $index, 'pending', '0000-00-00 00:00:00', gmdate( 'Y-m-d H:i:s', $now ) ) );
		if ( self::query_failed() ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		$result['oldest_overdue_at'] = self::timestamp( $oldest );
		if ( $budget->exhausted() ) {
			return $result;
		}
		$claim_index = self::index( $claims, [ 'date_created_gmt' ] );
		if ( null === $claim_index ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		if ( $budget->exhausted() ) {
			return $result;
		}
		$oldest = $wpdb->get_var( $wpdb->prepare( 'SELECT date_created_gmt FROM %i FORCE INDEX (%i) WHERE date_created_gmt > %s ORDER BY date_created_gmt ASC LIMIT 1', $claims, $claim_index, '0000-00-00 00:00:00' ) );
		if ( self::query_failed() ) {
			$result['status'] = 'unavailable';
			return $result;
		}
		$result['oldest_claim_at'] = self::timestamp( $oldest );
		$result['status']          = 'ok';
		$result['exact']           = ! $result['truncated'];
		return $result;
	}

	/** @param string[] $columns Required leading, unprefixed index columns. */
	private static function index( string $table, array $columns ): ?string {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		if ( self::query_failed() || ! is_array( $rows ) || count( $rows ) > 100 ) {
			return null;
		}
		$indexes = [];
		foreach ( $rows as $row ) {
			if ( null === $row['Sub_part'] ) {
				$indexes[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
		}
		foreach ( $indexes as $name => $parts ) {
			$matches = true;
			foreach ( $columns as $offset => $column ) {
				if ( ( $parts[ $offset + 1 ] ?? '' ) !== $column ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				return $name;
			}
		}
		return null;
	}

	private static function hook( string $name ): string {
		return substr( (string) preg_replace( '/[^a-zA-Z0-9_\\\\.\/:\-]/', '', $name ), 0, 120 );
	}

	/**
	 * @param array<string, int> $counts Observed hook counts.
	 * @return array<int, array{hook: string, count: int}>
	 */
	private static function top_hooks( array $counts ): array {
		arsort( $counts );
		$rows = [];
		foreach ( array_slice( $counts, 0, 10, true ) as $hook => $count ) {
			$rows[] = [
				'hook'  => $hook,
				'count' => $count,
			];
		}
		return $rows;
	}

	private static function timestamp( ?string $date ): ?int {
		$timestamp = null !== $date ? strtotime( $date . ' UTC' ) : false;
		return false !== $timestamp && $timestamp > 0 ? $timestamp : null;
	}

	/** @phpstan-impure */
	private static function visit_exhausted( WorkBudget $budget, int &$visits ): bool {
		++$visits;
		return $visits > 4000 || $budget->exhausted();
	}

	/** @phpstan-impure */
	private static function query_failed(): bool {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}

	/**
	 * @param array<string, mixed> $previous Last sample.
	 * @param array<string, mixed> $current Current component.
	 * @return array<string, int|string>|null
	 */
	private static function trend( array $previous, array $current, string $name, int $now ): ?array {
		$old = $previous[ $name ] ?? null;
		if ( ! is_array( $old ) || empty( $old['exact'] ) || empty( $current['exact'] ) || 'ok' !== ( $old['status'] ?? '' ) || 'ok' !== $current['status'] || ( $old['limit'] ?? 0 ) !== self::LIMIT || $now <= (int) ( $previous['collected_at'] ?? 0 ) ) {
			return null;
		}
		$before = 'cron' === $name ? $old['event_count'] : $old['pending']['count'];
		$after  = 'cron' === $name ? $current['event_count'] : $current['pending']['count'];
		$delta  = (int) $after - (int) $before;
		return [
			'direction'       => 0 === $delta ? 'unchanged' : ( $delta > 0 ? 'growing' : 'shrinking' ),
			'delta'           => $delta,
			'elapsed_seconds' => $now - (int) $previous['collected_at'],
		];
	}
}
