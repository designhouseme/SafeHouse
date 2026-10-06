<?php
/**
 * Minimal stubs for third-party APIs SafeHouse probes with class_exists()/function_exists().
 * Static analysis only; never loaded at runtime.
 */

namespace {
	class wfConfig {
		/** @return mixed */
		public static function get( string $key, mixed $default = false, bool $allow_cached = true ) {}
	}

	class autoptimizeCache {
		public static function clearall(): bool {}
	}

	function rocket_clean_domain(): void {}
	function w3tc_flush_all(): void {}
	function wp_cache_clear_cache(): void {}
}

namespace WordfenceLS {
	class Controller_CAPTCHA {
		public static function shared(): self {}
		public function enabled(): bool {}
	}

	class Controller_Settings {
		public static function shared(): self {}
		public function get_bool( string $key, bool $default = false ): bool {}
	}
}
