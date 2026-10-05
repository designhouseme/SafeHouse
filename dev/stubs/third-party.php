<?php
/**
 * Minimal stubs for third-party APIs WPHouse probes with class_exists()/function_exists().
 * Static analysis only; never loaded at runtime.
 */

class wfConfig {
	/** @return mixed */
	public static function get( string $key, mixed $default = false, bool $allow_cached = true ) {}
}
