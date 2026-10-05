<?php
/**
 * Minimal stubs for third-party APIs WPHouse probes with class_exists()/function_exists().
 * Static analysis only; never loaded at runtime.
 */

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
