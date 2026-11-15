<?php
/**
 * One parsed copy of a rules file.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A parsed rules file, held as one nested array of scalars so it can be written with
 * `var_export()` to a PHP file that OPcache keeps in shared memory: loading it then costs next to
 * nothing, however many rules there are.
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
	 * The stored form, for `var_export()`.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
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
		$stored = $this->data['hosts'][ $host ]['exact'][ Path::match( $path ) ] ?? null;

		return null === $stored ? null : new Rule( $stored );
	}

	/**
	 * Whether the hostname has an exact rule for the path, whatever its method or options.
	 *
	 * @param string $host A listed hostname.
	 * @param string $path The request path.
	 */
	public function has_exact( string $host, string $path ): bool {
		return isset( $this->data['hosts'][ $host ]['exact'][ Path::match( $path ) ] );
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
