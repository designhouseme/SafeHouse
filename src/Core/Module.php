<?php
/**
 * Contract every WPHouse module implements.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

defined( 'ABSPATH' ) || exit;

interface Module {

	/** Stable identifier, used as the settings key and in WP-CLI. */
	public function id(): string;

	/** Whether the module is on when nobody has saved a choice yet. */
	public function default_enabled(): bool;

	/**
	 * Field defaults. Must not call translation functions: it runs before `init`.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array;

	/** Translated module name. Only call after `init`. */
	public function label(): string;

	/** Translated one-paragraph description. Only call after `init`. */
	public function description(): string;

	/**
	 * Field schema for the settings page. Only call after `init`.
	 *
	 * Each field: type (toggle|text|email_list|textarea|html|code|number|select), label,
	 * optional help, options (select), min/max (number), max_length, covered_by, locked.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array;

	/**
	 * Clean submitted values for this module.
	 *
	 * @param array<string, mixed> $input Unslashed submitted values.
	 * @param array<string, mixed> $old   Currently saved values.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input, array $old ): array;

	/** Whether the module can run on this site (e.g. WooCommerce present). Must not translate: runs before `init`. */
	public function available(): bool;

	/** Translated reason why available() is false, for the settings page. Only call after `init`. */
	public function unavailable_reason(): string;

	/** Register hooks. Called on `plugins_loaded` only when the module is enabled and available. */
	public function boot(): void;

	/**
	 * Admin-side buttons, as task => translated label. Handled by handle_task().
	 *
	 * @return array<string, string>
	 */
	public function tasks(): array;

	/** Run an admin task and return a translated result message. Capability and nonce are already checked. */
	public function handle_task( string $task ): string;

	/** Print extra read-only output (status, findings) under the module's fields. Output must be escaped. */
	public function render_panel(): void;

	/**
	 * React to a settings save, whether or not the module is enabled.
	 *
	 * @param array<string, mixed> $before      Previous module settings.
	 * @param array<string, mixed> $after       New module settings.
	 * @param bool                 $was_enabled Previous enabled state.
	 * @param bool                 $is_enabled  New enabled state.
	 */
	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void;
}
