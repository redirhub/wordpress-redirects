<?php
/**
 * The Location of a rule for a request.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a rule's `Location`, and a frame rule's iframe `src` (integration-spec, "Location").
 */
final class Location {

	/**
	 * The target as written. Without `q` the request query is dropped; with `q` it is appended
	 * with `?`, or `&` when the target has a query. With `p` (catch-all only) the escaped request
	 * path is appended to the target's path without a double slash. A fragment of the target
	 * stays last.
	 *
	 * @param Rule   $rule  The matched rule.
	 * @param string $path  The request path, escaped (Path::escape()).
	 * @param string $query The request query string without `?`, escaped; empty for none.
	 */
	public static function build( Rule $rule, string $path, string $query ): string {
		$target   = $rule->target;
		$hash     = strpos( $target, '#' );
		$fragment = false === $hash ? null : substr( $target, $hash + 1 );
		if ( false !== $hash ) {
			$target = substr( $target, 0, $hash );
		}
		$question     = strpos( $target, '?' );
		$base         = false === $question ? $target : substr( $target, 0, $question );
		$target_query = false === $question ? '' : substr( $target, $question + 1 );

		if ( $rule->append_path ) {
			$base = preg_replace( '#/$#', '', $base ) . ( '' === $path ? '/' : $path );
		}
		$location = false === $question ? $base : $base . '?' . $target_query;
		if ( $rule->keep_query && '' !== $query ) {
			if ( false === $question ) {
				$location .= '?' . $query;
			} else {
				$location .= '' === $target_query ? $query : '&' . $query;
			}
		}

		return null === $fragment ? $location : $location . '#' . $fragment;
	}
}
