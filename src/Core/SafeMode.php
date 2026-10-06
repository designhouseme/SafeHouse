<?php
/**
 * Safe mode switches every module off without touching the database.
 *
 * Turn it on with define( 'SHOUSE_SAFE_MODE', true ), by uploading an empty file named
 * `shouse-safe-mode` to wp-content (FTP is enough), or with `wp shouse safe-mode on`.
 * There is deliberately no URL-based rescue key: URLs end up in server and CDN logs.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class SafeMode {

	public static function flag_file(): string {
		return WP_CONTENT_DIR . '/shouse-safe-mode';
	}

	/** The file's name before the rename to SafeHouse. Still honoured: a rescue must not depend on reading the changelog. */
	public static function legacy_flag_file(): string {
		return WP_CONTENT_DIR . '/wphouse-safe-mode';
	}

	public static function active(): bool {
		return Compat::constant_on( 'SHOUSE_SAFE_MODE' ) || file_exists( self::flag_file() ) || file_exists( self::legacy_flag_file() );
	}
}
