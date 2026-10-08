<?php
/**
 * Local incident observations and read-only background-work diagnostics.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use SafeHouse\Core\AbstractModule;
use SafeHouse\Core\Log;
use SafeHouse\Core\Notify;
use SafeHouse\Core\Queue;
use SafeHouse\Core\RuntimeMonitor;
use SafeHouse\Core\Settings;
use SafeHouse\Core\StabilityDiagnostics;

defined( 'ABSPATH' ) || exit;

final class Stability extends AbstractModule {

	public function id(): string {
		return 'stability';
	}

	public function default_enabled(): bool {
		return true;
	}

	public function label(): string {
		return __( 'Stability', 'shouse' );
	}

	public function description(): string {
		return __( 'Records slow requests and PHP failures locally, and checks background queues. Observations help investigate a cause; they never automatically disable another plugin or cancel its work.', 'shouse' );
	}

	public function defaults(): array {
		return [ 'alerts' => true ];
	}

	public function fields(): array {
		return [
			'alerts' => [
				'type'  => 'toggle',
				'label' => __( 'Email about recorded PHP failures', 'shouse' ),
				'help'  => __( 'One summary per hour at most, delivered by the SafeHouse queue when cron runs.', 'shouse' ),
			],
		];
	}

	public function boot(): void {
		RuntimeMonitor::install();
		RuntimeMonitor::start();
		add_action( 'shouse_hourly', [ $this, 'scheduled_scan' ] );
		add_action( 'shouse_daily', [ RuntimeMonitor::class, 'purge' ] );
		add_filter( 'site_status_tests', [ $this, 'health_tests' ] );
	}

	public function scheduled_scan(): void {
		update_option( 'shouse_stability_heartbeat', time(), false );
		StabilityDiagnostics::collect();
		if ( ! $this->opt( 'alerts' ) ) {
			return;
		}
		$last = (int) get_option( 'shouse_stability_alerted', 0 );
		if ( $last > time() - HOUR_IN_SECONDS ) {
			return;
		}
		$failures = array_filter( RuntimeMonitor::recent( 100 ), static fn( $row ) => in_array( $row['kind'], [ 'fatal', 'memory_exhausted', 'time_limit' ], true ) && (int) $row['last_seen'] > $last );
		if ( $failures && Queue::reserve( 'stability-alert', 60 ) && Notify::send( 'PHP failures recorded', [ 'SafeHouse recorded PHP failures. Open Stability to inspect their time, request context and error location.', 'An error location does not prove which plugin caused the failure. Observations are sampled and incomplete; use hosting logs for failures before SafeHouse loaded or after a process was killed.' ] ) ) {
			update_option( 'shouse_stability_alerted', time(), false );
		}
	}

	public function tasks(): array {
		$tasks = [ 'scan' => __( 'Check background queues', 'shouse' ) ];
		if ( (int) get_option( 'shouse_stability_session', 0 ) > time() ) {
			$tasks['stop'] = __( 'Stop detailed sampling', 'shouse' );
		} else {
			$tasks['sample'] = __( 'Sample requests for 15 minutes', 'shouse' );
		}
		$tasks['resume'] = __( 'Resume up to 20 paused SafeHouse jobs', 'shouse' );
		return $tasks;
	}

	public function handle_task( string $task ): string {
		if ( Settings::locked() ) {
			return __( 'Settings are locked in wp-config.php.', 'shouse' );
		}
		if ( 'scan' === $task ) {
			$result = StabilityDiagnostics::collect();
			return 'saved' === $result['storage'] ? __( 'Background queue observations updated. Partial results are labelled in the panel.', 'shouse' ) : __( 'The observations could not be saved. Check database access.', 'shouse' );
		}
		if ( 'sample' === $task ) {
			$until = time() + 15 * MINUTE_IN_SECONDS;
			if ( ! update_option( 'shouse_stability_session', $until, false ) && $until !== (int) get_option( 'shouse_stability_session', 0 ) ) {
				return __( 'The sampling session could not be saved. Check database access.', 'shouse' );
			}
			return __( 'Detailed sampling starts with subsequent requests and expires automatically after 15 minutes.', 'shouse' );
		}
		if ( 'stop' === $task ) {
			delete_option( 'shouse_stability_session' );
			if ( (int) get_option( 'shouse_stability_session', 0 ) > time() ) {
				return __( 'Detailed sampling could not be stopped. Check database access.', 'shouse' );
			}
			return __( 'Detailed sampling stopped. Basic incident recording remains active.', 'shouse' );
		}
		if ( 'resume' === $task ) {
			$count = Queue::resume( 20 );
			Log::add( 'queue_resumed', 'Paused SafeHouse jobs resumed by an administrator', [ 'count' => $count ] );
			/* translators: %d: number of retained delivery jobs. */
			return sprintf( __( '%d retained jobs resumed. Delivery runs separately; check the cause of failure before resuming more.', 'shouse' ), $count );
		}
		return '';
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
		if ( ! $is_enabled ) {
			delete_option( 'shouse_stability_session' );
		}
	}

	/**
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function health_tests( array $tests ): array {
		$tests['direct']['shouse_stability'] = [
			'label' => __( 'SafeHouse stability observations', 'shouse' ),
			'test'  => [ $this, 'health' ],
		];
		return $tests;
	}

	/** @return array<string, mixed> */
	public function health(): array {
		$data  = StabilityDiagnostics::cached();
		$stale = empty( $data['collected_at'] ) || (int) $data['collected_at'] < time() - 2 * HOUR_IN_SECONDS;
		$bad   = $stale || empty( $data['complete'] );
		foreach ( [ 'cron', 'actions' ] as $key ) {
			$oldest = (int) ( $data[ $key ]['oldest_overdue_at'] ?? 0 );
			$bad    = $bad || ( $oldest > 0 && $oldest < time() - HOUR_IN_SECONDS );
		}
		$claim = (int) ( $data['actions']['oldest_claim_at'] ?? 0 );
		$bad   = $bad || (int) ( $data['actions']['failed']['count'] ?? 0 ) > 0 || ( $claim > 0 && $claim < time() - HOUR_IN_SECONDS );
		foreach ( RuntimeMonitor::recent() as $row ) {
			$bad = $bad || ( in_array( $row['kind'], [ 'fatal', 'memory_exhausted', 'time_limit' ], true ) && (int) $row['last_seen'] > time() - DAY_IN_SECONDS );
		}
		$bad = $bad || ! RuntimeMonitor::storage_available();
		return [
			'label'       => $bad ? __( 'Review the stability observations', 'shouse' ) : __( 'No substantial delay found in the latest bounded check', 'shouse' ),
			'status'      => $bad ? 'recommended' : 'good',
			'badge'       => [
				'label' => __( 'Performance', 'shouse' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Observations can be incomplete. A clean sample does not rule out a failure. Check collection time, queue age and hosting logs.', 'shouse' ) . '</p>',
			'actions'     => '<a href="' . esc_url( \SafeHouse\Plugin::settings_url( 'stability' ) ) . '">' . esc_html__( 'Open Stability', 'shouse' ) . '</a>',
			'test'        => 'shouse_stability',
		];
	}

	public function render_panel(): void {
		$data    = StabilityDiagnostics::cached();
		$session = (int) get_option( 'shouse_stability_session', 0 );
		echo '<div class="shouse-stability">';
		if ( $session > time() ) {
			/* translators: %s: local date and time. */
			echo '<p><strong>' . esc_html( sprintf( __( 'Detailed sampling is active until %s.', 'shouse' ), wp_date( 'H:i', $session ) ) ) . '</strong></p>';
		}
		echo '<p class="description">' . esc_html__( 'Basic recording observes requests over 3 seconds, high memory use and PHP failures after SafeHouse loads. Detailed sampling adds HTTP timing for about 10% of requests, up to 20 calls per sampled request. At most one observation is saved per 10 seconds site-wide. Storage is capped at 256 groups; the panel shows the last 7 days and scheduled cleanup removes older records. Counts are recorded samples, not traffic totals.', 'shouse' ) . '</p>';
		echo '<h3>' . esc_html__( 'Background work', 'shouse' ) . '</h3>';
		if ( ! $data ) {
			echo '<p>' . esc_html__( 'No background queue observations yet. Run a check below; scheduled checks then run hourly.', 'shouse' ) . '</p>';
		} else {
			/* translators: %s: local date and time. */
			echo '<p>' . esc_html( sprintf( __( 'Last checked: %s.', 'shouse' ), wp_date( 'Y-m-d H:i', (int) $data['collected_at'] ) ) ) . '</p>';
			if ( (int) $data['collected_at'] < time() - 2 * HOUR_IN_SECONDS ) {
				echo '<p><strong>' . esc_html__( 'These observations are out of date. Run a new check and verify the site cron runner.', 'shouse' ) . '</strong></p>';
			}
			$this->render_scheduler( 'WP-Cron', (array) ( $data['cron'] ?? [] ), true );
			$this->render_scheduler( 'Action Scheduler', (array) ( $data['actions'] ?? [] ), false );
			if ( ! empty( $data['cron']['disabled'] ) ) {
				echo '<p class="description">' . esc_html__( 'Visitor-triggered WP-Cron is disabled. This can be intentional when a hosting cron runner is configured.', 'shouse' ) . '</p>';
			}
			$heartbeat = (int) ( $data['cron']['heartbeat_at'] ?? 0 );
			/* translators: %s: local date and time, or an unknown-state label. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last scheduled stability check: %s.', 'shouse' ), $heartbeat ? wp_date( 'Y-m-d H:i', $heartbeat ) : __( 'not observed', 'shouse' ) ) ) . '</p>';
		}
		$queue = Queue::status();
		/* translators: 1: pending jobs, 2: jobs with failed attempts, 3: paused jobs. */
		echo '<p>' . esc_html( sprintf( __( 'SafeHouse delivery: %1$d pending, %2$d with failed attempts, %3$d paused.', 'shouse' ), $queue['pending'], $queue['failed'], $queue['paused'] ) ) . '</p>';
		if ( ! $queue['storage_available'] ) {
			echo '<p><strong>' . esc_html__( 'SafeHouse queue storage is unavailable. The displayed counts cannot confirm that the queue is empty.', 'shouse' ) . '</strong></p>';
		}
		if ( $queue['paused'] > 0 ) {
			echo '<p>' . esc_html__( 'Repeated failures are retained for review. Fix the mail transport or cache integration before resuming jobs. Third-party queues are never changed here.', 'shouse' ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Recent observations', 'shouse' ) . '</h3>';
		$rows = RuntimeMonitor::recent();
		if ( ! RuntimeMonitor::storage_available() ) {
			echo '<p><strong>' . esc_html__( 'Incident storage is unavailable. Check database access; no clean result can be confirmed.', 'shouse' ) . '</strong></p>';
		} elseif ( ! $rows ) {
			echo '<p>' . esc_html__( 'No recent observations were recorded. This does not confirm that every request completed successfully.', 'shouse' ) . '</p>';
		} else {
			echo '<div class="shouse-stability__table"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recent stability observations', 'shouse' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Last seen', 'shouse' ) . '</th><th scope="col">' . esc_html__( 'Observation', 'shouse' ) . '</th><th scope="col">' . esc_html__( 'Context / location', 'shouse' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( wp_date( 'm-d H:i', (int) $row['last_seen'] ) ) . '</td><td>' . esc_html( self::kind_label( (string) $row['kind'] ) );
				/* translators: 1: observed duration in seconds, 2: peak memory, 3: saved observations. */
				echo '<br><small>' . esc_html( sprintf( __( '%1$s s; %2$s peak; %3$d samples', 'shouse' ), number_format_i18n( (int) $row['elapsed_ms'] / 1000, 2 ), size_format( (int) $row['peak_bytes'] ), (int) $row['observations'] ) ) . '</small>';
				if ( (int) $row['http_count'] > 0 ) {
					/* translators: 1: measured HTTP calls, 2: measured duration in seconds. */
					echo '<br><small>' . esc_html( sprintf( __( '%1$d HTTP calls; %2$s s measured', 'shouse' ), (int) $row['http_count'], number_format_i18n( (int) $row['http_ms'] / 1000, 2 ) ) ) . '</small>';
				}
				echo '</td><td>' . esc_html( self::context_label( (string) $row['context'] ) ) . '<br><code>' . esc_html( $row['component'] ? (string) $row['component'] : __( 'undetermined', 'shouse' ) ) . '</code></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '<p class="description">' . esc_html__( 'Location identifies where an error surfaced or a slow HTTP call originated; it is not proof of the cause. Recovery Mode and hosting logs remain essential for failures before loading, exhausted workers and killed processes.', 'shouse' ) . '</p></div>';
	}

	/** @param array<string, mixed> $data Bounded scheduler snapshot. */
	private function render_scheduler( string $label, array $data, bool $cron ): void {
		echo '<p><strong>' . esc_html( $label ) . '</strong>: ';
		if ( 'missing' === ( $data['status'] ?? '' ) ) {
			echo esc_html__( 'not present', 'shouse' ) . '</p>';
			return;
		}
		if ( 'ok' !== ( $data['status'] ?? '' ) ) {
			$message = match ( $data['status'] ?? '' ) {
				'oversized' => __( 'cron data exceeds the 1 MiB inspection limit; inspect it through the hosting tools', 'shouse' ),
				'unsupported' => __( 'this scheduler storage is not supported by the bounded check', 'shouse' ),
				'budget_exhausted' => __( 'the inspection reached its time or memory limit; inspect the scheduler through the hosting tools', 'shouse' ),
				default => __( 'a complete check was not possible; inspect the scheduler and hosting logs', 'shouse' ),
			};
			echo esc_html( $message ) . '</p>';
			return;
		}
		$count = $cron ? (int) ( $data['event_count'] ?? 0 ) : (int) ( $data['pending']['count'] ?? 0 );
		/* translators: %d: observed scheduled events or pending actions. */
		echo esc_html( sprintf( __( '%d scheduled or pending items observed', 'shouse' ), $count ) );
		if ( empty( $data['exact'] ) ) {
			echo ' — ' . esc_html__( 'partial check; totals may be higher', 'shouse' );
		}
		echo '.</p>';
		if ( $cron ) {
			/* translators: %d: number of overdue events in the bounded observation. */
			echo '<p>' . esc_html( sprintf( __( '%d overdue events observed.', 'shouse' ), (int) ( $data['overdue_count'] ?? 0 ) ) ) . '</p>';
		} else {
			/* translators: 1: running actions, 2: failed actions in the bounded observation. */
			echo '<p>' . esc_html( sprintf( __( '%1$d running and %2$d failed actions observed.', 'shouse' ), (int) ( $data['running']['count'] ?? 0 ), (int) ( $data['failed']['count'] ?? 0 ) ) ) . '</p>';
			$claim = (int) ( $data['oldest_claim_at'] ?? 0 );
			if ( $claim ) {
				/* translators: %s: age of the oldest observed Action Scheduler claim. */
				echo '<p>' . esc_html( sprintf( __( 'Oldest worker claim: %s ago. Its age alone does not confirm a stuck job.', 'shouse' ), human_time_diff( $claim ) ) ) . '</p>';
			}
		}
		$oldest = (int) ( $data['oldest_overdue_at'] ?? 0 );
		if ( $oldest ) {
			/* translators: %s: age of the oldest observed overdue task. */
			echo '<p>' . esc_html( sprintf( __( 'Oldest observed overdue item: %s ago.', 'shouse' ), human_time_diff( $oldest ) ) ) . '</p>';
		}
		$hooks = (array) ( $data['top_hooks'] ?? [] );
		if ( $hooks ) {
			echo '<ul>';
			foreach ( array_slice( $hooks, 0, 5 ) as $hook ) {
				echo '<li><code>' . esc_html( (string) $hook['hook'] ) . '</code>: ' . esc_html( (string) $hook['count'] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	private static function context_label( string $context ): string {
		return match ( $context ) {
			'admin' => __( 'Administration', 'shouse' ),
			'login' => __( 'Login', 'shouse' ),
			'cron' => __( 'Scheduled task', 'shouse' ),
			'cli' => __( 'Command line', 'shouse' ),
			'ajax' => 'AJAX',
			'rest' => 'REST API',
			default => __( 'Website request', 'shouse' ),
		};
	}

	public static function kind_label( string $kind ): string {
		return match ( $kind ) {
			'fatal' => __( 'PHP failure', 'shouse' ),
			'memory_exhausted' => __( 'Memory exhausted', 'shouse' ),
			'time_limit' => __( 'Execution time limit', 'shouse' ),
			'memory_pressure' => __( 'High memory use', 'shouse' ),
			'slow' => __( 'Slow request', 'shouse' ),
			default => __( 'Diagnostic sample', 'shouse' ),
		};
	}
}
