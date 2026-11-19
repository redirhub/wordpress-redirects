<?php
/**
 * One parsed copy of a rules file.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A parsed rules file, held as one nested array of scalars, which Store keeps as data: the
 * exact paths split into buckets (split()), read back one bucket at a time (lazy()).
 *
 *     etag, refresh, hits, check, powered_by, rules (count)
 *     hosts => [ hostname => [ token, report404, exact => [ match path => rule ], all => rule|null ] ]
 *
 * A rule is `[ source, target, status, append_path, keep_query, options ]` (Rule).
 */
final class RuleSet {

	/** The check path used when the file has no `@check` directive. */
	const DEFAULT_CHECK_PATH = '/_redirhub/check';

	/** Poll interval bounds for `@refresh`, in seconds. */
	const DEFAULT_REFRESH = 60;
	const MIN_REFRESH     = 30;
	const MAX_REFRESH     = 3600;

	/**
	 * The stored form.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/** How many parts a stored copy's paths are split into (Store keeps one per option). */
	const BUCKETS = 256;

	/**
	 * Reads one part of the exact paths when the copy was loaded without them (Store), or null.
	 *
	 * @var callable(string): array<string, array<string, mixed>>|null
	 */
	private $loader = null;

	/** @var array<string, array<string, array<string, mixed>>> The parts read so far, by bucket. */
	private $buckets = array();

	/**
	 * A copy whose exact paths are read one part at a time: `$summary` holds everything but each
	 * host's `exact`, and `$loader` returns a bucket's paths, host by host. A request reads the
	 * one part its path is in, however many rules there are.
	 *
	 * @param array<string, mixed>                                  $summary The copy without the paths.
	 * @param callable(string): array<string, array<string, mixed>> $loader  A bucket's paths.
	 */
	public static function lazy( array $summary, callable $loader ): self {
		$set         = new self( $summary );
		$set->loader = $loader;

		return $set;
	}

	/**
	 * The bucket of a path on a host: two hex digits.
	 *
	 * @param string $host The hostname.
	 * @param string $key  The path, as Path::match() keys it.
	 */
	public static function bucket( string $host, string $key ): string {
		return substr( md5( $host . ' ' . $key ), 0, 2 );
	}

	/**
	 * The copy without its paths, and its paths by bucket and host: what Store writes.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, array<string, array<string, mixed>>>}
	 */
	public function split(): array {
		$summary = $this->to_array();
		$buckets = array();
		foreach ( $summary['hosts'] as $host => $entry ) {
			foreach ( $entry['exact'] ?? array() as $key => $stored ) {
				$buckets[ self::bucket( (string) $host, (string) $key ) ][ $host ][ $key ] = $stored;
			}
			unset( $summary['hosts'][ $host ]['exact'] );
		}

		return array( $summary, $buckets );
	}

	/**
	 * The exact paths of a host in the bucket of a key.
	 *
	 * @param string $host The hostname.
	 * @param string $key  The path, as Path::match() keys it.
	 * @return array<string, array<string, mixed>>
	 */
	private function paths( string $host, string $key ): array {
		if ( null === $this->loader ) {
			return $this->data['hosts'][ $host ]['exact'] ?? array();
		}
		$bucket = self::bucket( $host, $key );
		if ( ! isset( $this->buckets[ $bucket ] ) ) {
			$this->buckets[ $bucket ] = call_user_func( $this->loader, $bucket );
		}

		return $this->buckets[ $bucket ][ $host ] ?? array();
	}

	/** Reads every part, for what needs the whole copy (the admin page, a sync's comparison). */
	private function read_all(): void {
		if ( null === $this->loader ) {
			return;
		}
		foreach ( array_keys( $this->data['hosts'] ) as $host ) {
			$this->data['hosts'][ $host ]['exact'] = array();
		}
		for ( $i = 0; $i < self::BUCKETS; $i++ ) {
			$bucket = sprintf( '%02x', $i );
			$paths  = $this->buckets[ $bucket ] ?? call_user_func( $this->loader, $bucket );
			foreach ( $paths as $host => $exact ) {
				if ( isset( $this->data['hosts'][ $host ] ) ) {
					$this->data['hosts'][ $host ]['exact'] += $exact;
				}
			}
		}
		// The parts come in hash order: list each host's paths in a stable one.
		foreach ( array_keys( $this->data['hosts'] ) as $host ) {
			ksort( $this->data['hosts'][ $host ]['exact'], SORT_STRING );
		}
		$this->loader  = null;
		$this->buckets = array();
	}

	/**
	 * Wraps a stored form (Parser::parse() or a stored copy).
	 *
	 * @param array<string, mixed> $data The stored form.
	 */
	public function __construct( array $data ) {
		$this->data = $data + array(
			'etag'       => '',
			'refresh'    => self::DEFAULT_REFRESH,
			'hits'       => null,
			'check'      => self::DEFAULT_CHECK_PATH,
			'powered_by' => null,
			'rules'      => 0,
			'hosts'      => array(),
		);
	}

	/**
	 * The stored form, every path included.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$this->read_all();

		return $this->data;
	}

	/** The ETag of the copy, without quotes or `W/`. */
	public function etag(): string {
		return (string) $this->data['etag'];
	}

	/** Seconds between downloads, clamped to 30–3600. */
	public function refresh(): int {
		return (int) $this->data['refresh'];
	}

	/** Where redirects and 404s are reported; null reports nothing. */
	public function hits_url(): ?string {
		return $this->data['hits'];
	}

	/** The check path, e.g. `/_redirhub/check`. */
	public function check_path(): string {
		return (string) $this->data['check'];
	}

	/** Sent as `X-Powered-By` on every redirect and frame page from the rules; null sends none. */
	public function powered_by(): ?string {
		return $this->data['powered_by'];
	}

	/** How many rules the copy holds, over every hostname. */
	public function count(): int {
		return (int) $this->data['rules'];
	}

	/**
	 * The hostnames listed with `@host`, lowercase.
	 *
	 * @return list<string>
	 */
	public function hostnames(): array {
		return array_map( 'strval', array_keys( $this->data['hosts'] ) );
	}

	/**
	 * Whether the hostname is listed with `@host`.
	 *
	 * @param string $host A normalised hostname (Path::host()).
	 */
	public function has_host( string $host ): bool {
		return isset( $this->data['hosts'][ $host ] );
	}

	/**
	 * The check token of the hostname's `@host` line.
	 *
	 * @param string $host A listed hostname.
	 */
	public function token( string $host ): string {
		return (string) ( $this->data['hosts'][ $host ]['token'] ?? '' );
	}

	/**
	 * Whether the hostname's `@host` line asks for the site's own 404 answers to be reported.
	 *
	 * @param string $host A listed hostname.
	 */
	public function report404( string $host ): bool {
		return ! empty( $this->data['hosts'][ $host ]['report404'] );
	}

	/**
	 * The exact rule for a request path on a listed hostname, or null.
	 *
	 * @param string $host A listed hostname.
	 * @param string $path The request path as sent (percent-encoded or raw), without the query.
	 */
	public function exact( string $host, string $path ): ?Rule {
		$key    = Path::match( $path );
		$stored = $this->paths( $host, $key )[ $key ] ?? null;

		return null === $stored ? null : new Rule( $stored );
	}

	/**
	 * Whether the hostname has an exact rule for the path, whatever its method or options.
	 *
	 * @param string $host A listed hostname.
	 * @param string $path The request path.
	 */
	public function has_exact( string $host, string $path ): bool {
		$key = Path::match( $path );

		return isset( $this->paths( $host, $key )[ $key ] );
	}

	/**
	 * The hostname's catch-all (`{host}/*`), or null.
	 *
	 * @param string $host A listed hostname.
	 */
	public function catch_all( string $host ): ?Rule {
		$stored = $this->data['hosts'][ $host ]['all'] ?? null;

		return null === $stored ? null : new Rule( $stored );
	}

	/**
	 * Every rule of a hostname, the catch-all last, for the admin page.
	 *
	 * @param string $host A listed hostname.
	 * @return list<Rule>
	 */
	public function rules( string $host ): array {
		$this->read_all();
		$rules = array();
		foreach ( $this->data['hosts'][ $host ]['exact'] ?? array() as $stored ) {
			$rules[] = new Rule( $stored );
		}
		if ( ! empty( $this->data['hosts'][ $host ]['all'] ) ) {
			$rules[] = new Rule( $this->data['hosts'][ $host ]['all'] );
		}

		return $rules;
	}
}
