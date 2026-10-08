<?php
/**
 * Cooperative limits for SafeHouse work. Check between units; this cannot interrupt a callback.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class WorkBudget {

	private float $deadline;
	private int $memory_ceiling;

	public function __construct( float $seconds = 5.0, float $memory_fraction = 0.8 ) {
		$this->deadline       = self::clock() + max( 0.0, $seconds );
		$limit                = self::memory_limit();
		$this->memory_ceiling = $limit > 0 ? (int) ( $limit * max( 0.1, min( 0.95, $memory_fraction ) ) ) : 0;
	}

	/** @phpstan-impure */
	public function exhausted(): bool {
		return self::clock() >= $this->deadline || ( $this->memory_ceiling > 0 && memory_get_usage( true ) >= $this->memory_ceiling );
	}

	public static function clock(): float {
		return hrtime( true ) / 1e9;
	}

	/** Zero means unlimited or unavailable; do not invent a host resource limit. */
	public static function memory_limit(): int {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( ! preg_match( '/^(\d+)\s*([KMG]?)$/i', $value, $match ) ) {
			return 0;
		}
		$power = array_search( strtoupper( $match[2] ), [ '', 'K', 'M', 'G' ], true );
		return (int) min( PHP_INT_MAX, (int) $match[1] * ( 1024 ** ( false === $power ? 0 : $power ) ) );
	}
}
