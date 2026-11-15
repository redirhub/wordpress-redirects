<?php
/**
 * Decides how a request is answered from a rule set.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * "Answering requests" of the format, without any I/O: the check path, exact rules before the
 * site, the catch-all only on the site's 404, and the report line of each answer. The plugin
 * sends what this decides; the tests compare it with spec/v1.
 */
final class Router {

	/**
	 * The rules in use.
	 *
	 * @var RuleSet
	 */
	private $rules;

	/**
	 * Routes with a rule set.
	 *
	 * @param RuleSet $rules The rules in use.
	 */
	public function __construct( RuleSet $rules ) {
		$this->rules = $rules;
	}

	/** The rules in use. */
	public function rules(): RuleSet {
		return $this->rules;
	}

	/**
	 * The hostname of the request as listed with `@host`, or null when the request is not the
	 * integration's (another method, an unlisted hostname).
	 *
	 * @param Request $request The request.
	 */
	public function host( Request $request ): ?string {
		$host = Path::host( $request->host );

		return '' !== $host && $this->rules->has_host( $host ) ? $host : null;
	}

	/**
	 * Before the site: the check path's answer or an exact rule's, or null to let the site answer.
	 *
	 * @param Request $request The request.
	 */
	public function before( Request $request ): ?Answer {
		if ( ! $request->is_answerable() ) {
			return null;
		}
		$host = $this->host( $request );
		if ( null === $host ) {
			return null;
		}
		if ( $request->path === $this->rules->check_path() ) {
			return Answer::check( $host, 'token=' . $this->rules->token( $host ) . ' etag=' . $this->rules->etag() );
		}
		$rule = $this->rules->exact( $host, $request->path );

		return null === $rule ? null : $this->answer( $host, $rule, $request );
	}

	/**
	 * After the site answered 404: the catch-all's answer, or null to keep the site's 404.
	 *
	 * @param Request $request The request the site answered with 404.
	 */
	public function after_404( Request $request ): ?Answer {
		if ( ! $request->is_answerable() ) {
			return null;
		}
		$host = $this->host( $request );
		$rule = null === $host ? null : $this->rules->catch_all( $host );

		return null === $rule ? null : $this->answer( $host, $rule, $request );
	}

	/**
	 * A rule's answer for the request, with its Location (or frame src).
	 *
	 * @param string  $host    The listed hostname.
	 * @param Rule    $rule    The rule.
	 * @param Request $request The request.
	 */
	public function answer( string $host, Rule $rule, Request $request ): Answer {
		return Answer::rule( $host, $rule, Location::build( $rule, Path::escape( $request->path ), Path::escape( $request->query, true ) ) );
	}

	/**
	 * Whether the site's own 404 for this request is reported: GET or HEAD on a listed hostname
	 * whose `@host` line has `report404`, when the file has `@hits`.
	 *
	 * @param Request $request The request.
	 */
	public function reports_404( Request $request ): bool {
		$host = $request->is_answerable() ? $this->host( $request ) : null;

		return null !== $host && null !== $this->rules->hits_url() && $this->rules->report404( $host );
	}

	/**
	 * The report line of an answer (a redirect or frame page), or of the site's 404 when $answer
	 * is null.
	 *
	 * @param Request     $request The request.
	 * @param Answer|null $answer  The rule's answer; null for the site's 404.
	 * @param int         $time    The Unix time of the visit.
	 * @param int         $size    The body size of a 404 (0 for a redirect and a frame page).
	 */
	public function report_line( Request $request, ?Answer $answer, int $time, int $size = 0 ): string {
		$hit = array(
			'ip'         => $request->ip,
			'method'     => $request->method,
			'scheme'     => $request->scheme,
			'host'       => null === $answer ? Path::host( $request->host ) : $answer->host,
			'path'       => Path::escape( $request->path ),
			'query'      => Path::escape( $request->query, true ),
			'protocol'   => $request->protocol,
			'status'     => null === $answer ? 404 : $answer->rule->answer_status(),
			'size'       => null === $answer ? $size : 0,
			'referer'    => $request->referer,
			'user_agent' => $request->user_agent,
		);
		if ( null !== $answer ) {
			$hit['source']   = $answer->rule->source;
			$hit['location'] = $answer->location;
		}

		return HitLine::format( $hit, $time );
	}
}
