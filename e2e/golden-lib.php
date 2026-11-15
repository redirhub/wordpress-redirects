<?php
/**
 * Helpers of the end-to-end scripts.
 */

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

/**
 * An HTTP request to the site, with the request target sent as is.
 *
 * @return array{0: int, 1: array<string, string>, 2: string}
 */
function http( string $base, string $method, string $url, array $headers = array(), ?string $body = null ): array {
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
			CURLOPT_TIMEOUT        => 30,
		)
	);
	if ( null !== $body ) {
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $body );
	}
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

/** The median time to first byte of a URL, in seconds. */
function timing( string $base, string $url, int $runs ): float {
	preg_match( '#^(https?)://([^/?]*)(.*)$#', $url, $parts );
	$times = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$curl = curl_init( $base . $parts[3] );
		curl_setopt_array( $curl, array( CURLOPT_HTTPHEADER => array( 'Host: ' . $parts[2] ), CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false ) );
		curl_exec( $curl );
		$times[] = curl_getinfo( $curl, CURLINFO_STARTTRANSFER_TIME );
	}
	sort( $times );

	return $times[ (int) ( $runs / 2 ) ];
}

function post( string $url, string $body ): string {
	return (string) file_get_contents( $url, false, stream_context_create( array( 'http' => array( 'method' => 'POST', 'content' => $body, 'header' => 'Content-Type: text/plain' ) ) ) );
}

function docker( string $service, string $command ): string {
	return (string) shell_exec( 'cd ' . escapeshellarg( __DIR__ ) . ' && docker compose exec -T -u root ' . $service . ' sh -c ' . escapeshellarg( $command ) . ' 2>&1' );
}

function wp_eval( string $code ): string {
	return (string) shell_exec( 'cd ' . escapeshellarg( __DIR__ ) . ' && docker compose exec -T cli wp eval ' . escapeshellarg( $code ) . ' 2>/dev/null' );
}

function mock_log(): array {
	return array_map( 'json_decode', array_values( array_filter( explode( "\n", (string) file_get_contents( 'http://127.0.0.1:18081/control/log' ) ) ) ) );
}

function section( string $title ): void {
	echo "\n{$title}\n";
}

function check( string $what, bool $ok ): void {
	if ( $ok ) {
		++$GLOBALS['passed'];
		echo "  ok   {$what}\n";
	} else {
		++$GLOBALS['failed'];
		echo "  FAIL {$what}\n";
	}
}

function finish(): void {
	printf( "\n%d passed, %d failed\n", $GLOBALS['passed'], $GLOBALS['failed'] );
	exit( $GLOBALS['failed'] > 0 ? 1 : 0 );
}
