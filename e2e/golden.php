<?php
/**
 * The golden sample (spec/v1/requests.tsv) against a real WordPress with the plugin, over HTTP:
 * every answer of the plugin must match the sample byte for byte, every request the sample leaves
 * to the site must reach WordPress untouched, and the reports RedirHub receives must be the
 * sample's lines (the visitor's address and the time aside).
 *
 * Usage: php e2e/golden.php [wordpress base, default http://127.0.0.1:18080] [mock base, default http://127.0.0.1:18081]
 */

$wp   = $argv[1] ?? 'http://127.0.0.1:18080';
$mock = $argv[2] ?? 'http://127.0.0.1:18081';
$spec = dirname( __DIR__ ) . '/spec/v1/';

function http( string $base, string $method, string $url, array $headers = array() ): array {
	preg_match( '#^(https?)://([^/?]*)(.*)$#', $url, $parts );
	$target = '' === $parts[3] ? '/' : $parts[3];
	$curl   = curl_init( $base . '/' );
	$sent   = array( 'Host: ' . $parts[2], 'X-Forwarded-Proto: ' . $parts[1] );
	foreach ( $headers as $name => $value ) {
		if ( null !== $value ) {
			$sent[] = $name . ': ' . $value;
		}
	}
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_REQUEST_TARGET => $target,
			CURLOPT_HTTPHEADER     => $sent,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_POSTFIELDS     => 'POST' === $method ? 'x=1' : null,
		)
	);
	$raw  = (string) curl_exec( $curl );
	$size = curl_getinfo( $curl, CURLINFO_HEADER_SIZE );
	$code = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	$head = array();
	foreach ( explode( "\r\n", substr( $raw, 0, $size ) ) as $line ) {
		if ( false !== strpos( $line, ':' ) ) {
			list( $name, $value ) = explode( ':', $line, 2 );
			$head[ strtolower( trim( $name ) ) ] = trim( $value );
		}
	}

	return array( $code, $head, substr( $raw, $size ) );
}

function normalise( string $line ): string {
	return (string) preg_replace( '/^\S+ - - \[[^\]]+\]/', 'IP - - [TIME]', $line );
}

$fail    = 0;
$pass    = 0;
$expect  = array();
$lines   = array_values( array_filter( explode( "\n", file_get_contents( $spec . 'requests.tsv' ) ) ) );
$header  = explode( "\t", array_shift( $lines ) );
$reports = explode( "\n", rtrim( file_get_contents( $spec . 'reports.txt' ), "\n" ) );
file_get_contents( $mock . '/control/reset', false, stream_context_create( array( 'http' => array( 'method' => 'POST' ) ) ) );

foreach ( $lines as $line ) {
	$row = array_combine( $header, explode( "\t", $line ) );
	list( $code, $head, $body ) = http(
		$wp,
		$row['method'],
		$row['url'],
		array(
			'Referer'    => '-' === $row['referer'] ? null : $row['referer'],
			'User-Agent' => '-' === $row['user_agent'] ? '' : $row['user_agent'],
		)
	);
	$ours     = 'RedirHub' === ( $head['x-redirect-by'] ?? '' ) || ( 'check' === $row['outcome'] && 0 === strpos( $body, 'token=' ) );
	$problems = array();
	switch ( $row['outcome'] ) {
		case 'redirect':
			if ( (int) $row['status'] !== $code || ( $head['location'] ?? '' ) !== $row['location'] ) {
				$problems[] = "want {$row['status']} {$row['location']}, got {$code} " . ( $head['location'] ?? '-' );
			}
			if ( 'RedirHub (integrated)' !== ( $head['x-powered-by'] ?? '' ) ) {
				$problems[] = 'X-Powered-By missing';
			}
			break;
		case 'frame':
			$src = substr( $row['body'], 4 );
			if ( 200 !== $code || 1 !== substr_count( $body, '<iframe' ) || false === strpos( $body, 'src="' . htmlspecialchars( $src, ENT_QUOTES ) . '"' ) ) {
				$problems[] = "want a frame page of {$src}, got {$code}";
			}
			break;
		case 'check':
			$want = preg_replace( '/etag=.*$/', '', $row['body'] );
			if ( 200 !== $code || 0 !== strpos( $body, $want . 'etag=' ) || 'no-store' !== ( $head['cache-control'] ?? '' ) || 0 !== strpos( $head['content-type'] ?? '', 'text/plain' ) ) {
				$problems[] = "want 200 {$row['body']}, got {$code} {$body}";
			}
			break;
		case 'site':
			if ( $ours ) {
				$problems[] = "the plugin answered ({$code}); the site should have";
			}
			break;
	}
	if ( '-' !== $row['report'] && ( 'site' !== $row['outcome'] || 404 === $code ) ) {
		$expect[] = normalise( $reports[ (int) $row['report'] - 1 ] );
	}
	if ( array() === $problems ) {
		++$pass;
		printf( "  ok   %s (%d)\n", $row['name'], $code );
	} else {
		++$fail;
		printf( "  FAIL %s: %s\n", $row['name'], implode( '; ', $problems ) );
	}
}

// Reports of WordPress's own 404s wait for WP-Cron when PHP cannot release the visitor first.
passthru( 'cd ' . escapeshellarg( __DIR__ ) . ' && docker compose exec -T cli wp cron event run --due-now >/dev/null 2>&1' );
sleep( 2 );
$got = array_values( array_filter( explode( "\n", (string) file_get_contents( $mock . '/control/hits' ) ) ) );
$got = array_map( 'normalise', $got );
sort( $got );
sort( $expect );
if ( $got === $expect ) {
	++$pass;
	printf( "  ok   %d report lines match reports.txt\n", count( $got ) );
} else {
	++$fail;
	echo "  FAIL report lines differ\n    missing:\n      " . implode( "\n      ", array_diff( $expect, $got ) ) . "\n    unexpected:\n      " . implode( "\n      ", array_diff( $got, $expect ) ) . "\n";
}

$log = array_map( 'json_decode', array_filter( explode( "\n", (string) file_get_contents( $mock . '/control/log' ) ) ) );
foreach ( $log as $entry ) {
	if ( '/_edge/hits' === $entry->path && ( 'gzip' !== $entry->enc || 0 !== strpos( $entry->ua, 'wordpress-redirects/' ) ) ) {
		++$fail;
		echo "  FAIL a report was sent without gzip or the plugin's User-Agent: " . json_encode( $entry ) . "\n";
		break;
	}
}

printf( "%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
