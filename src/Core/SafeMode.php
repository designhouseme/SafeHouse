<?php
/**
 * Safe mode switches every module off without touching the database.
 *
 * Turn it on with define( 'WPHOUSE_SAFE_MODE', true ), by uploading an empty file named
 * `wphouse-safe-mode` to wp-content (FTP is enough), or with `wp wphouse safe-mode on`.
 * There is deliberately no URL-based rescue key: URLs end up in server and CDN logs.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

defined( 'ABSPATH' ) || exit;

final class SafeMode {

	public static function flag_file(): string {
		return WP_CONTENT_DIR . '/wphouse-safe-mode';
	}

	public static function active(): bool {
		return Compat::constant_on( 'WPHOUSE_SAFE_MODE' ) || file_exists( self::flag_file() );
	}
}
