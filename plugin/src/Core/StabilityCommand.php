<?php
/**
 * Operator diagnostics without exposing HTTP payloads, query strings or queue arguments.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class StabilityCommand {

	/** Show the last bounded scheduler check and recorded request observations as JSON. */
	public function status(): void {
		$incidents = RuntimeMonitor::recent();
		WP_CLI::line(
			(string) wp_json_encode(
				[
					'diagnostics'                => StabilityDiagnostics::cached(),
					'incidents'                  => $incidents,
					'incident_storage_available' => RuntimeMonitor::storage_available(),
					'queue'                      => Queue::status(),
					'session_until'              => (int) get_option( 'shouse_stability_session', 0 ),
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/** Collect a new bounded scheduler snapshot; this does not execute scheduled work. */
	public function scan(): void {
		WP_CLI::line( (string) wp_json_encode( StabilityDiagnostics::collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Start or stop a 15-minute diagnostic sampling session.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function sample( array $args ): void {
		if ( 'on' === $args[0] ) {
			if ( ! \SafeHouse\Plugin::instance()->is_running( 'stability' ) ) {
				WP_CLI::error( 'Enable Stability and leave SafeHouse safe mode before starting a sampling session.' );
			}
			$until = time() + 15 * MINUTE_IN_SECONDS;
			if ( ! update_option( 'shouse_stability_session', $until, false ) && $until !== (int) get_option( 'shouse_stability_session', 0 ) ) {
				WP_CLI::error( 'The sampling session could not be saved. Check database access.' );
			}
			WP_CLI::success( 'Detailed request sampling enabled for 15 minutes. Capture is sampled and bounded.' );
		} else {
			delete_option( 'shouse_stability_session' );
			if ( (int) get_option( 'shouse_stability_session', 0 ) > time() ) {
				WP_CLI::error( 'Detailed sampling could not be stopped. Check database access.' );
			}
			WP_CLI::success( 'Detailed request sampling stopped.' );
		}
	}
}
