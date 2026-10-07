<?php
/**
 * Release helper, run inside the composer:2 image (PHP with sodium and zip). Called by dev/release.sh.
 *
 *   keygen   <keyfile>                       new Ed25519 key pair; prints the public key
 *   pubkey   <keyfile>                       prints the public key
 *   package  <srcdir> <zipfile>              zips srcdir under "shouse/", sorted, fixed timestamps
 *   manifest <zipfile> <out.json> <version> <download_url> <changelog.md>
 *   sign     <keyfile> <file>                writes <file>.sig (base64 detached signature)
 *   verify   <public_key_b64> <file>         exits 1 unless <file>.sig is valid
 *   envelope <file> <out.json> [extra.sig]  atomic payload and detached signatures
 *
 * @package SafeHouse
 */

// phpcs:ignoreFile -- CLI build tool, not loaded by WordPress.

$cmd  = $argv[1] ?? '';
$args = array_slice( $argv, 2 );

function fail( string $message ): never {
	fwrite( STDERR, "release-tool: $message\n" );
	exit( 1 );
}

function read_secret_key( string $keyfile ): string {
	if ( ! is_readable( $keyfile ) ) {
		fail( "cannot read key file $keyfile" );
	}
	$key = base64_decode( trim( (string) file_get_contents( $keyfile ) ), true );
	if ( false === $key || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $key ) ) {
		fail( "$keyfile is not an Ed25519 secret key" );
	}
	return $key;
}

function plugin_header( string $zipfile, string $field ): string {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zipfile ) ) {
		fail( "cannot open $zipfile" );
	}
	$main = (string) $zip->getFromName( 'shouse/shouse.php' );
	$zip->close();
	return preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $main, $m ) ? trim( $m[1] ) : '';
}

switch ( $cmd ) {
	case 'keygen':
		[ $keyfile ] = $args + [ '' ];
		if ( '' === $keyfile || file_exists( $keyfile ) ) {
			fail( 'refusing to overwrite an existing key (or no path given)' );
		}
		$pair = sodium_crypto_sign_keypair();
		file_put_contents( $keyfile, base64_encode( sodium_crypto_sign_secretkey( $pair ) ) . "\n" );
		chmod( $keyfile, 0600 );
		echo base64_encode( sodium_crypto_sign_publickey( $pair ) ), "\n";
		break;

	case 'pubkey':
		echo base64_encode( sodium_crypto_sign_publickey_from_secretkey( read_secret_key( $args[0] ?? '' ) ) ), "\n";
		break;

	case 'package':
		[ $src, $zipfile ] = $args + [ '', '' ];
		$src = rtrim( realpath( $src ) ?: fail( "no such directory: $src" ), '/' );
		$files = [];
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$files[] = substr( $file->getPathname(), strlen( $src ) + 1 );
			}
		}
		sort( $files, SORT_STRING );
		@unlink( $zipfile );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zipfile, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
			fail( "cannot create $zipfile" );
		}
		foreach ( $files as $relative ) {
			$name = 'shouse/' . $relative;
			$zip->addFile( "$src/$relative", $name );
			$zip->setMtimeName( $name, 315532800 ); // 1980-01-01, so the zip only changes when content does.
		}
		$zip->close();
		echo count( $files ), " files -> $zipfile\n";
		break;

	case 'manifest':
		[ $zipfile, $out, $version, $download_url, $changelog_file ] = $args + [ '', '', '', '', '' ];
		if ( plugin_header( $zipfile, 'Version' ) !== $version ) {
			fail( "zip header version does not match $version" );
		}
		$changelog = '';
		if ( is_readable( $changelog_file ) && preg_match( '/^## ' . preg_quote( $version, '/' ) . '\b.*?$(.*?)(?=^## |\z)/ms', (string) file_get_contents( $changelog_file ), $m ) ) {
			$changelog = trim( $m[1] );
		}
		$now = time();
		$manifest = [
			'protocol'     => 2,
			'channel'      => 'release',
			'generation'   => max( $now, (int) ( is_file( $out ) ? ( json_decode( (string) file_get_contents( $out ), true )['generation'] ?? 0 ) : 0 ) + 1 ),
			'issued_at'    => $now,
			'expires_at'   => $now + 180 * 86400,
			'slug'         => 'shouse',
			'name'         => 'SafeHouse',
			'version'      => $version,
			'requires'     => plugin_header( $zipfile, 'Requires at least' ),
			'requires_php' => plugin_header( $zipfile, 'Requires PHP' ),
			'tested'       => plugin_header( $zipfile, 'Tested up to' ),
			'released'     => gmdate( 'Y-m-d', $now ),
			'homepage'     => 'https://designhouse.me/',
			'download_url' => $download_url,
			'sha256'       => hash_file( 'sha256', $zipfile ),
			'size'         => filesize( $zipfile ),
			'changelog'    => $changelog,
		];
		file_put_contents( $out, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
		echo "manifest -> $out\n";
		break;

	case 'envelope':
		[ $file, $out ] = $args + [ '', '' ];
		$signatures = [];
		foreach ( array_merge( [ "$file.sig" ], array_slice( $args, 2 ) ) as $signature_file ) {
			$encoded = trim( (string) file_get_contents( $signature_file ) );
			if ( strlen( (string) base64_decode( $encoded, true ) ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
				fail( "invalid signature in $signature_file" );
			}
			$signatures[] = $encoded;
		}
		file_put_contents( $out, json_encode( [ 'format' => 1, 'payload' => base64_encode( (string) file_get_contents( $file ) ), 'signatures' => array_values( array_unique( $signatures ) ) ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );
		break;

	case 'sign':
		[ $keyfile, $file ] = $args + [ '', '' ];
		$signature = sodium_crypto_sign_detached( (string) file_get_contents( $file ), read_secret_key( $keyfile ) );
		file_put_contents( "$file.sig", base64_encode( $signature ) . "\n" );
		echo "signature -> $file.sig\n";
		break;

	case 'verify':
		[ $public_b64, $file ] = $args + [ '', '' ];
		$ok = sodium_crypto_sign_verify_detached(
			(string) base64_decode( trim( (string) file_get_contents( "$file.sig" ) ), true ),
			(string) file_get_contents( $file ),
			(string) base64_decode( $public_b64, true )
		);
		echo $ok ? "signature OK\n" : "signature INVALID\n";
		exit( $ok ? 0 : 1 );

	default:
		fail( 'usage: keygen|pubkey|package|manifest|sign|verify (see header)' );
}
