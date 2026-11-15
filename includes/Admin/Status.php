<?php
/**
 * The connection's status, in words a site owner understands.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\Path;
use RedirHub\Redirects\Settings;
use RedirHub\Redirects\State;
use RedirHub\Redirects\Store;

/**
 * One reading of the status for the admin page, the notices and Site Health, so all three say
 * the same thing.
 */
final class Status {

	const OFF     = 'off';
	const FAILING = 'failing';
	const STALE   = 'stale';
	const WAITING = 'waiting';
	const OK      = 'ok';

	/** A copy this much older than its refresh interval (and failing) counts as stopped. */
	const STALE_FACTOR = 5;

	/**
	 * The status: `off` (not connected), `failing` (connected, no copy yet), `stale` (downloads
	 * failing for a while: the last good copy is in use), `waiting` (rules in use, RedirHub has
	 * not called the check path yet), `ok`.
	 */
	public static function level(): string {
		if ( '' === Settings::rules_url() ) {
			return self::OFF;
		}
		$state = State::get();
		if ( '' === $state['file'] ) {
			return self::FAILING;
		}
		if ( '' !== $state['error'] && time() - (int) $state['fetched_at'] > max( 300, self::STALE_FACTOR * (int) $state['refresh'] ) ) {
			return self::STALE;
		}

		return array() === self::checked_hosts() ? self::WAITING : self::OK;
	}

	/** The hostname of the home URL, as the rules file lists hostnames. */
	public static function site_host(): string {
		return Path::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * The listed hostnames RedirHub has called the check path of, with when.
	 *
	 * @return array<string, int>
	 */
	public static function checked_hosts(): array {
		$rules  = Store::load();
		$listed = null === $rules ? array() : $rules->hostnames();
		$checks = array();
		foreach ( State::activity()['checks'] as $host => $check ) {
			if ( in_array( $host, $listed, true ) ) {
				$checks[ $host ] = (int) $check['at'];
			}
		}

		return $checks;
	}

	/**
	 * A failed download as a sentence.
	 *
	 * @param string $error `network:<message>`, `http:<status>`, `size:<bytes>` or `format:<message>`.
	 */
	public static function error_text( string $error ): string {
		$parts  = explode( ':', $error, 2 );
		$kind   = $parts[0];
		$detail = $parts[1] ?? '';
		switch ( $kind ) {
			case 'network':
				/* translators: %s: the error message of the HTTP request. */
				return sprintf( __( 'RedirHub could not be reached from this server: %s', 'redirhub-redirects' ), $detail );
			case 'http':
				if ( '404' === $detail || '403' === $detail ) {
					return __( 'This rules address does not exist (any more). Copy it again from the RedirHub dashboard: it changes when the integration key is rotated or the domain is removed.', 'redirhub-redirects' );
				}
				/* translators: %s: an HTTP status code. */
				return sprintf( __( 'RedirHub answered with status %s. The plugin keeps using the last good copy and tries again shortly.', 'redirhub-redirects' ), $detail );
			case 'size':
				return __( 'The rules file is larger than 32 MB.', 'redirhub-redirects' );
			case 'format':
				return __( 'The rules file is in a newer format than this version of the plugin understands. Update the plugin.', 'redirhub-redirects' );
		}

		return $error;
	}

	/**
	 * A past time as "5 minutes ago".
	 *
	 * @param int $time A Unix time; 0 for never.
	 */
	public static function ago( int $time ): string {
		if ( 0 === $time ) {
			return __( 'never', 'redirhub-redirects' );
		}
		if ( time() - $time < 10 ) {
			return __( 'just now', 'redirhub-redirects' );
		}

		/* translators: %s: a duration such as "5 mins". */
		return sprintf( __( '%s ago', 'redirhub-redirects' ), human_time_diff( $time ) );
	}
}
