<?php
/**
 * One autoloaded option, `shouse_settings`, with a section per module.
 *
 * Shape: [ 'modules' => [ id => bool ], 'general' => [...], '<module id>' => [...] ].
 * Agency deploys can pin state in wp-config:
 *   define( 'SHOUSE_MODULES', [ 'lockdown' => true, 'scripts' => false ] );  // forces modules on/off
 *   define( 'SHOUSE_LOCK_SETTINGS', true );                                  // settings page becomes read-only
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

use SafeHouse\Plugin;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'shouse_settings';

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	public function __construct() {
		add_action( 'update_option_' . self::OPTION, [ $this, 'after_update' ], 10, 2 );
		add_action( 'add_option_' . self::OPTION, [ $this, 'after_add' ], 10, 2 );
	}

	/** @return array<string, mixed> */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = is_array( $stored ) ? $stored : [];
		}
		return $this->cache;
	}

	public function value( string $section, string $key, mixed $fallback = null ): mixed {
		$all = $this->all();
		return $all[ $section ][ $key ] ?? $fallback;
	}

	/** @return array<string, mixed> */
	public function section( string $section ): array {
		$all = $this->all();
		return is_array( $all[ $section ] ?? null ) ? $all[ $section ] : [];
	}

	/** State forced by SHOUSE_MODULES, or null when the saved setting decides. */
	public function forced( string $module_id ): ?bool {
		if ( ! defined( 'SHOUSE_MODULES' ) || ! is_array( SHOUSE_MODULES ) || ! array_key_exists( $module_id, SHOUSE_MODULES ) ) {
			return null;
		}
		return (bool) SHOUSE_MODULES[ $module_id ];
	}

	public function module_enabled( AbstractModule $module ): bool {
		$forced = $this->forced( $module->id() );
		if ( null !== $forced ) {
			return $forced;
		}
		$saved = $this->all()['modules'][ $module->id() ] ?? null;
		return null === $saved ? $module->default_enabled() : (bool) $saved;
	}

	public static function locked(): bool {
		return defined( 'SHOUSE_LOCK_SETTINGS' ) && SHOUSE_LOCK_SETTINGS;
	}

	/** Alert recipients from the General section, or the site admin address. */
	public function alert_recipients(): string {
		$list = (string) $this->value( 'general', 'alert_emails', '' );
		return '' !== $list ? $list : (string) get_option( 'admin_email' );
	}

	/** Used by WP-CLI, which does not go through the settings form. */
	public function set_module_enabled( string $module_id, bool $enabled ): void {
		$all                          = $this->all();
		$all['modules']               = is_array( $all['modules'] ?? null ) ? $all['modules'] : [];
		$all['modules'][ $module_id ] = $enabled;
		$this->cache                  = null;
		update_option( self::OPTION, $all, true );
		$this->cache = null;
	}

	public function register(): void {
		register_setting(
			'shouse',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'show_in_rest'      => false,
				'default'           => [],
			]
		);
	}

	/**
	 * Settings-form sanitizer. Idempotent, because core runs it twice when the option is first created.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$old = $this->all();
		if ( self::locked() || ! current_user_can( 'manage_options' ) ) {
			return $old;
		}
		$input = is_array( $input ) ? $input : [];

		$clean = [
			'modules' => [],
			'general' => [
				'alert_emails' => AbstractModule::clean_email_list( (string) ( $input['general']['alert_emails'] ?? '' ) ),
				'proxy'        => 'cloudflare' === ( $input['general']['proxy'] ?? '' ) ? 'cloudflare' : '',
			],
		];

		foreach ( Plugin::instance()->modules() as $id => $module ) {
			$clean['modules'][ $id ] = null !== $this->forced( $id )
				? (bool) ( $old['modules'][ $id ] ?? $module->default_enabled() )
				: ! empty( $input['modules'][ $id ] );

			$section      = is_array( $input[ $id ] ?? null ) ? $input[ $id ] : [];
			$old_section  = is_array( $old[ $id ] ?? null ) ? $old[ $id ] : [];
			$clean[ $id ] = $module->sanitize( $section, $old_section );
		}

		return $clean;
	}

	/**
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public function after_update( mixed $old_value, mixed $new_value ): void {
		$this->cache = null;
		$this->dispatch_changes( is_array( $old_value ) ? $old_value : [], is_array( $new_value ) ? $new_value : [] );
	}

	/**
	 * @param string $option    Option name.
	 * @param mixed  $new_value New option value.
	 */
	public function after_add( string $option, mixed $new_value ): void {
		$this->cache = null;
		$this->dispatch_changes( [], is_array( $new_value ) ? $new_value : [] );
	}

	/**
	 * Log which sections changed and let every module react, enabled or not.
	 *
	 * @param array<string, mixed> $before Previous option value.
	 * @param array<string, mixed> $after  New option value.
	 */
	private function dispatch_changes( array $before, array $after ): void {
		$changed = [];
		foreach ( Plugin::instance()->modules() as $id => $module ) {
			$was_enabled = (bool) ( $before['modules'][ $id ] ?? $module->default_enabled() );
			$is_enabled  = (bool) ( $after['modules'][ $id ] ?? $module->default_enabled() );
			$old_section = is_array( $before[ $id ] ?? null ) ? $before[ $id ] : [];
			$new_section = is_array( $after[ $id ] ?? null ) ? $after[ $id ] : [];

			if ( $was_enabled !== $is_enabled || $old_section !== $new_section ) {
				$changed[] = $id . ( $was_enabled !== $is_enabled ? ( $is_enabled ? ' (on)' : ' (off)' ) : '' );
				$module->settings_saved( $old_section, $new_section, $was_enabled, $is_enabled );
			}
		}
		if ( ( $before['general'] ?? [] ) !== ( $after['general'] ?? [] ) ) {
			$changed[] = 'general';
		}
		if ( $changed ) {
			Log::add( 'settings_changed', 'Settings changed: ' . implode( ', ', $changed ) );
		}
	}
}
