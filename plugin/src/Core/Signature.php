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
	 * Decode an atomic envelope without ever re-encoding its signed JSON payload.
	 * Multiple signatures allow a staged rotation while older clients still trust the previous key.
	 *
	 * @param string[] $public_keys Keys trusted by this channel only.
	 */
	public static function unpack( string $envelope, array $public_keys, int $limit ): ?string {
		$data = json_decode( $envelope, true, 8 );
		if ( ! is_array( $data ) || 1 !== ( $data['format'] ?? null ) || ! is_string( $data['payload'] ?? null )
			|| ! is_array( $data['signatures'] ?? null ) || count( $data['signatures'] ) > 4 ) {
			return null;
		}
		$body = base64_decode( $data['payload'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- exact signed payload.
		if ( false === $body || strlen( $body ) > $limit ) {
			return null;
		}
		foreach ( $data['signatures'] as $signature ) {
			if ( is_string( $signature ) && self::verify( $body, $signature, $public_keys ) ) {
				return $body;
			}
		}
		return null;
	}

	/**
	 * Check signed freshness fields. Legacy dates are accepted only during migration, never after v2.
	 *
	 * @param array<string, mixed> $data Signed fields.
	 * @return array{protocol: int, generation: int, issued_at: int, expires_at: int}|null
	 */
	public static function freshness( array $data, string $channel, int $max_age, string $legacy_field, string $legacy_format ): ?array {
		$protocol = $data['protocol'] ?? 1;
		if ( 2 === $protocol ) {
			if ( ( $data['channel'] ?? null ) !== $channel ) {
				return null;
			}
			$issued     = $data['issued_at'] ?? null;
			$expires    = $data['expires_at'] ?? null;
			$generation = $data['generation'] ?? null;
			if ( ! is_int( $issued ) || ! is_int( $expires ) || ! is_int( $generation ) || $generation < 1 ) {
				return null;
			}
		} elseif ( 1 === $protocol && is_string( $data[ $legacy_field ] ?? null ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $legacy_format, $data[ $legacy_field ], new \DateTimeZone( 'UTC' ) );
			if ( false === $date || $date->format( $legacy_format ) !== $data[ $legacy_field ] ) {
				return null;
			}
			$issued     = $date->getTimestamp();
			$expires    = $issued + $max_age;
			$generation = $issued;
		} else {
			return null;
		}
		if ( $issued < 1 || $issued > time() + 300 || $expires <= time() || $expires <= $issued || $expires - $issued > $max_age ) {
			return null;
		}
		return [
			'protocol'   => $protocol,
			'generation' => $generation,
			'issued_at'  => $issued,
			'expires_at' => $expires,
		];
	}

	/**
	 * Advance a persistent floor with compare-and-swap, independent of transient/object-cache expiry.
	 * A simultaneous newer refresh must never be overwritten by an older response.
	 *
	 * @param array{protocol: int, generation: int, digest: string, version?: string} $candidate Verified and fully validated metadata.
	 */
	public static function advance_floor( string $option, array $candidate ): bool {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			// Read the authoritative value: the object cache is not a rollback-prevention store.
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic security floor, not cacheable.
			if ( null === $raw ) {
				if ( add_option( $option, $candidate, '', false ) ) {
					return true;
				}
				continue;
			}
			$floor = maybe_unserialize( $raw );
			if ( ! is_array( $floor ) || ! is_int( $floor['protocol'] ?? null ) || ! is_int( $floor['generation'] ?? null ) || ! is_string( $floor['digest'] ?? null ) ) {
				return false;
			}
			if ( $candidate['protocol'] < $floor['protocol'] || $candidate['generation'] < $floor['generation']
				|| ( isset( $floor['version'] ) && ( ! isset( $candidate['version'] ) || version_compare( $candidate['version'], (string) $floor['version'], '<' ) ) ) ) {
				return false;
			}
			if ( $candidate['protocol'] === $floor['protocol'] && $candidate['generation'] === $floor['generation'] && ! hash_equals( $floor['digest'], $candidate['digest'] ) ) {
				// Legacy release dates have day precision; a higher version on that day is legitimate.
				if ( 1 !== $candidate['protocol'] || ! isset( $candidate['version'], $floor['version'] ) || ! version_compare( $candidate['version'], (string) $floor['version'], '>' ) ) {
					return false;
				}
			}
			if ( $candidate === $floor ) {
				return true;
			}
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $candidate ), $option, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-swap cannot use update_option().
			if ( 1 === $updated ) {
				wp_cache_delete( $option, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return true;
			}
		}
		return false;
	}

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
