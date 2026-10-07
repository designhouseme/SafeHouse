<?php
/** Local integration regression: wp eval-file dev/signed-data-wp-test.php on a disposable local site. */
// phpcs:ignoreFile -- integration fixture, never shipped in plugin/.

use SafeHouse\Core\Signature;
use SafeHouse\Core\Updater;
use SafeHouse\Modules\Vulnerabilities;
use SafeHouse\Modules\PluginHealth;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! Updater::is_local() ) {
	throw new RuntimeException( 'Run only on an isolated local/development WordPress.' );
}
$pair = sodium_crypto_sign_keypair();
$key = sodium_crypto_sign_secretkey( $pair );
$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
define( 'SHOUSE_UPDATE_PUBLIC_KEYS', [ $public ] );
define( 'SHOUSE_ADVISORY_PUBLIC_KEYS', [ $public ] );
$names = [ 'shouse_update_floor', 'shouse_advisory_floor', 'shouse_vulnerabilities', 'shouse_vulnerabilities_findings', 'shouse_plugin_health', 'shouse_test_floor' ];
$before = [];
foreach ( $names as $name ) {
	$before[ $name ] = get_option( $name, null );
	delete_option( $name );
}
$cached = get_site_transient( 'shouse_update_manifest_v2' );
delete_site_transient( 'shouse_update_manifest_v2' );
$http = [];
$streamed = [];
$filter = static function ( $pre, $args, $url ) use ( &$http, &$streamed ) {
	$response = $http[ $url ] ?? [ 'response' => [ 'code' => 404 ], 'body' => '', 'headers' => [] ];
	if ( ! is_wp_error( $response ) && ! empty( $args['stream'] ) ) {
		$streamed[] = $args;
		file_put_contents( $args['filename'], substr( $response['body'], 0, $args['limit_response_size'] ?? PHP_INT_MAX ) );
		$response['body'] = '';
	}
	return $response;
};
add_filter( 'pre_http_request', $filter, 1000, 3 );
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void {
	if ( ! $ok ) throw new RuntimeException( $message );
	++$checks;
	WP_CLI::log( 'PASS ' . $message );
};
$response = static fn( string $body ): array => [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => $body ];
$envelope = static function ( array $data ) use ( $key ): string {
	$body = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	return json_encode( [ 'format' => 1, 'payload' => base64_encode( $body ), 'signatures' => [ base64_encode( sodium_crypto_sign_detached( $body, $key ) ) ] ], JSON_THROW_ON_ERROR );
};
$base = 'https://updates.designhouse.me/shouse/';
$zip = 'PK fixture bytes for bounded download';
$generation = time();
$manifest = [
	'protocol' => 2, 'channel' => 'release', 'generation' => $generation, 'issued_at' => time(), 'expires_at' => time() + 3600,
	'slug' => 'shouse', 'version' => '99.0.0', 'download_url' => $base . 'shouse-99.0.0.zip', 'size' => strlen( $zip ), 'sha256' => hash( 'sha256', $zip ),
	'requires' => '6.6', 'requires_php' => '8.1', 'tested' => '7.1', 'released' => gmdate( 'Y-m-d' ), 'homepage' => 'https://designhouse.me/', 'changelog' => 'Fixture',
];
$reset_update = static function (): void { delete_site_transient( 'shouse_update_manifest_v2' ); };
try {
	$http[ $base . 'release.json' ] = $response( $envelope( $manifest ) );
	$check( null !== Updater::manifest(), 'atomic release metadata accepted' );
	$bad = $manifest; $bad['generation']--; $bad['issued_at']--; $bad['changelog'] = 'replayed';
	$http[ $base . 'release.json' ] = $response( $envelope( $bad ) ); $reset_update();
	$check( null === Updater::manifest(), 'signed older generation rejected' );
	$bad = $manifest; $bad['generation']++; $bad['expires_at'] = time() - 1;
	$http[ $base . 'release.json' ] = $response( $envelope( $bad ) ); $reset_update();
	$check( null === Updater::manifest(), 'signed expired release rejected' );
	$bad = $manifest; $bad['generation']++; unset( $bad['size'] );
	$http[ $base . 'release.json' ] = $response( $envelope( $bad ) ); $reset_update();
	$check( null === Updater::manifest(), 'modern manifest requires signed size' );
	$bad = $manifest; $bad['generation']++; $bad['issued_at'] = time() + 3600; $bad['expires_at'] = time() + 7200;
	$http[ $base . 'release.json' ] = $response( $envelope( $bad ) ); $reset_update();
	$check( null === Updater::manifest(), 'future-dated signed metadata rejected' );
	$bad = $manifest; $bad['generation']++; $bad['channel'] = 'advisories';
	$http[ $base . 'release.json' ] = $response( $envelope( $bad ) ); $reset_update();
	$check( null === Updater::manifest(), 'signed metadata cannot cross channels' );
	$check( get_option( 'shouse_update_floor' )['generation'] === $generation, 'invalid signed metadata never advances the floor' );
	unset( $http[ $base . 'release.json' ] ); $reset_update();
	$legacy = $manifest; unset( $legacy['protocol'], $legacy['channel'], $legacy['generation'], $legacy['issued_at'], $legacy['expires_at'], $legacy['size'] );
	$legacy_body = json_encode( $legacy, JSON_THROW_ON_ERROR );
	$http[ $base . 'manifest.json' ] = $response( $legacy_body );
	$http[ $base . 'manifest.json.sig' ] = $response( base64_encode( sodium_crypto_sign_detached( $legacy_body, $key ) ) );
	$check( null === Updater::manifest(), 'legacy fallback disabled after modern metadata' );
	delete_option( 'shouse_update_floor' ); $reset_update();
	$check( null !== Updater::manifest(), 'fresh legacy host works before migration' );
	$invalid_envelope = json_decode( $envelope( $manifest ), true );
	$invalid_envelope['signatures'] = [ base64_encode( str_repeat( 'x', 64 ) ) ];
	$http[ $base . 'release.json' ] = $response( json_encode( $invalid_envelope ) ); $reset_update();
	$check( null === Updater::manifest(), 'invalid envelope never falls back to an otherwise valid legacy pair' );
	$rotating = json_decode( $envelope( $manifest ), true );
	array_unshift( $rotating['signatures'], base64_encode( str_repeat( 'x', 64 ) ) );
	$check( null !== Signature::unpack( json_encode( $rotating ), [ $public ], 65536 ), 'rotation envelope accepts a matching trusted signature among others' );
	$manifest['generation']++;
	$http[ $base . 'release.json' ] = $response( $envelope( $manifest ) ); $reset_update();
	$check( null !== Updater::manifest(), 'legacy client state migrates to modern generation' );
	$http[ $manifest['download_url'] ] = $response( $zip );
	$http[ $manifest['download_url'] ]['headers']['content-disposition'] = 'attachment; filename=existing-victim.txt';
	$victim = sys_get_temp_dir() . '/existing-victim.txt';
	if ( file_exists( $victim ) ) throw new RuntimeException( 'Fixture filename unexpectedly exists' );
	file_put_contents( $victim, 'KEEP ORIGINAL' );
	$file = Updater::verify_download( false, $manifest['download_url'], new stdClass(), [ 'plugin' => Updater::basename() ] );
	$check( is_string( $file ) && file_get_contents( $file ) === $zip && basename( $file ) === 'package.zip', 'valid bounded download uses its own canonical name' );
	$check( file_get_contents( $victim ) === 'KEEP ORIGINAL', 'Content-Disposition cannot overwrite an existing file' );
	$http[ $manifest['download_url'] ]['body'] = str_repeat( 'x', strlen( $zip ) + 100 );
	$rejected = Updater::verify_download( false, $manifest['download_url'], new stdClass(), [ 'plugin' => Updater::basename() ] );
	$check( is_wp_error( $rejected ) && $rejected->get_error_code() === 'shouse_package_size', 'oversized stream refused before hashing' );
	$check( end( $streamed )['limit_response_size'] === strlen( $zip ) + 1, 'transport receives the signed streaming byte limit' );
	$check( ! file_exists( end( $streamed )['filename'] ), 'rejected download and private directory cleaned up' );
	$check( file_get_contents( $victim ) === 'KEEP ORIGINAL', 'rejected response preserves existing files too' );
	unlink( $victim );

	$feed = static function ( int $gen, bool $finding, int $expires ) use ( &$http, $base, $response, $envelope ): void {
		$hashes = [];
		for ( $i = 0; $i < 256; ++$i ) {
			$name = sprintf( '%02x', $i );
			$data = $finding && $name === substr( md5( 'core:wordpress' ), 0, 2 )
				? [ 'core:wordpress' => [ [ '11111111-1111-4111-8111-111111111111', 'Fixture vulnerability', 9.8, [ [ '*', true, '*', true ] ], [ '999.0' ] ] ] ] : [];
			$body = json_encode( (object) $data, JSON_THROW_ON_ERROR );
			$hashes[ $name ] = hash( 'sha256', $body );
			$http[ $base . 'advisories/sha256/' . $hashes[ $name ] . '.json' ] = $response( $body );
			$http[ $base . 'advisories/' . $name . '.json' ] = $response( $body );
		}
		$index = [ 'format' => 1, 'protocol' => 2, 'channel' => 'advisories', 'generation' => $gen, 'issued_at' => time(), 'expires_at' => $expires,
			'generated' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'attribution' => 'Fixture', 'shards' => $hashes ];
		$http[ $base . 'advisories/feed.json' ] = $response( $envelope( $index ) );
	};
	$module = new Vulnerabilities();
	$feed( $generation, true, time() + 3600 );
	$legacy_index = json_decode( base64_decode( json_decode( $http[ $base . 'advisories/feed.json' ]['body'], true )['payload'] ), true );
	unset( $legacy_index['protocol'], $legacy_index['channel'], $legacy_index['generation'], $legacy_index['issued_at'], $legacy_index['expires_at'] );
	$legacy_body = json_encode( $legacy_index, JSON_THROW_ON_ERROR );
	$http[ $base . 'advisories/index.json' ] = $response( $legacy_body );
	$http[ $base . 'advisories/index.json.sig' ] = $response( base64_encode( sodium_crypto_sign_detached( $legacy_body, $key ) ) );
	unset( $http[ $base . 'advisories/feed.json' ] );
	$state = $module->refresh();
	$check( empty( $state['error'] ) && count( $module->findings( true ) ) === 1, 'fresh signed legacy advisory host works before migration' );
	$generation = time() + 1;
	$feed( $generation, true, time() + 3600 );
	$state = $module->refresh();
	$old_state = $state;
	$check( empty( $state['error'] ) && count( $module->findings( true ) ) === 1 && ! empty( $state['last_success'] ), 'fresh CVE envelope matches and records successful check' );
	$feed( $generation - 1, false, time() + 3600 );
	$state = $module->refresh();
	$check( ! empty( $state['error'] ) && count( $module->findings( true ) ) === 1, 'replayed feed cannot erase known findings' );
	$feed( $generation + 1, false, time() - 1 );
	$state = $module->refresh();
	$check( ! empty( $state['error'] ) && count( $module->findings( true ) ) === 1, 'expired feed keeps previous warnings' );
	$feed( $generation + 2, false, time() + 3600 );
	$state = $module->refresh();
	$check( empty( $state['error'] ) && $module->site_health_result()['status'] === 'good', 'a current clean feed permits good health' );
	$saver = new ReflectionMethod( Vulnerabilities::class, 'save_state' );
	$kept = $saver->invoke( $module, $old_state );
	$check( $kept['generation'] === $generation + 2 && count( $module->findings( true ) ) === 0, 'a slow old refresh cannot overwrite a newer completed snapshot' );
	$floor = get_option( 'shouse_advisory_floor' );
	$newer_check = $state;
	$newer_check['last_success']++;
	$newer_check['checked_at']++;
	$saver->invoke( $module, $newer_check, $state, $floor );
	$failed_check = $state;
	$failed_check['error'] = 'Failure from an earlier request';
	$kept = $saver->invoke( $module, $failed_check, $state );
	$check( empty( $kept['error'] ) && $kept['last_success'] === $newer_check['last_success'] && get_option( 'shouse_vulnerabilities' ) === $newer_check, 'a delayed error cannot erase a concurrent success at the same generation' );
	global $wpdb;
	$new_floor = $floor;
	$new_floor['generation']++;
	$new_floor['digest'] = str_repeat( 'f', 64 );
	$advance_during_save = static function ( string $query ) use ( &$advance_during_save, $new_floor, $wpdb ): string {
		if ( str_starts_with( $query, "UPDATE {$wpdb->options} AS snapshot" ) || str_starts_with( $query, "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT" ) ) {
			remove_filter( 'query', $advance_during_save, 1000 );
			if ( ! Signature::advance_floor( 'shouse_advisory_floor', $new_floor ) ) throw new RuntimeException( 'Fixture floor advance failed' );
		}
		return $query;
	};
	$candidate = $newer_check; $candidate['checked_at']++;
	add_filter( 'query', $advance_during_save, 1000 );
	$kept = $saver->invoke( $module, $candidate, $newer_check, $floor );
	$check( ! empty( $kept['error'] ) && get_option( 'shouse_vulnerabilities' ) === $newer_check, 'snapshot UPDATE rejects a floor advanced between its read and SQL write' );
	update_option( 'shouse_advisory_floor', $floor, false );
	delete_option( 'shouse_vulnerabilities' );
	add_filter( 'query', $advance_during_save, 1000 );
	$kept = $saver->invoke( $module, $candidate, [], $floor );
	$check( ! empty( $kept['error'] ) && false === get_option( 'shouse_vulnerabilities' ), 'first snapshot INSERT also rejects a concurrently advanced floor' );
	remove_filter( 'query', $advance_during_save, 1000 );
	update_option( 'shouse_advisory_floor', $floor, false );
	update_option( 'shouse_vulnerabilities', $newer_check, false );
	delete_option( 'shouse_vulnerabilities' );
	$first_snapshot_race = static function ( string $query ) use ( &$first_snapshot_race, $saver, $module, $newer_check, $floor ): string {
		if ( str_starts_with( $query, 'INSERT' ) && str_contains( $query, "'shouse_vulnerabilities'" ) ) {
			remove_filter( 'query', $first_snapshot_race, 1000 );
			$saver->invoke( $module, $newer_check, [], $floor );
		}
		return $query;
	};
	add_filter( 'query', $first_snapshot_race, 1000 );
	$kept = $saver->invoke( $module, [ 'error' => 'An earlier initial request failed' ], [] );
	$check( $kept === $newer_check && get_option( 'shouse_vulnerabilities' ) === $newer_check, 'first error INSERT cannot overwrite a concurrent first successful snapshot' );
	$http[ $base . 'advisories/feed.json' ] = new WP_Error( 'offline', 'Fixture network unavailable' );
	$module->refresh();
	$check( $module->site_health_result()['status'] === 'recommended', 'failed refresh never gives green health' );
	$health = new PluginHealth();
	$check( $health->site_health_result()['status'] === 'recommended', 'PluginHealth without a successful check is not green' );
	$first_floor_race = static function ( string $query ) use ( &$first_floor_race ): string {
		if ( str_starts_with( $query, 'INSERT' ) && str_contains( $query, "'shouse_test_floor'" ) ) {
			remove_filter( 'query', $first_floor_race, 1000 );
			Signature::advance_floor( 'shouse_test_floor', [ 'protocol' => 2, 'generation' => 21, 'digest' => str_repeat( 'c', 64 ) ] );
		}
		return $query;
	};
	add_filter( 'query', $first_floor_race, 1000 );
	$accepted = Signature::advance_floor( 'shouse_test_floor', [ 'protocol' => 2, 'generation' => 20, 'digest' => str_repeat( 'a', 64 ) ] );
	$check( ! $accepted && get_option( 'shouse_test_floor' )['generation'] === 21, 'first floor INSERT cannot lower a concurrent first accepted generation' );
	delete_option( 'shouse_test_floor' );
	$check( Signature::advance_floor( 'shouse_test_floor', [ 'protocol' => 2, 'generation' => 20, 'digest' => str_repeat( 'a', 64 ) ] ), 'floor initialized in real WordPress database' );
	$check( ! Signature::advance_floor( 'shouse_test_floor', [ 'protocol' => 2, 'generation' => 19, 'digest' => str_repeat( 'b', 64 ) ] ), 'persistent floor rejects older metadata' );
	$check( Signature::advance_floor( 'shouse_test_floor', [ 'protocol' => 2, 'generation' => 21, 'digest' => str_repeat( 'c', 64 ) ] ), 'floor advances with database compare-and-swap' );
	wp_cache_set( 'shouse_test_floor', [ 'protocol' => 1, 'generation' => 1, 'digest' => str_repeat( 'd', 64 ) ], 'options' );
	$check( ! Signature::advance_floor( 'shouse_test_floor', [ 'protocol' => 2, 'generation' => 19, 'digest' => str_repeat( 'b', 64 ) ] ), 'stale object-cache value cannot lower the authoritative floor' );
	WP_CLI::success( "$checks signed-data checks passed." );
} finally {
	remove_filter( 'pre_http_request', $filter, 1000 );
	if ( isset( $advance_during_save ) ) remove_filter( 'query', $advance_during_save, 1000 );
	if ( isset( $first_snapshot_race ) ) remove_filter( 'query', $first_snapshot_race, 1000 );
	if ( isset( $first_floor_race ) ) remove_filter( 'query', $first_floor_race, 1000 );
	foreach ( $before as $name => $value ) {
		if ( null === $value ) delete_option( $name ); else update_option( $name, $value, false );
	}
	delete_site_transient( 'shouse_update_manifest_v2' );
	if ( false !== $cached ) set_site_transient( 'shouse_update_manifest_v2', $cached, HOUR_IN_SECONDS );
	if ( isset( $victim ) && file_exists( $victim ) ) unlink( $victim );
}
