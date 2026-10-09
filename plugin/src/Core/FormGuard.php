<?php
/**
 * Bounded, atomic form proof storage and rate accounting.
 *
 * Fixed slot ranges cap storage without relying on cron, cache eviction or attacker-chosen
 * row keys. Active rate slots are never evicted; a unique owner index prevents concurrent
 * requests from allocating duplicate counters. A distributed flood can still exhaust capacity.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- conditional writes to bounded security state cannot use the object cache.
final class FormGuard {

	private const VERSION     = '2';
	private const OPTION      = 'shouse_form_guard_db_version';
	private const FORMS       = [
		'register'     => 1,
		'lostpassword' => 2,
		'comments'     => 3,
	];
	private const TOKEN_SLOTS = 16384;
	private const RATE_SLOTS  = 32768;
	private const ATTEMPTS    = 8;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shouse_form_guard';
	}

	/** One schema attempt per minute, including concurrent public requests after an upgrade. */
	public static function maybe_install(): bool {
		if ( self::installed() ) {
			return true;
		}
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$option = 'shouse_form_guard_install_after';
			$now    = time();
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, '0', 'off')", $wpdb->options, $option ) ) ) {
				return false;
			}
			$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d', $wpdb->options, (string) ( $now + MINUTE_IN_SECONDS ), $option, $now ) );
			return 1 === $claimed ? self::install() : self::installed();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Called by module lifecycle, never by a failed proof or an unavailable-store retry. */
	public static function install(): bool {
		if ( self::installed() ) {
			return true;
		}
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$table = self::table();
			dbDelta(
				"CREATE TABLE {$table} (
					pool tinyint unsigned NOT NULL,
					kind tinyint unsigned NOT NULL,
					slot int unsigned NOT NULL,
					fingerprint char(64) DEFAULT NULL,
					expires_at bigint unsigned NOT NULL DEFAULT 0,
					hits int unsigned NOT NULL DEFAULT 0,
					PRIMARY KEY  (pool,kind,slot)
				) {$wpdb->get_charset_collate()};"
			);
			if ( self::has_error() ) {
				return false;
			}
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
			if ( self::has_error() || array_diff( [ 'pool', 'kind', 'slot', 'fingerprint', 'expires_at', 'hits' ], $columns ) ) {
				return false;
			}
			$primary = $wpdb->get_col( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'PRIMARY'", $table ), 4 );
			if ( self::has_error() || [ 'pool', 'kind', 'slot' ] !== $primary ) {
				return false;
			}
			// dbDelta does not reliably alter nullability when the underlying column type is unchanged.
			$definition = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM %i WHERE Field = 'fingerprint'", $table ), ARRAY_A );
			if ( self::has_error() || ! is_array( $definition ) ) {
				return false;
			}
			if ( 'YES' !== $definition['Null'] && false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY fingerprint char(64) DEFAULT NULL', $table ) ) ) {
				return false;
			}
			// Legacy empty slots used ''. NULL permits many free slots under the owner constraint.
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE %i SET fingerprint = NULL WHERE fingerprint = ''", $table ) ) ) {
				return false;
			}
			$owner = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'owner'", $table ), ARRAY_A );
			if ( self::has_error() ) {
				return false;
			}
			if ( ! $owner ) {
				if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY owner (pool,kind,fingerprint)', $table ) ) ) {
					return false;
				}
				$owner = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'owner'", $table ), ARRAY_A );
			}
			if ( self::has_error() || ! is_array( $owner ) || [ 'pool', 'kind', 'fingerprint' ] !== array_column( $owner, 'Column_name' ) || array_filter( array_column( $owner, 'Non_unique' ), static fn( mixed $value ): bool => 0 !== (int) $value ) ) {
				return false;
			}
			update_option( self::OPTION, self::VERSION, false );
			return self::installed();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * The caller supplies a fresh, unique proof fingerprint and signs the returned slot into it.
	 * At most eight candidates are attempted; issued proofs are never displaced before expiry.
	 *
	 * @return array{slot: ?int, reason: string}
	 */
	public static function issue( string $form, string $fingerprint, int $expires ): array {
		$now = time();
		if ( ! isset( self::FORMS[ $form ] ) || ! self::fingerprint( $fingerprint ) || $expires <= $now || $expires > $now + HOUR_IN_SECONDS ) {
			return [
				'slot'   => null,
				'reason' => 'context',
			];
		}
		if ( ! self::installed() ) {
			return [
				'slot'   => null,
				'reason' => 'storage',
			];
		}
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			for ( $attempt = 0; $attempt < self::ATTEMPTS; ++$attempt ) {
				$slot = random_int( 0, self::TOKEN_SLOTS - 1 );
				if ( ! self::ensure_slot( $form, 1, $slot ) ) {
					return [
						'slot'   => null,
						'reason' => 'storage',
					];
				}
				$changed = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET fingerprint = %s, expires_at = %d WHERE pool = %d AND kind = 1 AND slot = %d AND expires_at <= %d',
						self::table(),
						$fingerprint,
						$expires,
						self::FORMS[ $form ],
						$slot,
						$now
					)
				);
				if ( false === $changed ) {
					return [
						'slot'   => null,
						'reason' => 'storage',
					];
				}
				if ( 1 === $changed ) {
					return [
						'slot'   => $slot,
						'reason' => '',
					];
				}
			}
			return [
				'slot'   => null,
				'reason' => 'capacity',
			];
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Consume only after the caller checks the signature, browser, context, age and empty trap. */
	public static function consume( string $form, int $slot, string $fingerprint ): string {
		if ( ! isset( self::FORMS[ $form ] ) || $slot < 0 || $slot >= self::TOKEN_SLOTS || ! self::fingerprint( $fingerprint ) ) {
			return 'context';
		}
		if ( ! self::installed() ) {
			return 'storage';
		}
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$changed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET expires_at = 0 WHERE pool = %d AND kind = 1 AND slot = %d AND fingerprint = %s AND expires_at > %d',
					self::table(),
					self::FORMS[ $form ],
					$slot,
					$fingerprint,
					time()
				)
			);
			return false === $changed ? 'storage' : ( 1 === $changed ? '' : 'replayed' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * The unique owner index serializes allocation even when contenders try different slots.
	 * The subject must already be a site-keyed HMAC, never a raw IP, cookie or request field.
	 * Fixed windows can admit up to two limits across a boundary; refusals do not prolong a window.
	 *
	 * @param int|null $retry_at Receives the refused counter's expiry, or null when not rate limited.
	 */
	public static function budget( string $form, string $stage, string $kind, string $subject, int $limit, int $window, ?int &$retry_at = null ): string {
		$retry_at = null;
		if ( ! isset( self::FORMS[ $form ] ) || ! in_array( $stage, [ 'issue', 'submit' ], true ) || ! in_array( $kind, [ 'browser', 'ip' ], true ) || ! self::fingerprint( $subject ) || $limit < 1 || $limit > 1000000 || $window < 1 || $window > DAY_IN_SECONDS ) {
			return 'context';
		}
		if ( ! self::installed() ) {
			return 'storage';
		}
		$fingerprint = hash( 'sha256', $stage . '|' . $kind . '|' . $subject );
		$slot        = (int) hexdec( substr( $fingerprint, 0, 7 ) ) % self::RATE_SLOTS;
		// An odd step traverses distinct slots in this power-of-two pool, without unbounded scans.
		$step    = ( (int) hexdec( substr( $fingerprint, 7, 7 ) ) % intdiv( self::RATE_SLOTS, 2 ) ) * 2 + 1;
		$now     = time();
		$expires = ( intdiv( $now, $window ) + 1 ) * $window;
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$existing = self::take_existing( $form, $fingerprint, $limit, $now, $expires, $retry_at );
			if ( null !== $existing ) {
				return $existing;
			}
			for ( $attempt = 0; $attempt < self::ATTEMPTS; ++$attempt ) {
				$candidate = ( $slot + $attempt * $step ) % self::RATE_SLOTS;
				if ( ! self::ensure_slot( $form, 2, $candidate ) ) {
					return 'storage';
				}
				$changed = $wpdb->query( $wpdb->prepare( 'UPDATE IGNORE %i SET fingerprint = %s, hits = 1, expires_at = %d WHERE pool = %d AND kind = 2 AND slot = %d AND expires_at <= %d', self::table(), $fingerprint, $expires, self::FORMS[ $form ], $candidate, $now ) );
				if ( false === $changed ) {
					return 'storage';
				}
				if ( 1 === $changed ) {
					// FOUND_ROWS can count a duplicate-key update skipped by IGNORE. Verify ownership.
					$claimed = $wpdb->get_var( $wpdb->prepare( 'SELECT fingerprint FROM %i WHERE pool = %d AND kind = 2 AND slot = %d', self::table(), self::FORMS[ $form ], $candidate ) );
					if ( self::has_error() ) {
						return 'storage';
					}
					if ( is_string( $claimed ) && hash_equals( $fingerprint, $claimed ) ) {
						return '';
					}
				}
				// A parallel request may have claimed another slot for this owner. Reuse its counter.
				$existing = self::take_existing( $form, $fingerprint, $limit, $now, $expires, $retry_at );
				if ( null !== $existing ) {
					return $existing;
				}
			}
			return 'capacity';
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** Null means no owner exists; only a conditional UPDATE may grant allowance. */
	private static function take_existing( string $form, string $fingerprint, int $limit, int $now, int $expires, ?int &$retry_at ): ?string {
		global $wpdb;
		// A second attempt handles allocation between our first UPDATE and the following SELECT.
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = IF(expires_at <= %d, 1, hits + 1), expires_at = IF(expires_at <= %d, %d, expires_at) WHERE pool = %d AND kind = 2 AND fingerprint = %s AND (expires_at <= %d OR hits < %d)', self::table(), $now, $now, $expires, self::FORMS[ $form ], $fingerprint, $now, $limit ) );
			if ( false === $changed ) {
				return 'storage';
			}
			if ( 1 === $changed ) {
				return '';
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT hits, expires_at FROM %i WHERE pool = %d AND kind = 2 AND fingerprint = %s', self::table(), self::FORMS[ $form ], $fingerprint ), ARRAY_A );
			if ( self::has_error() ) {
				return 'storage';
			}
			if ( null === $row ) {
				return null;
			}
			if ( (int) $row['expires_at'] > $now && (int) $row['hits'] >= $limit ) {
				$retry_at = (int) $row['expires_at'];
				return 'rate_limited';
			}
		}
		return 'capacity';
	}

	/**
	 * INSERT IGNORE only initializes a fixed empty slot; admission is the conditional UPDATE.
	 * Avoid no-op ON DUPLICATE KEY updates: CLIENT_FOUND_ROWS could report those as a success.
	 */
	private static function ensure_slot( string $form, int $kind, int $slot ): bool {
		global $wpdb;
		return false !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (pool, kind, slot) VALUES (%d, %d, %d)', self::table(), self::FORMS[ $form ], $kind, $slot ) );
	}

	/** @phpstan-impure Options can change when installation writes the version in this call. */
	private static function installed(): bool {
		return self::VERSION === get_option( self::OPTION );
	}

	/** @phpstan-impure Every wpdb query replaces the preceding error. */
	private static function has_error(): bool {
		global $wpdb;
		return '' !== $wpdb->last_error;
	}

	private static function fingerprint( string $value ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/', $value );
	}
}
