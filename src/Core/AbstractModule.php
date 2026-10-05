<?php
/**
 * Shared plumbing for modules: settings access, overlap handling and schema-driven sanitizing.
 *
 * @package WPHouse
 */

namespace WPHouse\Core;

use WPHouse\Plugin;

defined( 'ABSPATH' ) || exit;

abstract class AbstractModule implements Module {

	public function default_enabled(): bool {
		return false;
	}

	public function available(): bool {
		return true;
	}

	public function unavailable_reason(): string {
		return '';
	}

	public function tasks(): array {
		return [];
	}

	public function handle_task( string $task ): string {
		return '';
	}

	public function render_panel(): void {
	}

	public function settings_saved( array $before, array $after, bool $was_enabled, bool $is_enabled ): void {
	}

	/**
	 * Fields already handled by another plugin or by wp-config, as field => provider name.
	 * Must not call translation functions: it runs on `plugins_loaded`.
	 *
	 * @return array<string, string>
	 */
	public function coverage(): array {
		return [];
	}

	/** Saved value of one field, falling back to its default. */
	protected function opt( string $key ): mixed {
		return Plugin::instance()->settings->value( $this->id(), $key, $this->defaults()[ $key ] ?? null );
	}

	/** A toggle field that is on and not already handled elsewhere. */
	protected function feature_on( string $key ): bool {
		if ( ! $this->opt( $key ) ) {
			return false;
		}
		return ! isset( $this->coverage()[ $key ] ) || Compat::ignore_overlaps();
	}

	/**
	 * Field schema with overlap and permission locks applied.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema(): array {
		$coverage = Compat::ignore_overlaps() ? [] : $this->coverage();
		$fields   = $this->fields();
		foreach ( $fields as $key => $field ) {
			if ( isset( $coverage[ $key ] ) ) {
				$fields[ $key ]['covered_by'] = $coverage[ $key ];
				$fields[ $key ]['locked']     = true;
			}
			if ( 'code' === $field['type'] && ! current_user_can( 'unfiltered_html' ) ) {
				$fields[ $key ]['locked'] = true;
			}
		}
		return $fields;
	}

	public function sanitize( array $input, array $old ): array {
		$defaults = $this->defaults();
		$clean    = [];
		foreach ( $this->schema() as $key => $field ) {
			$previous = $old[ $key ] ?? $defaults[ $key ] ?? null;
			if ( ! empty( $field['locked'] ) ) {
				$clean[ $key ] = $previous;
				continue;
			}
			$clean[ $key ] = $this->sanitize_field( $field, $input[ $key ] ?? null, $defaults[ $key ] ?? null );
		}
		return $clean;
	}

	/**
	 * Clean one value according to its field type.
	 *
	 * @param array<string, mixed> $field   Field schema.
	 * @param mixed                $value   Submitted value (unslashed).
	 * @param mixed                $fallback Default value.
	 */
	protected function sanitize_field( array $field, mixed $value, mixed $fallback ): mixed {
		$max_length = (int) ( $field['max_length'] ?? 500 );
		switch ( $field['type'] ) {
			case 'toggle':
				return ! empty( $value );
			case 'text':
				return mb_substr( sanitize_text_field( (string) $value ), 0, $max_length );
			case 'email_list':
				return self::clean_email_list( (string) $value );
			case 'textarea':
				return mb_substr( sanitize_textarea_field( (string) $value ), 0, $max_length );
			case 'html':
				return mb_substr( wp_kses_post( (string) $value ), 0, $max_length );
			case 'code':
				// Raw by design (header/footer snippets); schema() locks the field for users without unfiltered_html.
				return mb_substr( (string) $value, 0, $max_length );
			case 'number':
				if ( '' === $value || null === $value ) {
					return ! empty( $field['allow_empty'] ) ? '' : $fallback;
				}
				$number = (int) $value;
				if ( isset( $field['min'] ) ) {
					$number = max( (int) $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( (int) $field['max'], $number );
				}
				return $number;
			case 'select':
				$value = (string) $value;
				return array_key_exists( $value, (array) ( $field['options'] ?? [] ) ) ? $value : $fallback;
		}
		return $fallback;
	}

	/** Comma/space/newline separated list of valid addresses, normalised to "a@x, b@y". */
	public static function clean_email_list( string $value ): string {
		$emails = array_filter(
			array_map( 'sanitize_email', (array) preg_split( '/[\s,;]+/', $value ) ),
			'is_email'
		);
		return implode( ', ', array_unique( $emails ) );
	}
}
