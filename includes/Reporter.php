<?php
/**
 * Sends report lines to the `@hits` address.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Integration-spec, "Reports": one Apache combined line per redirect, frame page and reported
 * 404, posted with the integration key, at most 100 lines and 64 KB a request, 2 second timeout,
 * dropped on failure and never retried. The visitor never waits: lines go out after the answer
 * (Background). Where PHP cannot release the visitor first (a page of the site on mod_php), the
 * line waits in a small queue that WP-Cron sends in its own background request.
 */
final class Reporter {

	/** The WP-Cron hook that sends the queue. */
	const HOOK = 'redirhub_redirects_report';

	/** The option of the queue. */
	const QUEUE = 'redirhub_redirects_queue';

	/** Lines the queue holds at most; more are dropped. */
	const MAX_QUEUE = 1000;

	const MAX_LINES = 100;
	const MAX_BYTES = 65536;
	const TIMEOUT   = 2;

	/**
	 * This request's lines.
	 *
	 * @var list<string>
	 */
	private static $lines = array();

	/**
	 * Where they go.
	 *
	 * @var string
	 */
	private static $url = '';

	/**
	 * Adds a line; it is sent at the end of the request.
	 *
	 * @param string $line     The report line.
	 * @param string $hits_url The `@hits` address of the rules in use.
	 */
	public static function add( string $line, string $hits_url ): void {
		if ( ! Settings::reports() ) {
			return;
		}
		if ( array() === self::$lines ) {
			Background::defer( array( self::class, 'flush' ) );
		}
		self::$lines[] = $line;
		self::$url     = $hits_url;
	}

	/**
	 * Shutdown: sends this request's lines, or queues them for WP-Cron when the visitor would wait.
	 *
	 * @param bool $finished Whether the visitor already has the whole answer.
	 */
	public static function flush( bool $finished ): void {
		$lines       = self::$lines;
		self::$lines = array();
		if ( array() === $lines ) {
			return;
		}
		if ( $finished ) {
			self::send( $lines, self::$url );

			return;
		}
		self::queue( $lines, self::$url );
	}

	/**
	 * WP-Cron: sends the queued lines.
	 */
	public static function send_queue(): void {
		$queue = get_option( self::QUEUE, array() );
		delete_option( self::QUEUE );
		if ( ! is_array( $queue ) || empty( $queue['lines'] ) || empty( $queue['url'] ) ) {
			return;
		}
		self::send( array_values( $queue['lines'] ), (string) $queue['url'] );
	}

	/**
	 * Posts lines in batches of at most MAX_LINES lines and MAX_BYTES bytes.
	 *
	 * @param list<string> $lines The lines.
	 * @param string       $url   The `@hits` address.
	 */
	public static function send( array $lines, string $url ): void {
		$key = Settings::key();
		if ( '' === $key || '' === $url ) {
			return;
		}
		$batch = array();
		$bytes = 0;
		foreach ( $lines as $line ) {
			$size = strlen( $line ) + 1;
			if ( array() !== $batch && ( count( $batch ) >= self::MAX_LINES || $bytes + $size > self::MAX_BYTES ) ) {
				self::post( $batch, $url, $key );
				$batch = array();
				$bytes = 0;
			}
			$batch[] = $line;
			$bytes  += $size;
		}
		if ( array() !== $batch ) {
			self::post( $batch, $url, $key );
		}
	}

	/**
	 * One request to the `@hits` address. A failure drops the lines and is noted.
	 *
	 * @param list<string> $lines The lines.
	 * @param string       $url   The address.
	 * @param string       $key   The integration key.
	 */
	private static function post( array $lines, string $url, string $key ): void {
		$body    = implode( "\n", $lines ) . "\n";
		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'text/plain',
		);
		if ( function_exists( 'gzencode' ) ) {
			$zipped = gzencode( $body );
			if ( false !== $zipped ) {
				$body                        = $zipped;
				$headers['Content-Encoding'] = 'gzip';
			}
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'            => self::TIMEOUT,
				'redirection'        => 0,
				'headers'            => $headers,
				'user-agent'         => Plugin::user_agent(),
				'body'               => $body,
				'reject_unsafe_urls' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			State::note_report( count( $lines ), 'network:' . $response->get_error_message() );
			Log::warn( 'report', 'report dropped: ' . $response->get_error_message() );

			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			State::note_report( count( $lines ), 'http:' . $code );
			Log::warn( 'report', 'report rejected with status ' . $code );

			return;
		}
		State::note_report( count( $lines ) );
	}

	/**
	 * Puts lines in the queue and makes sure WP-Cron sends it.
	 *
	 * @param list<string> $lines The lines.
	 * @param string       $url   The `@hits` address.
	 */
	private static function queue( array $lines, string $url ): void {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) && isset( $queue['lines'] ) ? $queue : array( 'lines' => array() );
		$room  = self::MAX_QUEUE - count( $queue['lines'] );
		if ( $room < count( $lines ) ) {
			State::note_report( count( $lines ) - max( 0, $room ), 'queue:full' );
			$lines = array_slice( $lines, 0, max( 0, $room ) );
		}
		$queue['lines'] = array_merge( $queue['lines'], $lines );
		$queue['url']   = $url;
		update_option( self::QUEUE, $queue, false );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}
}
