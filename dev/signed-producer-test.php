<?php
/** No-network tests for signed artifacts. Run with PHP sodium + zip, e.g. the pinned release image. */
// phpcs:ignoreFile -- development regression runner.
$root = dirname( __DIR__ );
$work = sys_get_temp_dir() . '/shouse-producer-' . bin2hex( random_bytes( 8 ) );
mkdir( $work, 0700 );
$checks = 0;
$check = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) throw new RuntimeException( $label );
	++$checks;
	echo "PASS $label\n";
};
$run = static function ( array $args, bool $success = true ) use ( $root, $check ): string {
	$process = proc_open( array_merge( [ PHP_BINARY ], $args ), [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, $root );
	if ( ! is_resource( $process ) ) throw new RuntimeException( 'Cannot start PHP test command' );
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	$status = proc_close( $process );
	if ( ( 0 === $status ) !== $success ) throw new RuntimeException( "Unexpected command result $status: $out" );
	return $out;
};
try {
	$keyfile = "$work/key";
	$run( [ 'dev/release-tool.php', 'keygen', $keyfile ] );
	$key = base64_decode( trim( file_get_contents( $keyfile ) ), true );
	$public = sodium_crypto_sign_publickey_from_secretkey( $key );
	$feed = [];
	for ( $i = 1; $i <= 5; ++$i ) {
		$id = sprintf( '11111111-1111-4111-8111-%012d', $i );
		$feed[ $id ] = [ 'id' => $id, 'title' => "Regression fixture $i", 'published' => gmdate( 'Y-m-d H:i:s' ),
			'software' => [ [ 'type' => 'plugin', 'slug' => 'safehouse-synthetic-fixture', 'name' => 'Fixture', 'patched' => true, 'patched_versions' => [ '2.0' ],
				'affected_versions' => [ [ 'from_version' => '*', 'from_inclusive' => true, 'to_version' => '2.0', 'to_inclusive' => false ] ] ] ],
			'cvss' => [ 'score' => 9.0 ], 'references' => [], 'copyrights' => [ 'message' => '' ],
		];
	}
	$input = "$work/feed.json"; $dir = "$work/advisories";
	file_put_contents( $input, json_encode( $feed, JSON_THROW_ON_ERROR ) );
	$args = [ 'dev/cve-watch.php', '--dry-run', "--feed=$input", "--advisories=$dir", "--advisory-key=$keyfile", '--min-advisories=1' ];
	$run( $args );
	$index_body = file_get_contents( "$dir/index.json" ); $index = json_decode( $index_body, true );
	$env = json_decode( file_get_contents( "$dir/feed.json" ), true );
	$check( $index['protocol'] === 2 && $index['record_count'] === 5 && count( $index['shards'] ) === 256, 'complete protocol-2 index produced' );
	$check( base64_decode( $env['payload'], true ) === $index_body && sodium_crypto_sign_verify_detached( base64_decode( $env['signatures'][0], true ), $index_body, $public ), 'atomic envelope signs exact legacy index bytes' );
	$check( sodium_crypto_sign_verify_detached( base64_decode( trim( file_get_contents( "$dir/index.json.sig" ) ), true ), $index_body, $public ), 'legacy detached signature still works' );
	foreach ( $index['shards'] as $name => $hash ) {
		if ( hash_file( 'sha256', "$dir/$name.json" ) !== $hash || file_get_contents( "$dir/$name.json" ) !== file_get_contents( "$dir/sha256/$hash.json" ) ) throw new RuntimeException( 'Immutable and legacy shard mismatch' );
	}
	$check( true, 'all legacy and immutable shards match signed hashes' );
	$good = file_get_contents( "$dir/feed.json" );
	foreach ( [ [], [ 'error' => 'rate limited' ], [ 'bad' => [ 'id' => 'x', 'title' => 'broken', 'software' => [] ] ] ] as $bad ) {
		file_put_contents( $input, json_encode( $bad ) ); $run( $args, false );
		$check( file_get_contents( "$dir/feed.json" ) === $good, 'invalid/empty upstream data preserves current signed publication' );
	}
	file_put_contents( $input, json_encode( array_slice( $feed, 0, 2, true ) ) );
	$run( $args, false );
	$check( file_get_contents( "$dir/feed.json" ) === $good, 'unapproved sharp record drop preserves publication' );
	$run( array_merge( $args, [ '--approve-count=2' ] ) );
	$next = json_decode( file_get_contents( "$dir/index.json" ), true );
	$check( $next['record_count'] === 2 && $next['generation'] > $index['generation'], 'reviewed exact-count correction advances generation' );
	$run( array_merge( $args, [ "--previous-index=$work/missing.json" ] ), false );
	$check( true, 'missing explicit previous index fails closed' );
	$run( array_filter( $args, static fn( $arg ) => $arg !== '--min-advisories=1' ), false );
	$check( true, 'production minimum rejects tiny fixture feeds' );

	mkdir( "$work/plugin" );
	file_put_contents( "$work/plugin/shouse.php", "<?php\n/**\n * Version: 9.9.9\n * Requires at least: 6.6\n * Requires PHP: 8.1\n * Tested up to: 7.1\n */\n" );
	file_put_contents( "$work/changelog.md", "## 9.9.9\n\nFixture release.\n" );
	$zip = "$work/shouse-9.9.9.zip"; $manifest = "$work/manifest.json";
	$run( [ 'dev/release-tool.php', 'package', "$work/plugin", $zip ] );
	$manifest_args = [ 'dev/release-tool.php', 'manifest', $zip, $manifest, '9.9.9', 'https://fixture.invalid/shouse-9.9.9.zip', "$work/changelog.md" ];
	$run( $manifest_args );
	$run( [ 'dev/release-tool.php', 'sign', $keyfile, $manifest ] );
	$run( [ 'dev/release-tool.php', 'envelope', $manifest, "$work/release.json" ] );
	$m = json_decode( file_get_contents( $manifest ), true );
	$check( $m['size'] === filesize( $zip ) && $m['sha256'] === hash_file( 'sha256', $zip ), 'release metadata signs package size and hash' );
	$check( $m['expires_at'] - $m['issued_at'] === 180 * 86400 && $m['released'] === gmdate( 'Y-m-d', $m['issued_at'] ), 'release freshness fields use one clock snapshot' );
	$e = json_decode( file_get_contents( "$work/release.json" ), true );
	$check( sodium_crypto_sign_verify_detached( base64_decode( $e['signatures'][0], true ), base64_decode( $e['payload'], true ), $public ), 'release envelope signature verifies' );
	$zip_hash = hash_file( 'sha256', $zip ); $run( $manifest_args );
	$renewed = json_decode( file_get_contents( $manifest ), true );
	$check( $renewed['generation'] > $m['generation'] && hash_file( 'sha256', $zip ) === $zip_hash, 'renewal advances metadata without changing package' );
	echo "$checks producer checks passed.\n";
} finally {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) { if ( $file->isDir() ) rmdir( $file->getPathname() ); else unlink( $file->getPathname() ); }
	rmdir( $work );
}
