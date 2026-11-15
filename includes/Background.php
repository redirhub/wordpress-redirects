<?php
/**
 * Work that runs after the visitor has their answer.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Runs tasks at the end of a request without making the visitor wait: after
 * `fastcgi_finish_request()` (PHP-FPM) or `litespeed_finish_request()` (LiteSpeed), or after an
 * answer of this plugin whose body is complete (a redirect with `Content-Length: 0`). Where none
 * of these holds (a page of the site on mod_php), a task that must not delay the page goes to
 * WP-Cron instead, which WordPress runs in a background request.
 */
final class Background {

	/**
	 * The tasks, in order.
	 *
	 * @var list<callable(bool):void>
	 */
	private static $tasks = array();

	/**
	 * Whether the shutdown hook is registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Whether this plugin answered with a complete response (the visitor has it all).
	 *
	 * @var bool
	 */
	private static $answered = false;

	/**
	 * Runs a task at shutdown. It receives whether the visitor already has the whole answer: if
	 * not, it should hand its work to WP-Cron rather than block.
	 *
	 * @param callable(bool):void $task The task.
	 */
	public static function defer( callable $task ): void {
		self::$tasks[] = $task;
		if ( ! self::$registered ) {
			self::$registered = true;
			register_shutdown_function( array( self::class, 'run' ) );
		}
	}

	/** Notes that the plugin sent a complete answer (its length known, its body sent). */
	public static function answered(): void {
		self::$answered = true;
	}

	/** Whether the visitor can be released before slow work: true where it can. */
	public static function can_finish(): bool {
		return self::$answered || function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	/** Shutdown: releases the visitor, then runs the tasks. Never throws. */
	public static function run(): void {
		$tasks       = self::$tasks;
		self::$tasks = array();
		if ( array() === $tasks ) {
			return;
		}
		$finished = self::finish();
		foreach ( $tasks as $task ) {
			try {
				$task( $finished );
			} catch ( \Throwable $error ) {
				Log::warn( 'background', 'a background task failed: ' . $error->getMessage() );
			}
		}
	}

	/**
	 * Hands the visitor their answer and goes on.
	 *
	 * @return bool Whether the visitor no longer waits.
	 */
	private static function finish(): bool {
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			return fastcgi_finish_request();
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();

			return true;
		}
		if ( self::$answered ) {
			while ( ob_get_level() > 0 && @ob_end_flush() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			flush();

			return true;
		}

		return false;
	}

	/** Forgets the tasks (tests). */
	public static function reset(): void {
		self::$tasks    = array();
		self::$answered = false;
	}
}
