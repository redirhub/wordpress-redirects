<?php
/**
 * What the plugin knows about its rules and its reports.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Two options. `state` is small and loaded with the others, because every request reads it:
 * where the copy in use is, when it was downloaded and the last failure. `activity` is written
 * by the check path and the reports, at most once a minute, and only the admin page reads it.
 */
final class State {

	const OPTION   = 'redirhub_redirects_state';
	const ACTIVITY = 'redirhub_redirects_activity';

	/** Activity is written at most this often, in seconds. */
	const ACTIVITY_EVERY = 60;

	/**
	 * Memoised for the request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cache;

	/**
	 * The state.
	 *
	 * @return array{file: string, etag: string, fetched_at: int, attempt_at: int, refresh: int, rules: int, hosts: list<string>, error: string, error_at: int, failing_since: int, connected_at: int, changed_at: int}
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = ( is_array( $stored ) ? $stored : array() ) + array(
				'file'          => '',
				'etag'          => '',
				'fetched_at'    => 0,
				'attempt_at'    => 0,
				'refresh'       => 60,
				'rules'         => 0,
				'hosts'         => array(),
				'error'         => '',
				'error_at'      => 0,
				'failing_since' => 0,
				'connected_at'  => 0,
				'changed_at'    => 0,
			);
		}

		return self::$cache;
	}

	/**
	 * Changes fields of the state.
	 *
	 * @param array<string, mixed> $changes Fields by name.
	 */
	public static function update( array $changes ): void {
		self::$cache = $changes + self::get();
		update_option( self::OPTION, self::$cache, true );
	}

	/** Forgets everything (disconnecting, uninstalling). */
	public static function reset(): void {
		delete_option( self::OPTION );
		delete_option( self::ACTIVITY );
		self::$cache = null;
	}

	/** Forgets the memo. */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * The activity: when RedirHub last called the check path of each hostname, and how the reports
	 * fare.
	 *
	 * @return array{checks: array<string, array{at: int, etag: string}>, reports: array{sent_at: int, lines: int, error: string, error_at: int, dropped: int}}
	 */
	public static function activity(): array {
		$stored = get_option( self::ACTIVITY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'checks'  => isset( $stored['checks'] ) && is_array( $stored['checks'] ) ? $stored['checks'] : array(),
			'reports' => ( isset( $stored['reports'] ) && is_array( $stored['reports'] ) ? $stored['reports'] : array() ) + array(
				'sent_at'  => 0,
				'lines'    => 0,
				'error'    => '',
				'error_at' => 0,
				'dropped'  => 0,
			),
		);
	}

	/**
	 * Notes that the check path of a hostname was answered (at most once a minute per hostname,
	 * or at once when the ETag it reported changed).
	 *
	 * @param string $host The listed hostname.
	 * @param string $etag The ETag the answer named.
	 */
	public static function note_check( string $host, string $etag ): void {
		$activity = self::activity();
		$last     = $activity['checks'][ $host ] ?? array(
			'at'   => 0,
			'etag' => '',
		);
		if ( time() - (int) $last['at'] < self::ACTIVITY_EVERY && $last['etag'] === $etag ) {
			return;
		}
		$activity['checks'][ $host ] = array(
			'at'   => time(),
			'etag' => $etag,
		);
		update_option( self::ACTIVITY, $activity, false );
	}

	/**
	 * Notes how a report went (a success at most once a minute; a failure at once).
	 *
	 * @param int    $lines The lines sent.
	 * @param string $error Empty for a success.
	 */
	public static function note_report( int $lines, string $error = '' ): void {
		$activity = self::activity();
		$reports  = $activity['reports'];
		if ( '' === $error ) {
			if ( time() - (int) $reports['sent_at'] < self::ACTIVITY_EVERY && '' === $reports['error'] ) {
				return;
			}
			$reports['sent_at'] = time();
			$reports['lines']   = $lines;
			$reports['error']   = '';
		} else {
			$reports['error']    = $error;
			$reports['error_at'] = time();
			$reports['dropped'] += $lines;
		}
		$activity['reports'] = $reports;
		update_option( self::ACTIVITY, $activity, false );
	}
}
