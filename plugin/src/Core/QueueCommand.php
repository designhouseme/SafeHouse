<?php
/**
 * Explicit operator controls for retained SafeHouse work, also available in safe mode.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class QueueCommand {

	/**
	 * Show delivery backlog and paused jobs, without exposing recipients or message bodies.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default: table.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', [ Queue::status() ], [ 'pending', 'failed', 'paused', 'attempts', 'oldest', 'storage_available' ] );
	}

	/**
	 * Run one bounded worker batch. This does not resume paused jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Total jobs to consider, 1–20. Default: 10.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		Queue::run( '', self::limit( $assoc_args, 10 ) );
		$this->status( [], [] );
	}

	/**
	 * Resume a bounded batch after correcting the cause of delivery failure.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Jobs to resume, 1–20. Default: 20. Disabled handlers are left paused.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function resume( array $args, array $assoc_args ): void {
		$count = Queue::resume( self::limit( $assoc_args, 20 ) );
		Log::add( 'queue_resumed', 'Paused delivery jobs resumed explicitly through WP-CLI', [ 'count' => $count ] );
		WP_CLI::success( sprintf( '%d retained jobs resumed. Delivery runs separately.', $count ) );
	}

	/** @param array<string, string> $assoc_args Named arguments. */
	private static function limit( array $assoc_args, int $fallback ): int {
		$value = filter_var(
			$assoc_args['limit'] ?? (string) $fallback,
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 1,
					'max_range' => 20,
				],
			]
		);
		if ( false === $value ) {
			WP_CLI::error( 'Limit must be an integer from 1 to 20.' );
		}
		return (int) $value;
	}
}
