<?php
/**
 * Warnings in the PHP error log, never more than one per kind every ten minutes.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * A busy site must not fill its error log because RedirHub is unreachable: each kind of warning
 * is written once, then at most every ten minutes.
 */
final class Log {

	const EVERY = 600;

	/**
	 * Writes a warning, unless the same kind was written recently.
	 *
	 * @param string $kind    A short name for the kind of problem.
	 * @param string $message The warning.
	 */
	public static function warn( string $kind, string $message ): void {
		$key = 'redirhub_redirects_log_' . $kind;
		if ( false !== get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::EVERY );
		error_log( 'RedirHub Redirects: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
