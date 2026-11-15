<?php
/**
 * Downloads the rules file.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\Parser;
use RedirHub\Redirects\Core\Path;
use RedirHub\Redirects\Core\RuleSet;
use RedirHub\Redirects\Core\UnknownFormat;

/**
 * Integration-spec, "Downloading": a GET every `@refresh` seconds with `If-None-Match`, never
 * per request; any failure keeps the last good copy. A visitor never waits for it: a request that
 * finds the copy due hands the download to the end of the request or to WP-Cron (Background),
 * and one download runs at a time across all PHP processes (a lock row in the options table).
 */
final class Sync {

	/** The WP-Cron hook of a download. */
	const HOOK = 'redirhub_redirects_sync';

	/** The option row that serialises downloads. */
	const LOCK = 'redirhub_redirects_lock';

	/** A lock older than this many seconds belongs to a process that died. */
	const LOCK_TTL = 60;

	/** After a failed download, wait this long before the next try. */
	const RETRY = 30;

	/** A larger file counts as a failed download. */
	const MAX_BYTES = 33554432;

	/** Per download. */
	const TIMEOUT = 5;

	/** Rule paths whose cached pages are purged one by one after a change; more purge the whole cache. */
	const PURGE_LIMIT = 200;

	/**
	 * Whether a download is due: no copy yet, or the copy is older than its `@refresh`, and the
	 * last try is longer ago than RETRY.
	 */
	public static function due(): bool {
		$state = State::get();
		$now   = time();
		if ( $now - (int) $state['attempt_at'] < self::RETRY ) {
			return false;
		}

		return '' === $state['file'] || $now - (int) $state['fetched_at'] >= max( RuleSet::MIN_REFRESH, (int) $state['refresh'] );
	}

	/**
	 * Called on every request the plugin handles: when a download is due, it runs after the
	 * visitor has their answer, or in WP-Cron.
	 */
	public static function maybe_later(): void {
		if ( ! self::due() ) {
			return;
		}
		// Taken now, so the requests that follow do not queue the same download.
		State::update( array( 'attempt_at' => time() ) );
		Background::defer(
			static function ( bool $finished ): void {
				if ( $finished ) {
					self::run();
				} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( time(), self::HOOK );
				}
			}
		);
	}

	/**
	 * Downloads the rules file now.
	 *
	 * @param bool $force Download even when the copy is fresh, and wait briefly for a running download.
	 * @return array{status: string, error: string, changed: bool} `status`: `updated`, `unchanged`, `failed`, `busy` or `off`.
	 */
	public static function run( bool $force = false ): array {
		$url = Settings::rules_url();
		if ( '' === $url ) {
			return self::result( 'off' );
		}
		$token = self::lock( $force );
		if ( null === $token ) {
			return self::result( 'busy' );
		}
		try {
			return self::download( $url );
		} finally {
			self::unlock( $token );
		}
	}

	/**
	 * The download itself, under the lock.
	 *
	 * @param string $url The rules address.
	 * @return array{status: string, error: string, changed: bool}
	 */
	private static function download( string $url ): array {
		// Another process may have downloaded since this request loaded its options.
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( array( State::OPTION, Store::OPTION ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		State::flush();
		Store::flush();
		$state    = State::get();
		$previous = Store::load();
		$headers  = array( 'User-Agent' => Plugin::user_agent() );
		if ( null !== $previous && '' !== $state['etag'] ) {
			$headers['If-None-Match'] = $state['etag'];
		}
		State::update( array( 'attempt_at' => time() ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'headers'             => $headers,
				'user-agent'          => Plugin::user_agent(),
				'limit_response_size' => self::MAX_BYTES + 1,
				'reject_unsafe_urls'  => self::reject_unsafe_urls(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( 'network', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code && null !== $previous ) {
			State::update( self::success_fields( $previous ) );

			return self::result( 'unchanged' );
		}
		if ( 200 !== $code ) {
			return self::fail( 'http', (string) $code );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return self::fail( 'size', (string) strlen( $body ) );
		}
		$etag = Path::etag_header( (string) wp_remote_retrieve_header( $response, 'etag' ) );
		try {
			$rules = Parser::parse( $body, $etag );
		} catch ( UnknownFormat $error ) {
			return self::fail( 'format', $error->getMessage() );
		}

		$changed = null === $previous || $previous->etag() !== $rules->etag() || '' === $rules->etag();
		$file    = Store::save( $rules );
		State::update(
			array(
				'file'       => $file,
				'etag'       => $etag,
				'changed_at' => $changed ? time() : (int) $state['changed_at'],
			) + self::success_fields( $rules )
		);
		if ( $changed ) {
			self::after_change( self::changed_urls( $previous, $rules ) );
		}

		return self::result( 'updated', '', $changed );
	}

	/**
	 * Purges the pages a change may hide, now when every plugin has loaded, else on the next
	 * request that loads them all (purge_pending()): a request the plugin answered while loading
	 * never loads the cache plugins that come after it.
	 *
	 * @param list<string>|null $urls The addresses; null for the whole cache.
	 */
	private static function after_change( ?array $urls ): void {
		if ( did_action( 'plugins_loaded' ) ) {
			self::purge( $urls );

			return;
		}
		$pending = State::get()['purge'];
		if ( null === $urls || 'all' === $pending ) {
			$merged = null;
		} else {
			$merged = array_values( array_unique( array_merge( is_array( $pending ) ? $pending : array(), $urls ) ) );
			if ( count( $merged ) > self::PURGE_LIMIT ) {
				$merged = null;
			}
		}
		State::update( array( 'purge' => null === $merged ? 'all' : $merged ) );
	}

	/** `wp_loaded`: carries out a purge a download left for a request with every plugin loaded. */
	public static function purge_pending(): void {
		$pending = State::get()['purge'];
		if ( false === $pending ) {
			return;
		}
		State::update( array( 'purge' => false ) );
		self::purge( 'all' === $pending ? null : (array) $pending );
	}

	/**
	 * Asks the page caches to forget the pages, and tells whoever listens.
	 *
	 * @param list<string>|null $urls The addresses; null for the whole cache.
	 */
	private static function purge( ?array $urls ): void {
		Compat::purge( $urls );
		/**
		 * Fires after the rules changed and the page caches were asked to forget the pages the
		 * change concerns.
		 *
		 * @param RuleSet|null $rules The copy in use.
		 */
		do_action( 'redirhub_redirects_rules_updated', Store::load() );
	}

	/**
	 * The state fields of a successful download.
	 *
	 * @param RuleSet $rules The copy in use.
	 * @return array<string, mixed>
	 */
	private static function success_fields( RuleSet $rules ): array {
		$state = State::get();

		return array(
			'fetched_at'    => time(),
			'refresh'       => $rules->refresh(),
			'rules'         => $rules->count(),
			'hosts'         => $rules->hostnames(),
			'error'         => '',
			'error_at'      => 0,
			'failing_since' => 0,
			'connected_at'  => $state['connected_at'] ? (int) $state['connected_at'] : time(),
		);
	}

	/**
	 * Notes a failed download; the last good copy stays in use.
	 *
	 * @param string $kind   `network`, `http`, `size` or `format`.
	 * @param string $detail The error message or status.
	 * @return array{status: string, error: string, changed: bool}
	 */
	private static function fail( string $kind, string $detail ): array {
		$state = State::get();
		$error = $kind . ':' . $detail;
		State::update(
			array(
				'error'         => $error,
				'error_at'      => time(),
				'failing_since' => $state['failing_since'] ? (int) $state['failing_since'] : time(),
			)
		);
		Log::warn( 'sync', 'rules download failed (' . $error . '); ' . ( '' === $state['file'] ? 'no rules yet' : 'keeping the last good copy' ) );

		return self::result( 'failed', $error );
	}

	/**
	 * A result.
	 *
	 * @param string $status  The outcome.
	 * @param string $error   The error, for `failed`.
	 * @param bool   $changed Whether the rules changed.
	 * @return array{status: string, error: string, changed: bool}
	 */
	private static function result( string $status, string $error = '', bool $changed = false ): array {
		return array(
			'status'  => $status,
			'error'   => $error,
			'changed' => $changed,
		);
	}

	/**
	 * Takes the download lock: a row only one process can insert (`INSERT IGNORE` on the unique
	 * option name), holding a token so only its owner removes it. A lock older than LOCK_TTL
	 * belongs to a process that died and is replaced in one statement.
	 *
	 * @param bool $wait Wait up to six seconds for a download that is running.
	 * @return string|null The token, or null when another process holds the lock.
	 */
	private static function lock( bool $wait ): ?string {
		global $wpdb;
		$token    = time() . ':' . bin2hex( random_bytes( 8 ) );
		$deadline = time() + ( $wait ? 6 : 0 );
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic lock; the options API checks, then inserts.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", self::LOCK, time() - self::LOCK_TTL ) );
			$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $token ) );
			// phpcs:enable
			if ( 1 === $taken ) {
				wp_cache_delete( self::LOCK, 'options' );
				wp_cache_delete( 'notoptions', 'options' );

				return $token;
			}
			if ( $wait ) {
				usleep( 250000 );
			}
		} while ( time() < $deadline );

		return null;
	}

	/**
	 * Releases the lock, if this process still holds it.
	 *
	 * @param string $token The token lock() returned.
	 */
	private static function unlock( string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::LOCK, 'options' );
	}

	/**
	 * Whether a URL the plugin requests must be a public one. A local or development site may
	 * use a private address (e.g. a test stand-in for RedirHub).
	 */
	public static function reject_unsafe_urls(): bool {
		return ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * The addresses whose cached pages may now hide a redirect: the paths of exact rules that are
	 * new or changed, on each listed hostname. Null asks for the whole cache: the first copy, a
	 * changed catch-all or list of hostnames, or more than PURGE_LIMIT paths.
	 *
	 * @param RuleSet|null $previous The copy before.
	 * @param RuleSet      $rules    The new copy.
	 * @return list<string>|null
	 */
	public static function changed_urls( ?RuleSet $previous, RuleSet $rules ): ?array {
		if ( null === $previous ) {
			return null;
		}
		$urls   = array();
		$before = $previous->to_array()['hosts'];
		$after  = $rules->to_array()['hosts'];
		if ( array_keys( $before ) !== array_keys( $after ) ) {
			return null;
		}
		foreach ( $after as $host => $entry ) {
			// A new or changed catch-all applies to every page the site cannot find, which a cache may hold.
			if ( $before[ $host ]['all'] !== $entry['all'] ) {
				return null;
			}
			foreach ( $entry['exact'] as $path => $rule ) {
				if ( ( $before[ $host ]['exact'][ $path ] ?? null ) === $rule ) {
					continue;
				}
				$url    = set_url_scheme( 'http://' . $host . Path::escape( (string) $path ) );
				$urls[] = $url;
				if ( '/' !== $path ) {
					$urls[] = $url . '/';
				}
				if ( count( $urls ) > self::PURGE_LIMIT ) {
					return null;
				}
			}
		}

		return $urls;
	}
}
