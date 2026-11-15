<?php
/**
 * Reads a rules file, format v1.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The parser of redirhub/integration-spec format v1 ("Rules file format v1"). Lines that cannot
 * be parsed, rules with an unknown flag letter and unknown `@` directives are skipped; rules for
 * hostnames without an `@host` line are dropped; the first rule for a source wins.
 */
final class Parser {

	/** The rules file format this client understands. */
	const FORMAT_MAJOR = 1;

	/** The redirect statuses a rule may carry. */
	const STATUSES = array( 301, 302, 307, 308 );

	/**
	 * Parses a rules file.
	 *
	 * @param string $body The file as downloaded.
	 * @param string $etag The `ETag` header it came with.
	 * @throws UnknownFormat When `@format` names a major version other than 1.
	 */
	public static function parse( string $body, string $etag = '' ): RuleSet {
		$data    = array(
			'etag'       => Path::etag( $etag ),
			'refresh'    => RuleSet::DEFAULT_REFRESH,
			'hits'       => null,
			'check'      => RuleSet::DEFAULT_CHECK_PATH,
			'powered_by' => null,
			'rules'      => 0,
			'hosts'      => array(),
		);
		$pending = array();

		foreach ( explode( "\n", $body ) as $line ) {
			$line = rtrim( $line, "\r" );
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( '@' === $line[0] ) {
				self::directive( $data, $line );
				continue;
			}
			$parsed = self::rule( $line );
			if ( null !== $parsed ) {
				$pending[] = $parsed;
			}
		}

		foreach ( $pending as list( $host, $path, $rule ) ) {
			if ( ! isset( $data['hosts'][ $host ] ) ) {
				continue;
			}
			if ( null === $path ) {
				if ( null === $data['hosts'][ $host ]['all'] ) {
					$data['hosts'][ $host ]['all'] = $rule;
					++$data['rules'];
				}
			} elseif ( ! isset( $data['hosts'][ $host ]['exact'][ $path ] ) ) {
				$rule[3]                                  = false; // `p` applies to the catch-all only.
				$data['hosts'][ $host ]['exact'][ $path ] = $rule;
				++$data['rules'];
			}
		}

		return new RuleSet( $data );
	}

	/**
	 * Applies one `@` directive.
	 *
	 * @param array<string, mixed> $data The rule set being built.
	 * @param string               $line The line, without its line ending.
	 * @throws UnknownFormat For an `@format` other than 1.
	 */
	private static function directive( array &$data, string $line ): void {
		$fields = preg_split( '/\s+/', trim( $line ) );
		$name   = $fields[0];
		$value  = $fields[1] ?? null;

		switch ( $name ) {
			case '@format':
				$major = null === $value ? '' : explode( '.', $value )[0];
				if ( ! preg_match( '/^[+-]?\d+$/', $major ) || self::FORMAT_MAJOR !== (int) $major ) {
					throw new UnknownFormat( 'unknown rules file format: ' . (int) $major );
				}
				break;
			case '@refresh':
				if ( null !== $value && preg_match( '/^[+-]?\d+$/', $value ) ) {
					$data['refresh'] = min( max( (int) $value, RuleSet::MIN_REFRESH ), RuleSet::MAX_REFRESH );
				}
				break;
			case '@hits':
				if ( null !== $value && Path::is_http_url( $value ) ) {
					$data['hits'] = $value;
				}
				break;
			case '@check':
				if ( null !== $value && 0 === strpos( $value, '/' ) ) {
					$data['check'] = $value;
				}
				break;
			case '@powered-by':
				$space = strpos( $line, ' ' );
				$text  = false === $space ? '' : trim( substr( $line, $space + 1 ) );
				if ( strlen( $text ) <= 128 && preg_match( '/^[\x20-\x7e]*$/', $text ) ) {
					$data['powered_by'] = '' === $text ? null : $text;
				}
				break;
			case '@host':
				if ( null === $value ) {
					break;
				}
				$host = Path::host( $value );
				if ( '' === $host || preg_match( '#[/*]#', $host ) || isset( $data['hosts'][ $host ] ) ) {
					break;
				}
				$entry = array(
					'token'     => '',
					'report404' => false,
					'exact'     => array(),
					'all'       => null,
				);
				foreach ( array_slice( $fields, 2 ) as $option ) {
					if ( 0 === strpos( $option, 'check=' ) ) {
						$entry['token'] = substr( $option, 6 );
					} elseif ( 'report404' === $option ) {
						$entry['report404'] = true;
					}
				}
				$data['hosts'][ $host ] = $entry;
				break;
		}
	}

	/**
	 * One rule line: `source<TAB>target[<TAB>status[<TAB>flags[<TAB>options]]]`.
	 *
	 * @param string $line The line.
	 * @return array{0: string, 1: ?string, 2: array}|null The hostname, the match path (null for the catch-all) and the stored rule.
	 */
	private static function rule( string $line ): ?array {
		$columns = explode( "\t", $line );
		if ( count( $columns ) < 2 ) {
			return null;
		}
		$source = trim( $columns[0] );
		$target = trim( $columns[1] );
		$split  = self::split_source( $source );
		if ( null === $split || ! Path::is_http_url( $target ) ) {
			return null;
		}

		$status = trim( $columns[2] ?? '' );
		if ( Rule::FRAME === $status ) {
			$code = Rule::FRAME;
		} elseif ( '' === $status ) {
			$code = 301;
		} elseif ( preg_match( '/^[+-]?\d+$/', $status ) && in_array( (int) $status, self::STATUSES, true ) ) {
			$code = (int) $status;
		} else {
			return null;
		}

		$append = false;
		$query  = false;
		$flags  = trim( $columns[3] ?? '' );
		for ( $i = 0, $n = strlen( $flags ); $i < $n; $i++ ) {
			if ( 'p' === $flags[ $i ] ) {
				$append = true;
			} elseif ( 'q' === $flags[ $i ] ) {
				$query = true;
			} else {
				return null; // Flags are must-understand.
			}
		}

		$options = array();
		foreach ( preg_split( '/ +/', trim( $columns[4] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $pair ) {
			$eq = strpos( $pair, '=' );
			if ( false !== $eq && $eq > 0 ) {
				$options[ substr( $pair, 0, $eq ) ] = substr( $pair, $eq + 1 );
			}
		}

		list( $host, $path ) = $split;

		return array(
			$host,
			'/*' === $path ? null : Path::match( $path ),
			array( $source, $target, $code, $append, $query, $options ),
		);
	}

	/**
	 * `host/path?query` into hostname and path; the query is ignored. A scheme, no path, a space,
	 * `"` or `\` is not a source.
	 *
	 * @param string $source The source column.
	 * @return array{0: string, 1: string}|null
	 */
	private static function split_source( string $source ): ?array {
		if ( false !== strpos( $source, '://' ) || preg_match( '/[ "\\\\]/', $source ) ) {
			return null;
		}
		$end   = strcspn( $source, '?#' );
		$rest  = substr( $source, 0, $end );
		$slash = strpos( $rest, '/' );
		if ( false === $slash ) {
			return null;
		}
		$host = Path::host( substr( $rest, 0, $slash ) );

		return '' === $host ? null : array( $host, substr( $rest, $slash ) );
	}
}
