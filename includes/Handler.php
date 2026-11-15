<?php
/**
 * Answers the visitor's request from the rules.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\Answer;
use RedirHub\Redirects\Core\Pages;
use RedirHub\Redirects\Core\Request;
use RedirHub\Redirects\Core\Router;
use RedirHub\Redirects\Core\Rule;

/**
 * Integration-spec, "Answering requests", on WordPress:
 *
 * - The check path and exact rules are answered while the plugin file loads, before the plugins
 *   after it, the theme and the query: a redirect costs little more than PHP and the options.
 * - The catch-all redirects on `template_redirect`, last, when WordPress has found nothing (404)
 *   and no other plugin redirected: a working page is never taken over.
 * - The site's own 404s are reported on `shutdown`, for hostnames whose `@host` line asks.
 *
 * Fails open: any error leaves the request to WordPress.
 */
final class Handler {

	/** Requests to these WordPress entry points are never the integration's. */
	const SKIPPED_SCRIPTS = array( 'wp-login.php', 'wp-cron.php', 'xmlrpc.php', 'wp-activate.php', 'wp-signup.php', 'wp-trackback.php', 'wp-comments-post.php', 'wp-links-opml.php', 'wp-mail.php' );

	/** The option of the turn counters of links with `routing=order` (where no persistent object cache is). */
	const TURNS = 'redirhub_redirects_turns';

	/**
	 * The request, while the plugin may still answer it.
	 *
	 * @var Request|null
	 */
	private static $request;

	/**
	 * The router of the rules in use.
	 *
	 * @var Router|null
	 */
	private static $router;

	/**
	 * Whether the plugin answered the request.
	 *
	 * @var bool
	 */
	private static $answered = false;

	/**
	 * Runs while the plugin file loads.
	 */
	public static function boot(): void {
		if ( ! self::is_front_request() || '' === Settings::rules_url() ) {
			return;
		}
		try {
			Sync::maybe_later();
			$rules = Store::load();
			if ( null === $rules ) {
				return;
			}
			$router  = new Router( $rules );
			$request = self::request_from( self::server() );
			$host    = $router->host( $request );
			if ( null === $host ) {
				return;
			}
			self::$router  = $router;
			self::$request = $request;

			$answer = $router->before( $request );
			if ( null !== $answer ) {
				self::serve( $answer );

				return;
			}
			if ( 'POST' === $request->method && Settings::link_options() ) {
				$rule = $rules->exact( $host, $request->path );
				if ( null !== $rule && '' !== $rule->option( 'password' ) ) {
					self::serve( $router->answer( $host, $rule, $request ) );

					return;
				}
			}
			if ( null !== $rules->catch_all( $host ) ) {
				add_action( 'template_redirect', array( self::class, 'catch_all' ), PHP_INT_MAX );
			}
			if ( $router->reports_404( $request ) ) {
				add_action( 'shutdown', array( self::class, 'report_404' ), 0 );
			}
		} catch ( \Throwable $error ) {
			Log::warn( 'request', 'request passed on after an error: ' . $error->getMessage() );
		}
	}

	/**
	 * `template_redirect`, last: the catch-all where WordPress answers 404.
	 */
	public static function catch_all(): void {
		if ( null === self::$router || null === self::$request || ! is_404() ) {
			return;
		}
		try {
			$answer = self::$router->after_404( self::$request );
			if ( null !== $answer ) {
				self::serve( $answer );
			}
		} catch ( \Throwable $error ) {
			Log::warn( 'request', 'catch-all passed on after an error: ' . $error->getMessage() );
		}
	}

	/**
	 * `shutdown`: reports the site's own 404.
	 */
	public static function report_404(): void {
		if ( self::$answered || null === self::$router || null === self::$request || 404 !== http_response_code() ) {
			return;
		}
		$hits = self::$router->rules()->hits_url();
		if ( null !== $hits ) {
			Reporter::add( self::$router->report_line( self::$request, null, time() ), $hits );
		}
	}

	/**
	 * Answers with the check path's body, or with a rule: its options first (plugins, password,
	 * further destinations), then its redirect or frame page. Exits.
	 *
	 * @param Answer $answer The router's answer.
	 */
	private static function serve( Answer $answer ): void {
		$request = self::$request;
		$rules   = self::$router->rules();

		if ( $answer->is_check() ) {
			State::note_check( $answer->host, $rules->etag() );
			self::send(
				200,
				array(
					'Content-Type'  => 'text/plain; charset=utf-8',
					'Cache-Control' => 'no-store',
				),
				$answer->body
			);
		}

		$rule = $answer->rule;
		if ( Settings::link_options() ) {
			self::gate( $answer->host, $rule, $request );
			$destinations = $rule->destinations();
			if ( count( $destinations ) > 1 ) {
				$picked = LinkOptions::pick(
					$destinations,
					$rule->option( 'routing' ),
					static function () use ( $rule ): int {
						return self::turn( $rule->source );
					}
				);
				$answer = self::$router->answer( $answer->host, $rule->with_target( $picked ), $request );
			}
		}
		if ( 'GET' !== $request->method && 'HEAD' !== $request->method ) {
			// The password form posts here; once unlocked, the visitor is sent back with GET.
			return;
		}

		$hits = $rules->hits_url();
		if ( null !== $hits ) {
			Reporter::add( self::$router->report_line( $request, $answer, time() ), $hits );
		}
		$headers = array();
		if ( null !== $rules->powered_by() ) {
			$headers['X-Powered-By'] = $rules->powered_by();
		}
		$headers['X-Redirect-By'] = 'RedirHub';

		if ( $answer->rule->is_frame() ) {
			$headers['Content-Type']  = 'text/html; charset=utf-8';
			$headers['Cache-Control'] = 'private, no-cache';
			self::send( 200, $headers, Pages::frame( $answer->location, $answer->rule->target ) );
		}
		$headers['Location']      = $answer->location;
		$headers['Cache-Control'] = in_array( $answer->rule->status, array( 301, 308 ), true ) ? 'private, max-age=3600' : 'private, no-cache';
		self::send( (int) $answer->rule->status, $headers, '' );
	}

	/**
	 * The link's plugins and password: answers (and exits) when the visitor may not go on yet.
	 *
	 * @param string  $host    The listed hostname.
	 * @param Rule    $rule    The rule.
	 * @param Request $request The request.
	 */
	private static function gate( string $host, Rule $rule, Request $request ): void {
		$plugins  = $rule->plugins();
		$language = VisitorPages::language( (string) ( self::server()['HTTP_ACCEPT_LANGUAGE'] ?? '' ) );
		$private  = array( 'Cache-Control' => 'no-store' );

		if ( in_array( 'nobot', $plugins, true ) && LinkOptions::is_bot( $request->user_agent ) ) {
			self::send( 403, $private + array( 'Content-Type' => 'text/plain; charset=utf-8' ), 'Robot access denied' );
		}
		if ( in_array( 'nowechat', $plugins, true ) && LinkOptions::is_wechat( $request->user_agent ) ) {
			self::send( 200, $private + array( 'Content-Type' => 'text/html; charset=utf-8' ), VisitorPages::wechat( $language ) );
		}

		$hash = $rule->option( 'password' );
		if ( '' === $hash ) {
			return;
		}
		$cookie = LinkOptions::cookie_name( $rule->source );
		if ( LinkOptions::unlocked( isset( $_COOKIE[ $cookie ] ) ? (string) $_COOKIE[ $cookie ] : null, $hash, $rule->source, time() ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared with an HMAC as sent.
			return;
		}

		$html  = $private + array( 'Content-Type' => 'text/html; charset=utf-8' );
		$path  = $request->path;
		$error = '';
		if ( 'POST' === $request->method ) {
			// A visitor's password form (no nonce: no session; rate limited below), compared with a hash as typed: sanitizing would change it.
			$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			if ( did_action( 'plugins_loaded' ) ) {
				$password = wp_unslash( $password );
			}
			if ( ! self::allow_attempt( (string) $request->ip, $rule->source ) ) {
				self::send( 429, $html, VisitorPages::password( $language, $host, $path, 'pw.limited' ) );
			}
			if ( LinkOptions::password_matches( $hash, $password ) ) {
				$expires = time() + LinkOptions::UNLOCK_TTL;
				setcookie(
					$cookie,
					LinkOptions::cookie_value( $hash, $rule->source, $expires ),
					array(
						'expires'  => $expires,
						'path'     => '/',
						'secure'   => 'https' === $request->scheme,
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
				// Post/Redirect/Get: the same address, now unlocked.
				self::send( 303, array( 'Location' => $path . ( '' === $request->query ? '' : '?' . $request->query ) ) + $private, '' );
			}
			$error = 'pw.wrong';
		}
		self::send( 401, $html, VisitorPages::password( $language, $host, $path, $error ) );
	}

	/**
	 * Counts a password attempt: at most MAX_ATTEMPTS a minute per visitor and link.
	 *
	 * @param string $ip   The visitor's address.
	 * @param string $link The rule's source column.
	 */
	private static function allow_attempt( string $ip, string $link ): bool {
		$key   = 'redirhub_pw_' . md5( $ip . '|' . $link );
		$count = (int) get_transient( $key );
		if ( $count >= LinkOptions::MAX_ATTEMPTS ) {
			return false;
		}
		set_transient( $key, $count + 1, LinkOptions::ATTEMPT_WINDOW );

		return true;
	}

	/**
	 * The turn of this visit for a link with `routing=order`: a counter per link, in the object
	 * cache when it is persistent, else in an option.
	 *
	 * @param string $link The rule's source column.
	 */
	private static function turn( string $link ): int {
		$id = md5( $link );
		if ( wp_using_ext_object_cache() ) {
			$turn = wp_cache_incr( $id, 1, 'redirhub_turns' );
			if ( false === $turn ) {
				wp_cache_add( $id, 0, 'redirhub_turns' );
				$turn = (int) wp_cache_incr( $id, 1, 'redirhub_turns' );
			}

			return (int) $turn - 1;
		}
		$turns        = get_option( self::TURNS, array() );
		$turns        = is_array( $turns ) ? $turns : array();
		$turn         = (int) ( $turns[ $id ] ?? 0 );
		$turns[ $id ] = $turn + 1;
		update_option( self::TURNS, $turns, false );

		return $turn;
	}

	/**
	 * Sends an answer and ends the request.
	 *
	 * @param int                   $status  The status.
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The body (not sent for HEAD).
	 */
	private static function send( int $status, array $headers, string $body ): void {
		self::$answered = true;
		Compat::do_not_cache();
		if ( ! headers_sent() ) {
			status_header( $status );
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . str_replace( array( "\r", "\n" ), '', $value ) );
			}
			header( 'Content-Length: ' . strlen( $body ) );
		}
		if ( 'HEAD' !== self::$request->method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a text body or a page escaped where it is built.
		}
		Background::answered();
		exit;
	}

	/**
	 * Whether WordPress is answering a visitor's page request (not the admin, AJAX, cron, the
	 * login page, WP-CLI, an install or an update).
	 */
	public static function is_front_request(): bool {
		if ( defined( 'REDIRHUB_REDIRECTS_DISABLE' ) && REDIRHUB_REDIRECTS_DISABLE ) {
			return false;
		}
		if ( 'cli' === PHP_SAPI || ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || wp_doing_cron() || wp_doing_ajax() || wp_installing() ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		$script = basename( (string) ( self::server()['SCRIPT_NAME'] ?? '' ) );

		return ! in_array( $script, self::SKIPPED_SCRIPTS, true );
	}

	/**
	 * `$_SERVER` without the slashes WordPress adds after the plugins have loaded.
	 *
	 * @return array<string, mixed>
	 */
	private static function server(): array {
		return did_action( 'plugins_loaded' ) ? wp_unslash( $_SERVER ) : $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * The request from `$_SERVER`.
	 *
	 * @param array<string, mixed> $server `$_SERVER`, unslashed.
	 */
	public static function request_from( array $server ): Request {
		$uri = (string) ( $server['REQUEST_URI'] ?? '/' );
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://[^/]*#i', $uri, $authority ) ) {
			$uri = (string) substr( $uri, strlen( $authority[0] ) );
		}
		$query = '';
		$mark  = strpos( $uri, '?' );
		if ( false !== $mark ) {
			$query = (string) substr( $uri, $mark + 1 );
			$uri   = (string) substr( $uri, 0, $mark );
		}

		return new Request(
			array(
				'method'     => (string) ( $server['REQUEST_METHOD'] ?? 'GET' ),
				'host'       => (string) ( $server['HTTP_HOST'] ?? ( $server['SERVER_NAME'] ?? '' ) ),
				'path'       => '' === $uri ? '/' : $uri,
				'query'      => $query,
				'scheme'     => is_ssl() ? 'https' : 'http',
				'ip'         => Settings::visitor_ip( $server ),
				'referer'    => isset( $server['HTTP_REFERER'] ) ? (string) $server['HTTP_REFERER'] : null,
				'user_agent' => isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : null,
				'protocol'   => (string) ( $server['SERVER_PROTOCOL'] ?? 'HTTP/1.1' ),
			)
		);
	}

	/** Forgets the request (tests). */
	public static function reset(): void {
		self::$request  = null;
		self::$router   = null;
		self::$answered = false;
	}
}
