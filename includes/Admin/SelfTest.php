<?php
/**
 * Asks the site itself whether its redirects work from outside.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\Path;
use RedirHub\Redirects\Core\RuleSet;
use RedirHub\Redirects\Store;

/**
 * Requests the site's own check path and one of its redirects over HTTP, as RedirHub and a
 * visitor would, through whatever stands in front (the web server's rewrite rules, a page cache,
 * a CDN). Each finding says what to do.
 */
final class SelfTest {

	/**
	 * Runs the tests.
	 *
	 * @return list<array{ok: bool, title: string, detail: string}>
	 */
	public static function run(): array {
		$rules = Store::load();
		if ( null === $rules ) {
			return array(
				array(
					'ok'     => false,
					'title'  => __( 'No rules yet', 'redirhub-redirects' ),
					'detail' => __( 'Connect the site and download the rules first.', 'redirhub-redirects' ),
				),
			);
		}
		$host = self::host( $rules );
		if ( null === $host ) {
			return array(
				array(
					'ok'     => false,
					'title'  => __( 'This site’s domain is not in the rules file', 'redirhub-redirects' ),
					/* translators: 1: the site's hostname, 2: the hostnames of the rules file. */
					'detail' => sprintf( __( 'This site answers as %1$s, the rules file is for %2$s. Requests for other hostnames are left to WordPress. Copy the rules address of this domain from the RedirHub dashboard.', 'redirhub-redirects' ), Status::site_host(), implode( ', ', $rules->hostnames() ) ),
				),
			);
		}

		$results   = array( self::check_path( $rules, $host ) );
		$redirect  = self::sample_rule( $rules, $host );
		$results[] = null === $redirect
			? array(
				'ok'     => true,
				'title'  => __( 'Nothing to test yet', 'redirhub-redirects' ),
				'detail' => __( 'Add a redirect for this domain in RedirHub, then run the test again.', 'redirhub-redirects' ),
			)
			: self::redirect( $rules, $host, $redirect );

		return $results;
	}

	/**
	 * The listed hostname to test: the site's own when listed, else none.
	 *
	 * @param RuleSet $rules The rules in use.
	 */
	private static function host( RuleSet $rules ): ?string {
		$site = Status::site_host();
		if ( $rules->has_host( $site ) ) {
			return $site;
		}
		$alias = 0 === strpos( $site, 'www.' ) ? substr( $site, 4 ) : 'www.' . $site;

		return $rules->has_host( $alias ) ? $alias : null;
	}

	/**
	 * GET the check path.
	 *
	 * @param RuleSet $rules The rules in use.
	 * @param string  $host  The hostname.
	 * @return array{ok: bool, title: string, detail: string}
	 */
	private static function check_path( RuleSet $rules, string $host ): array {
		$url      = self::scheme() . '://' . $host . $rules->check_path();
		$response = self::request( 'GET', $url );
		$title    = __( 'Your domain can be verified', 'redirhub-redirects' );
		if ( is_wp_error( $response ) ) {
			return self::unreachable( $title, $url, $response );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		$want = 'token=' . $rules->token( $host ) . ' etag=' . $rules->etag();
		if ( 200 === $code && $body === $want ) {
			return array(
				'ok'     => true,
				'title'  => $title,
				/* translators: %s: a URL. */
				'detail' => sprintf( __( 'RedirHub reaches this site at %s and sees your latest redirects.', 'redirhub-redirects' ), $url ),
			);
		}
		if ( 200 === $code && 0 === strpos( $body, 'token=' . $rules->token( $host ) . ' ' ) ) {
			$detail = __( 'It answers with older rules: a cache in front of the site keeps the answer. Exclude this path from your page cache or CDN.', 'redirhub-redirects' );
		} elseif ( 404 === $code ) {
			$detail = self::not_reaching_wordpress();
		} else {
			/* translators: %d: an HTTP status code. */
			$detail = sprintf( __( 'It answered with status %d and an unexpected body: something in front of WordPress (a cache, a security plugin or firewall) answers instead. Exclude this path there.', 'redirhub-redirects' ), $code );
		}

		return array(
			'ok'     => false,
			'title'  => $title,
			'detail' => $url . ' — ' . $detail,
		);
	}

	/**
	 * HEAD a redirect of the rules.
	 *
	 * @param RuleSet $rules The rules in use.
	 * @param string  $host  The hostname.
	 * @param string  $path  The rule's path.
	 * @return array{ok: bool, title: string, detail: string}
	 */
	private static function redirect( RuleSet $rules, string $host, string $path ): array {
		$rule     = $rules->exact( $host, $path );
		$url      = self::scheme() . '://' . $host . Path::escape( $path );
		$response = self::request( 'HEAD', $url );
		/* translators: %s: a path such as /old-page. */
		$title = sprintf( __( 'Your redirects work (%s)', 'redirhub-redirects' ), $path );
		if ( is_wp_error( $response ) ) {
			return self::unreachable( $title, $url, $response );
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$expected = array( $rule->target );
		foreach ( $rule->destinations() as $destination ) {
			$expected[] = $destination;
		}
		if ( $code === (int) $rule->status && in_array( strtok( $location, '?#' ), array_map( static function ( string $url ) {
			return strtok( $url, '?#' );
		}, $expected ), true ) ) {
			return array(
				'ok'     => true,
				'title'  => $title,
				/* translators: 1: an HTTP status code, 2: a URL. */
				'detail' => sprintf( __( 'A visitor is sent on to %2$s (%1$d).', 'redirhub-redirects' ), $code, $location ),
			);
		}
		if ( 200 === $code ) {
			$detail = __( 'The page answered instead of the redirect: a page cache keeps an old copy of it, or the file exists on the server. Purge this page in your cache plugin or CDN.', 'redirhub-redirects' );
		} elseif ( 404 === $code ) {
			$detail = self::not_reaching_wordpress();
		} else {
			/* translators: 1: an HTTP status code, 2: a URL or "-". */
			$detail = sprintf( __( 'It answered %1$d to %2$s: another plugin or the server redirects this path first.', 'redirhub-redirects' ), $code, '' === $location ? '-' : $location );
		}

		return array(
			'ok'     => false,
			'title'  => $title,
			'detail' => $url . ' — ' . $detail,
		);
	}

	/** The scheme of the home URL: the one visitors use. */
	private static function scheme(): string {
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'https' : 'http';
	}

	/** Why a 404 means the request never reached WordPress. */
	private static function not_reaching_wordpress(): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return __( 'The request does not reach WordPress: with plain permalinks the web server answers paths itself. Choose any other structure under Settings → Permalinks.', 'redirhub-redirects' );
		}

		return __( 'The request does not reach WordPress: the web server answers this path itself. Make it pass unknown paths to WordPress (the standard WordPress rewrite rules or try_files).', 'redirhub-redirects' );
	}

	/**
	 * A finding for a request that failed.
	 *
	 * @param string    $title The test.
	 * @param string    $url   The URL requested.
	 * @param \WP_Error $error The error.
	 * @return array{ok: bool, title: string, detail: string}
	 */
	private static function unreachable( string $title, string $url, \WP_Error $error ): array {
		return array(
			'ok'     => false,
			'title'  => $title,
			/* translators: 1: a URL, 2: an error message. */
			'detail' => sprintf( __( 'This server could not request %1$s (%2$s). Many hosts block requests from a site to itself; open the address in your browser to test it instead.', 'redirhub-redirects' ), $url, $error->get_error_message() ),
		);
	}

	/**
	 * A plain redirect of the hostname to test (no password, plugins, frame or rotation).
	 *
	 * @param RuleSet $rules The rules in use.
	 * @param string  $host  The hostname.
	 */
	private static function sample_rule( RuleSet $rules, string $host ): ?string {
		foreach ( $rules->to_array()['hosts'][ $host ]['exact'] as $path => $stored ) {
			if ( 'frame' !== $stored[2] && array() === $stored[5] && $path !== $rules->check_path() ) {
				return (string) $path;
			}
		}

		return null;
	}

	/**
	 * A request to the site itself.
	 *
	 * @param string $method `GET` or `HEAD`.
	 * @param string $url    The URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request( string $method, string $url ) {
		return wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to the site itself.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => 'Mozilla/5.0 (compatible; RedirHub self-test)',
			)
		);
	}
}
