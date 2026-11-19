<?php
/**
 * Turns the redirects other plugins keep into rows of RedirHub's import file.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: no WordPress. Each plugin's reader (Sources) hands over its redirects as plain items;
 * this keeps the ones RedirHub serves as they are (a fixed address to a fixed target, 301, 302,
 * 307 or 308) and counts the rest by reason, so the admin page can say what stays behind.
 *
 * An item: `source` (a path, `/old` or `old`, or an absolute URL), `target` (absolute, root
 * relative or relative to the site), `code`, and optionally `pattern` (regex or wildcard),
 * `condition` (matches on more than the address: login, referrer, …), `enabled`.
 */
final class Converter {

	/** The columns of RedirHub's import file. */
	const COLUMNS = array( 'source_url', 'destination', 'type', 'note' );

	/** Why an item is left out. */
	const PATTERN   = 'pattern';
	const TYPE      = 'type';
	const CONDITION = 'condition';
	const INVALID   = 'invalid';
	const DUPLICATE = 'duplicate';
	const DISABLED  = 'disabled';

	/** Codes that redirect, as RedirHub keeps them; 303 is a temporary redirect too. */
	const CODES = array(
		301 => '301',
		302 => '302',
		303 => '302',
		307 => '307',
		308 => '308',
	);

	/** @var string The site's own address: scheme, host and port, no path. */
	private $origin;

	/** @var string The site's hostname, as sources without one get it. */
	private $host;

	/** @var array<string, true> Sources already taken, across every plugin. */
	private $seen = array();

	/**
	 * @param string $home The site's home address (`home_url()`).
	 */
	public function __construct( string $home ) {
		$parts        = (array) parse_url( $home ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, no WordPress.
		$scheme       = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$this->host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port         = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$this->origin = $scheme . '://' . $this->host . $port;
	}

	/**
	 * The rows of one plugin's redirects, and what was left out.
	 *
	 * @param list<array<string, mixed>> $items The plugin's redirects.
	 * @param string                     $note  The note each row carries (where it came from).
	 * @return array{rows: list<array{0: string, 1: string, 2: string, 3: string}>, skipped: array<string, int>}
	 */
	public function convert( array $items, string $note ): array {
		$rows    = array();
		$skipped = array();
		foreach ( $items as $item ) {
			$reason = $this->reason( $item );
			if ( null === $reason ) {
				$source = $this->source( (string) $item['source'] );
				$target = $this->target( (string) $item['target'] );
				if ( null === $source || null === $target ) {
					$reason = self::INVALID;
				} elseif ( isset( $this->seen[ $source ] ) ) {
					$reason = self::DUPLICATE;
				} else {
					$this->seen[ $source ] = true;
					$rows[]                = array( $source, $target, self::CODES[ (int) $item['code'] ], $note );
					continue;
				}
			}
			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	/**
	 * Why an item cannot be kept, or null.
	 *
	 * @param array<string, mixed> $item The redirect.
	 */
	private function reason( array $item ): ?string {
		if ( array_key_exists( 'enabled', $item ) && ! $item['enabled'] ) {
			return self::DISABLED;
		}
		if ( ! empty( $item['condition'] ) ) {
			return self::CONDITION;
		}
		if ( ! empty( $item['pattern'] ) ) {
			return self::PATTERN;
		}
		if ( ! isset( self::CODES[ (int) ( $item['code'] ?? 0 ) ] ) ) {
			return self::TYPE;
		}
		if ( '' === trim( (string) ( $item['source'] ?? '' ) ) || '' === trim( (string) ( $item['target'] ?? '' ) ) ) {
			return self::INVALID;
		}

		return null;
	}

	/**
	 * The source as RedirHub writes it: `host/path?query`, without a scheme. A path gets the
	 * site's hostname; an absolute URL keeps its own.
	 *
	 * @param string $source The plugin's source.
	 */
	private function source( string $source ): ?string {
		$source = trim( $source );
		if ( preg_match( '#^https?://#i', $source ) ) {
			$parts = (array) parse_url( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, no WordPress.
			if ( empty( $parts['host'] ) ) {
				return null;
			}
			$host = strtolower( (string) $parts['host'] );
			$path = ( isset( $parts['path'] ) ? (string) $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		} else {
			$host = $this->host;
			$path = $source;
		}
		$path = '/' . ltrim( $path, '/' );
		if ( '' === $host || preg_match( '/[\s<>"]/', $path ) ) {
			return null;
		}

		return $host . $path;
	}

	/**
	 * The target as an absolute URL: one on the site gets its address.
	 *
	 * @param string $target The plugin's target.
	 */
	private function target( string $target ): ?string {
		$target = trim( $target );
		if ( preg_match( '#^https?://[^/\s]+#i', $target ) ) {
			return preg_match( '/\s/', $target ) ? null : $target;
		}
		if ( 0 === strpos( $target, '//' ) ) {
			return $this->target( 'https:' . $target );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $target ) || preg_match( '/\s/', $target ) ) {
			return null;
		}

		return $this->origin . '/' . ltrim( $target, '/' );
	}

	/**
	 * The import file.
	 *
	 * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows The rows.
	 */
	public static function csv( array $rows ): string {
		$lines = array( implode( ',', self::COLUMNS ) );
		foreach ( $rows as $row ) {
			$lines[] = implode( ',', array_map( array( self::class, 'cell' ), $row ) );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One CSV cell, quoted when it needs to be. Every cell starts with a hostname, `http` or the
	 * plugin's name, so none reads as a spreadsheet formula.
	 *
	 * @param string $cell The value.
	 */
	private static function cell( string $cell ): string {
		if ( preg_match( '/[",\n\r]/', $cell ) ) {
			return '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return $cell;
	}
}
