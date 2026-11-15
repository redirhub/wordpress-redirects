<?php
/**
 * Hostnames, paths and ETags in the forms the rules file format v1 compares them in
 * (redirhub/integration-spec, "Matching").
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pure string helpers: nothing here reads the request or WordPress.
 */
final class Path {

	/**
	 * The form a path is matched in, as on RedirHub's edge: percent-decoded bytewise (`%c3%bc`,
	 * `%C3%BC` and a raw `ü` are one path), leading and trailing slashes trimmed (`/old/` and
	 * `//old` are `/old`), the root `/`. A path with a malformed escape is matched as written.
	 *
	 * @param string $path A path as sent or as written in a rule's source, without the query.
	 */
	public static function match( string $path ): string {
		if ( false !== strpos( $path, '%' ) && ! preg_match( '/%(?![0-9A-Fa-f]{2})/', $path ) ) {
			$path = rawurldecode( $path );
		}

		return '/' . trim( $path, '/' );
	}

	/**
	 * A hostname lowercase, without its port and trailing dot (`Shop.Example.com.:443` →
	 * `shop.example.com`). A bracketed IPv6 address loses its brackets only with a port.
	 *
	 * @param string $value A `Host` header or the hostname of a `@host` line.
	 */
	public static function host( string $value ): string {
		$host = trim( $value );
		if ( '' !== $host && '[' === $host[0] ) {
			$end = strpos( $host, ']' );
			if ( false !== $end && ':' === substr( $host, $end + 1, 1 ) ) {
				$host = substr( $host, 1, $end - 1 );
			}
		} else {
			$colon = strpos( $host, ':' );
			if ( false !== $colon && strrpos( $host, ':' ) === $colon ) {
				$host = substr( $host, 0, $colon );
			}
		}

		return rtrim( strtolower( trim( $host ) ), '.' );
	}

	/**
	 * An ETag without the weak prefix and quotes, as the check path reports it.
	 *
	 * @param string $etag The `ETag` header as received.
	 */
	public static function etag( string $etag ): string {
		$etag = trim( $etag );
		if ( 0 === strpos( $etag, 'W/' ) ) {
			$etag = substr( $etag, 2 );
		}
		$etag = trim( $etag, '"' );

		// An entity tag is visible ASCII (RFC 9110); anything else is no ETag at all.
		return preg_match( '/^[\x21\x23-\x7e]*$/', $etag ) ? $etag : '';
	}

	/**
	 * An ETag header as received, fit to send back in `If-None-Match`: printable ASCII only,
	 * else empty.
	 *
	 * @param string $header The `ETag` header.
	 */
	public static function etag_header( string $header ): string {
		$header = trim( $header );

		return preg_match( '/^[\x20-\x7e]*$/', $header ) ? $header : '';
	}

	/**
	 * A path or query as a URL carries it: bytes that may not stand in a URL (non-ASCII, spaces,
	 * quotes, `<`, `>`, control characters ...) percent-encoded, escapes already there kept. The
	 * catch-all's `p` flag appends the path in this form, and reports name the request with it.
	 *
	 * @param string $value A path or query as sent.
	 * @param bool   $query Whether the value is a query string (which may also hold `?`).
	 */
	public static function escape( string $value, bool $query = false ): string {
		$allowed = $query ? "A-Za-z0-9\\-._~!$&'()*+,;=:@\\/?%" : "A-Za-z0-9\\-._~!$&'()*+,;=:@\\/%";

		return (string) preg_replace_callback(
			'/[^' . $allowed . ']/',
			static function ( array $match ): string {
				return '%' . strtoupper( bin2hex( $match[0] ) );
			},
			$value
		);
	}

	/**
	 * Whether a value is an absolute `http` or `https` URL with a hostname.
	 *
	 * @param string $value Any string.
	 */
	public static function is_http_url( string $value ): bool {
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return false;
		}
		$host = parse_url( $value, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, runs before WordPress in tests.

		return is_string( $host ) && '' !== $host;
	}
}
