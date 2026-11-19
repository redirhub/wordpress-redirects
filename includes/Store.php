<?php
/**
 * Keeps the last good copy of the rules.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\RuleSet;

/**
 * The parsed copy in use, in the database: an option with everything but the exact paths (the
 * hostnames, their check tokens and catch-alls), and the paths in RuleSet::BUCKETS options by
 * the hash of host and path. A request reads the first and the one bucket its path is in, so
 * 50,000 rules answer as fast as five. None of them is autoloaded: pages that no rule answers
 * never read the paths.
 *
 * Each copy gets a new generation, written whole before the summary points at it, so a request
 * never mixes two copies; the old generation is removed after.
 */
final class Store {

	/** The summary of the copy in use, with its generation. */
	const OPTION = 'redirhub_redirects_copy';

	/** The options of a generation's buckets: this, the generation and the bucket. */
	const BUCKET_PREFIX = 'redirhub_redirects_paths_';

	/**
	 * The copy loaded in this request; false before loading.
	 *
	 * @var RuleSet|null|false
	 */
	private static $loaded = false;

	/**
	 * The copy in use, or null before the first good download.
	 */
	public static function load(): ?RuleSet {
		if ( false !== self::$loaded ) {
			return self::$loaded;
		}
		self::$loaded = null;
		$stored       = get_option( self::OPTION, null );
		if ( is_array( $stored ) && isset( $stored['generation'], $stored['summary']['hosts'] ) && is_array( $stored['summary'] ) ) {
			$generation   = (string) $stored['generation'];
			self::$loaded = RuleSet::lazy(
				$stored['summary'],
				static function ( string $bucket ) use ( $generation ): array {
					$paths = get_option( self::BUCKET_PREFIX . $generation . '_' . $bucket, array() );

					return is_array( $paths ) ? $paths : array();
				}
			);
		}

		return self::$loaded;
	}

	/**
	 * Keeps a new copy, in place of the last one.
	 *
	 * @param RuleSet $rules The copy.
	 * @return string Its generation (State's `file`).
	 */
	public static function save( RuleSet $rules ): string {
		$previous   = get_option( self::OPTION, null );
		$generation = bin2hex( random_bytes( 6 ) );
		list( $summary, $buckets ) = $rules->split();
		// Every bucket, empty ones too: a missing option would be remembered in `notoptions`.
		for ( $i = 0; $i < RuleSet::BUCKETS; $i++ ) {
			$bucket = sprintf( '%02x', $i );
			update_option( self::BUCKET_PREFIX . $generation . '_' . $bucket, $buckets[ $bucket ] ?? array(), false );
		}
		update_option(
			self::OPTION,
			array(
				'generation' => $generation,
				'summary'    => $summary,
			),
			false
		);
		if ( is_array( $previous ) && isset( $previous['generation'] ) ) {
			self::remove_generation( (string) $previous['generation'] );
		}
		self::$loaded = $rules;

		return $generation;
	}

	/** Removes every copy (disconnecting, uninstalling). */
	public static function clear(): void {
		$stored = get_option( self::OPTION, null );
		delete_option( self::OPTION );
		if ( is_array( $stored ) && isset( $stored['generation'] ) ) {
			self::remove_generation( (string) $stored['generation'] );
		}
		self::remove_buckets( '' );
		self::$loaded = false;
	}

	/** Forgets the copy loaded in this request. */
	public static function flush(): void {
		self::$loaded = false;
	}

	/**
	 * Removes a generation's buckets.
	 *
	 * @param string $generation The generation.
	 */
	private static function remove_generation( string $generation ): void {
		if ( '' === $generation ) {
			return;
		}
		self::remove_buckets( $generation . '_' );
		for ( $i = 0; $i < RuleSet::BUCKETS; $i++ ) {
			wp_cache_delete( self::BUCKET_PREFIX . $generation . '_' . sprintf( '%02x', $i ), 'options' );
		}
	}

	/**
	 * Removes the buckets whose names start with a prefix, in one query: a generation's 256
	 * rows, or (empty) every generation's.
	 *
	 * @param string $prefix After BUCKET_PREFIX.
	 */
	private static function remove_buckets( string $prefix ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one DELETE for 256 options instead of 256.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::BUCKET_PREFIX . $prefix ) . '%' ) );
	}
}
