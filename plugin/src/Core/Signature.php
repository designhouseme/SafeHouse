<?php
/**
 * Ed25519 detached-signature check shared by the updater and the vulnerability alerts.
 * Each caller passes its own public keys, so a key trusted for one channel is never trusted for the other.
 *
 * @package SafeHouse
 */

namespace SafeHouse\Core;

defined( 'ABSPATH' ) || exit;

final class Signature {

	/**
	 * @param string   $body        Exact signed bytes.
	 * @param string   $sig_b64     Base64 detached signature.
	 * @param string[] $public_keys Base64 Ed25519 public keys; any one may match.
	 */
	public static function verify( string $body, string $sig_b64, array $public_keys ): bool {
		$signature = base64_decode( trim( $sig_b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature encoding.
		if ( false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return false;
		}
		foreach ( $public_keys as $key_b64 ) {
			$key = base64_decode( (string) $key_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key encoding.
			if ( false === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $signature, $body, $key ) ) {
					return true;
				}
			} catch ( \SodiumException ) {
				continue;
			}
		}
		return false;
	}
}
