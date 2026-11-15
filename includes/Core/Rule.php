<?php
/**
 * One rule of a rules file: a redirect or a frame page.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A matched rule. The rule set stores rules as plain arrays (cheap to keep in OPcache) and
 * builds this object only for the rule a request matched.
 */
final class Rule {

	/** The status column of a frame page. */
	const FRAME = 'frame';

	/**
	 * The source column as written; reports name the rule with it (`file=`).
	 *
	 * @var string
	 */
	public $source;

	/**
	 * The target column: an absolute URL.
	 *
	 * @var string
	 */
	public $target;

	/**
	 * 301, 302, 307, 308, or `frame`.
	 *
	 * @var int|string
	 */
	public $status;

	/**
	 * `p`: append the request path (catch-all only).
	 *
	 * @var bool
	 */
	public $append_path;

	/**
	 * `q`: keep the request query string.
	 *
	 * @var bool
	 */
	public $keep_query;

	/**
	 * The options column as `key => value` (may-ignore): `password`, `destinations`, `routing`,
	 * `plugins` are the ones RedirHub writes.
	 *
	 * @var array<string, string>
	 */
	public $options;

	/**
	 * Builds a rule from its stored form.
	 *
	 * @param array{0: string, 1: string, 2: int|string, 3: bool, 4: bool, 5: array<string, string>} $stored The rule as the rule set keeps it.
	 */
	public function __construct( array $stored ) {
		list( $this->source, $this->target, $this->status, $this->append_path, $this->keep_query, $this->options ) = $stored;
	}

	/** Whether the rule shows its target in a frame page instead of redirecting. */
	public function is_frame(): bool {
		return self::FRAME === $this->status;
	}

	/**
	 * The status the visit is answered and reported with: 200 for a frame page.
	 */
	public function answer_status(): int {
		return $this->is_frame() ? 200 : (int) $this->status;
	}

	/**
	 * The option's value, or an empty string.
	 *
	 * @param string $key An option key.
	 */
	public function option( string $key ): string {
		return isset( $this->options[ $key ] ) ? (string) $this->options[ $key ] : '';
	}

	/**
	 * Every destination of the link in order: the target, then the further ones of the
	 * `destinations` option (a comma inside a URL written `%2C`).
	 *
	 * @return list<string>
	 */
	public function destinations(): array {
		$all     = array( $this->target );
		$further = $this->option( 'destinations' );
		if ( '' === $further ) {
			return $all;
		}
		foreach ( explode( ',', $further ) as $url ) {
			$url = str_replace( array( '%2C', '%2c' ), ',', $url );
			if ( Path::is_http_url( $url ) ) {
				$all[] = $url;
			}
		}

		return $all;
	}

	/**
	 * The link's plugins (`nobot`, `nowechat`, `protection`).
	 *
	 * @return list<string>
	 */
	public function plugins(): array {
		$plugins = $this->option( 'plugins' );

		return '' === $plugins ? array() : array_values( array_filter( explode( ',', $plugins ) ) );
	}

	/**
	 * The same rule pointing at another destination (one picked from `destinations`).
	 *
	 * @param string $target An absolute URL.
	 */
	public function with_target( string $target ): self {
		$copy         = clone $this;
		$copy->target = $target;

		return $copy;
	}
}
