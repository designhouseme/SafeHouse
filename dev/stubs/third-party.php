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

	// WooCommerce, only what the Omnibus module calls (it runs only when WooCommerce is active).
	class WC_Product {
		public function get_id(): int {}
		public function get_name( string $context = 'view' ): string {}
		/** @return string */
		public function get_price( string $context = 'view' ) {}
		/** @return string */
		public function get_regular_price( string $context = 'view' ) {}
		public function is_on_sale( string $context = 'view' ): bool {}
		/** @param string|string[] $type */
		public function is_type( $type ): bool {}
		/** @return int[] */
		public function get_children() {}
	}

	class WC_Product_Variable extends WC_Product {
		/** @return int[] */
		public function get_visible_children() {}
	}

	class WC_Product_Factory {
		/** @return string|false */
		public static function get_product_type( int $product_id ) {}
	}

	/** @return WC_Product|null|false */
	function wc_get_product( mixed $the_product = false, array $deprecated = [] ) {}
	/** @param array<string, mixed> $args */
	function wc_price( float $price, array $args = [] ): string {}
	/**
	 * @param array<string, mixed> $args
	 * @return float|string
	 */
	function wc_get_price_to_display( WC_Product $product, array $args = [] ) {}
	/** @return int[] */
	function wc_get_product_ids_on_sale(): array {}
	/** @return string */
	function wc_format_decimal( mixed $number, mixed $dp = false, bool $trim_zeros = false ) {}
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
