<?php
/**
 * How the integration answers a request.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The check path's answer, or a rule's (a redirect or a frame page).
 */
final class Answer {

	const CHECK = 'check';
	const RULE  = 'rule';

	/**
	 * `check` or `rule`.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * The listed hostname.
	 *
	 * @var string
	 */
	public $host;

	/**
	 * The check path's body.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * The matched rule.
	 *
	 * @var Rule|null
	 */
	public $rule;

	/**
	 * The Location, or a frame page's iframe src.
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * Internal: use check() or rule().
	 *
	 * @param string $type `check` or `rule`.
	 * @param string $host The listed hostname.
	 */
	private function __construct( string $type, string $host ) {
		$this->type = $type;
		$this->host = $host;
	}

	/**
	 * The check path's answer.
	 *
	 * @param string $host The listed hostname.
	 * @param string $body `token=<check token> etag=<etag>`.
	 */
	public static function check( string $host, string $body ): self {
		$answer       = new self( self::CHECK, $host );
		$answer->body = $body;

		return $answer;
	}

	/**
	 * A rule's answer.
	 *
	 * @param string $host     The listed hostname.
	 * @param Rule   $rule     The rule.
	 * @param string $location Its Location for the request.
	 */
	public static function rule( string $host, Rule $rule, string $location ): self {
		$answer           = new self( self::RULE, $host );
		$answer->rule     = $rule;
		$answer->location = $location;

		return $answer;
	}

	/** Whether this is the check path's answer. */
	public function is_check(): bool {
		return self::CHECK === $this->type;
	}
}
