<?php
/**
 * `wp shouse login`: inspect and lift login lockouts.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Modules;

use WP_CLI;
use SafeHouse\Core\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and lift login lockouts.
 */
final class LoginLimitsCommand {

	/**
	 * Show locked-out addresses and accounts paused for new devices.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$rows = array_map(
			static fn( $row ) => [
				'kind'     => 'ip' === $row['kind'] ? 'address' : 'account (new devices)',
				'subject'  => 'ip' === $row['kind'] ? $row['subject'] : '(hashed)',
				'until'    => $row['until'] . ' UTC',
				'lockouts' => $row['lockouts'],
			],
			LoginLimits::active()
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, [ 'kind', 'subject', 'until', 'lockouts' ] );
	}

	/**
	 * Lift the lockout of an address or an account, or all lockouts.
	 *
	 * ## OPTIONS
	 *
	 * [<address-or-login>]
	 * : An IP address, or the login or e-mail of an account.
	 *
	 * [--all]
	 * : Lift every lockout.
	 *
	 * @param string[]            $args       Positional arguments.
	 * @param array<string, bool> $assoc_args Named arguments.
	 */
	public function unlock( array $args, array $assoc_args ): void {
		if ( empty( $assoc_args['all'] ) && empty( $args[0] ) ) {
			WP_CLI::error( 'Name an address or account, or pass --all.' );
		}
		$subject = empty( $assoc_args['all'] ) ? (string) $args[0] : '';
		$count   = LoginLimits::unlock( $subject );
		Log::add( 'login_unlocked', 'Login lockout lifted from WP-CLI', [ 'rows' => $count ], 'warning' );
		WP_CLI::success( sprintf( '%d lockout record(s) cleared.', $count ) );
	}
}
