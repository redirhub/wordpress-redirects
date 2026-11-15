<?php
/**
 * One report line for the `@hits` endpoint.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The Apache combined line with the absolute URL (integration-spec, "Reports"; spec/v1/reports.txt
 * is the golden sample):
 *
 *     203.0.113.9 - - [31/Oct/2026:19:10:00 +0000] "GET https://host/path?q HTTP/1.1" 301 0 "referer" "agent" file=host/path destination=https://…
 */
final class HitLine {

	/** The Referer and User-Agent are cut to this many bytes. */
	const MAX_HEADER_FIELD = 1024;

	/**
	 * Formats one visit.
	 *
	 * @param array{ip: ?string, method: string, scheme: string, host: string, path: string, query: string, protocol?: string, status: int, size?: int, referer: ?string, user_agent: ?string, source?: string, location?: string} $hit
	 *        The visit: `host` as listed (lowercase, no port), `path` and `query` escaped (Path::escape()), `query` without `?`.
	 * @param int $time The Unix time of the visit.
	 */
	public static function format( array $hit, int $time ): string {
		$target   = $hit['scheme'] . '://' . $hit['host'] . $hit['path'] . ( '' === $hit['query'] ? '' : '?' . $hit['query'] );
		$protocol = isset( $hit['protocol'] ) && preg_match( '#^HTTP/[0-9.]+$#', $hit['protocol'] ) ? $hit['protocol'] : 'HTTP/1.1';
		$ip       = null === $hit['ip'] || '' === $hit['ip'] ? '-' : self::escape( $hit['ip'] );

		$line = sprintf(
			'%s - - [%s] "%s %s %s" %d %d "%s" "%s"',
			$ip,
			gmdate( 'd/M/Y:H:i:s', $time ) . ' +0000',
			self::escape( $hit['method'] ),
			self::field( $target ),
			$protocol,
			$hit['status'],
			$hit['size'] ?? 0,
			self::or_dash( self::escape( self::truncate( (string) $hit['referer'] ) ) ),
			self::or_dash( self::escape( self::truncate( (string) $hit['user_agent'] ) ) )
		);
		if ( isset( $hit['source'] ) ) {
			$line .= ' file=' . self::field( $hit['source'] ) . ' destination=' . self::or_dash( self::field( (string) ( $hit['location'] ?? '' ) ) );
		}

		return $line;
	}

	/**
	 * Backslash-escapes `"` and `\` and writes control characters as `\xHH`: a value never
	 * breaks the line.
	 *
	 * @param string $value A header value or the method.
	 */
	private static function escape( string $value ): string {
		return (string) preg_replace_callback(
			'/["\\\\\x00-\x1f\x7f]/',
			static function ( array $match ): string {
				$char = $match[0];

				return '"' === $char || '\\' === $char ? '\\' . $char : sprintf( '\\x%02x', ord( $char ) );
			},
			$value
		);
	}

	/**
	 * Percent-encodes what cannot stand in the request line or a `key=value` field: spaces,
	 * quotes, backslashes, control characters.
	 *
	 * @param string $value A URL or a rule's source.
	 */
	private static function field( string $value ): string {
		return (string) preg_replace_callback(
			'/[\x00-\x20"\\\\\x7f]/',
			static function ( array $match ): string {
				return sprintf( '%%%02X', ord( $match[0] ) );
			},
			$value
		);
	}

	/**
	 * `-` for an empty value.
	 *
	 * @param string $value Any value.
	 */
	private static function or_dash( string $value ): string {
		return '' === $value ? '-' : $value;
	}

	/**
	 * Cuts to at most MAX_HEADER_FIELD bytes, never inside a UTF-8 character.
	 *
	 * @param string $value A header value.
	 */
	private static function truncate( string $value ): string {
		if ( strlen( $value ) <= self::MAX_HEADER_FIELD ) {
			return $value;
		}
		$cut = self::MAX_HEADER_FIELD;
		while ( $cut > 0 && ( ord( $value[ $cut ] ) & 0xC0 ) === 0x80 ) {
			--$cut;
		}

		return substr( $value, 0, $cut );
	}
}
