<?php
/**
 * A stand-in for RedirHub in the end-to-end tests (PHP's built-in server):
 *
 *   GET  /rules/<name>.txt          a rules file, with ETag / If-None-Match → 304
 *   POST /_edge/hits                reports: checks the key, gunzips, keeps the lines (answers 204)
 *   POST /control/rules?name=<name> sets a rules file (body)
 *   POST /control/spec?name=<name>  sets a rules file to spec/v1/rules.txt with @hits pointing here
 *   POST /control/status?code=<n>   answers rules downloads with this status from now on (0: normal)
 *   GET  /control/hits              the report lines received, one per line
 *   GET  /control/log               the requests received (method, path, User-Agent, If-None-Match)
 *   POST /control/reset             forgets the hits and the log
 */

$state  = '/state';
$path   = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method = $_SERVER['REQUEST_METHOD'];
$name   = preg_replace( '/[^A-Za-z0-9_-]/', '', $_GET['name'] ?? 'site' );

function log_request( string $state, string $path, string $method ): void {
	file_put_contents(
		$state . '/log',
		json_encode(
			array(
				'method' => $method,
				'path'   => $path,
				'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
				'inm'    => $_SERVER['HTTP_IF_NONE_MATCH'] ?? '',
				'auth'   => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
				'enc'    => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
			)
		) . "\n",
		FILE_APPEND
	);
}

if ( preg_match( '#^/rules/([A-Za-z0-9_-]+)\.txt$#', $path, $m ) ) {
	log_request( $state, $path, $method );
	$forced = (int) @file_get_contents( $state . '/status' );
	if ( $forced > 0 ) {
		http_response_code( $forced );
		exit;
	}
	$file = $state . '/rules-' . $m[1] . '.txt';
	if ( ! is_file( $file ) ) {
		http_response_code( 404 );
		exit;
	}
	$body = file_get_contents( $file );
	$etag = '"' . md5( $body ) . '"';
	header( 'ETag: ' . $etag );
	header( 'Cache-Control: max-age=60' );
	if ( ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) === $etag ) {
		http_response_code( 304 );
		exit;
	}
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo $body;
	exit;
}

if ( '/_edge/hits' === $path && 'POST' === $method ) {
	log_request( $state, $path, $method );
	if ( ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) !== 'Bearer ' . getenv( 'MOCK_KEY' ) ) {
		http_response_code( 401 );
		exit;
	}
	$body = file_get_contents( 'php://input' );
	if ( 'gzip' === ( $_SERVER['HTTP_CONTENT_ENCODING'] ?? '' ) ) {
		$body = gzdecode( $body );
	}
	file_put_contents( $state . '/hits', $body, FILE_APPEND );
	http_response_code( 204 );
	exit;
}

switch ( $method . ' ' . $path ) {
	case 'POST /control/rules':
		file_put_contents( $state . '/rules-' . $name . '.txt', file_get_contents( 'php://input' ) );
		break;
	case 'POST /control/spec':
		$host  = $_SERVER['HTTP_HOST'];
		$rules = str_replace( '@hits https://edge.example.test/_edge/hits', '@hits ' . ( $_GET['hits'] ?? 'http://mock:8080/_edge/hits' ), file_get_contents( '/spec/rules.txt' ) );
		file_put_contents( $state . '/rules-' . $name . '.txt', $rules );
		break;
	case 'POST /control/status':
		file_put_contents( $state . '/status', (string) (int) ( $_GET['code'] ?? 0 ) );
		break;
	case 'GET /control/hits':
		header( 'Content-Type: text/plain' );
		echo @file_get_contents( $state . '/hits' );
		break;
	case 'GET /control/log':
		header( 'Content-Type: text/plain' );
		echo @file_get_contents( $state . '/log' );
		break;
	case 'POST /control/reset':
		@unlink( $state . '/hits' );
		@unlink( $state . '/log' );
		break;
	default:
		http_response_code( 404 );
}
