<?php
/**
 * The few GitHub REST endpoints dev/cve-watch.php uses, kept in a JSON file: for dev/cve-watch-test.sh.
 * php -S 127.0.0.1:8080 dev/github-mock.php, with GITHUB_MOCK_STATE pointing at a writable JSON file.
 *
 * @package SafeHouse
 */

// phpcs:ignoreFile -- test double, never loaded by WordPress.

$file  = (string) getenv( 'GITHUB_MOCK_STATE' );
$state = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : [ 'issues' => [], 'comments' => [], 'labels' => [] ];
$path  = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$input = json_decode( (string) file_get_contents( 'php://input' ), true ) ?? [];

function reply( int $status, mixed $data ): never {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

function render( array $issue ): array {
	return $issue + [ 'html_url' => 'https://github.example/issues/' . $issue['number'] ];
}

$save = static function () use ( &$state, $file ): void {
	file_put_contents( $file, json_encode( $state, JSON_PRETTY_PRINT ) );
};

if ( ! preg_match( '#^/repos/[^/]+/[^/]+(/.*)$#', $path, $m ) ) {
	reply( 404, [ 'message' => 'Not Found' ] );
}
$route  = $m[1];
$method = $_SERVER['REQUEST_METHOD'];

if ( 'GET' === $method && '/issues' === $route ) {
	$page = max( 1, (int) ( $_GET['page'] ?? 1 ) );
	$all  = array_values( array_filter( $state['issues'], static fn( $i ) => in_array( (string) ( $_GET['labels'] ?? '' ), array_column( $i['labels'], 'name' ), true ) ) );
	reply( 200, array_map( 'render', array_slice( array_reverse( $all ), ( $page - 1 ) * 100, 100 ) ) );
}
if ( 'POST' === $method && '/labels' === $route ) {
	if ( in_array( $input['name'], $state['labels'], true ) ) {
		reply( 422, [ 'message' => 'Validation Failed' ] );
	}
	$state['labels'][] = $input['name'];
	$save();
	reply( 201, [ 'name' => $input['name'] ] );
}
if ( 'POST' === $method && '/issues' === $route ) {
	$number                       = count( $state['issues'] ) + 1;
	$state['issues'][ $number - 1 ] = [
		'number' => $number,
		'title'  => $input['title'],
		'body'   => $input['body'],
		'state'  => 'open',
		'labels' => array_map( static fn( $l ) => [ 'name' => $l ], $input['labels'] ?? [] ),
	];
	$save();
	reply( 201, render( $state['issues'][ $number - 1 ] ) );
}
if ( preg_match( '#^/issues/(\d+)(/comments)?$#', $route, $n ) && isset( $state['issues'][ $n[1] - 1 ] ) ) {
	$issue = &$state['issues'][ $n[1] - 1 ];
	if ( 'PATCH' === $method && empty( $n[2] ) ) {
		foreach ( [ 'title', 'body', 'state', 'state_reason' ] as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$issue[ $field ] = $input[ $field ];
			}
		}
		if ( isset( $input['labels'] ) ) {
			$issue['labels'] = array_map( static fn( $l ) => [ 'name' => $l ], $input['labels'] );
		}
		$save();
		reply( 200, render( $issue ) );
	}
	if ( 'POST' === $method && ! empty( $n[2] ) ) {
		$state['comments'][] = [ 'issue' => (int) $n[1], 'body' => $input['body'] ];
		$save();
		reply( 201, [ 'id' => count( $state['comments'] ) ] );
	}
}
reply( 404, [ 'message' => 'Not Found' ] );
