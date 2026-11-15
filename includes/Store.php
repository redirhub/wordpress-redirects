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
 * The parsed copy in use, written as a PHP file of one array under wp-content/cache: OPcache
 * keeps it in shared memory, so a request reads 50,000 rules as fast as five. Where that
 * directory cannot be written, the copy goes into an option (not autoloaded) instead: slower to
 * read, but it works everywhere. Each copy gets a new random file name, so OPcache never serves
 * an old copy, however it revalidates.
 */
final class Store {

	/** The option of the fallback copy. */
	const OPTION = 'redirhub_redirects_copy';

	/** `file` in State when the copy lives in the option. */
	const IN_OPTION = 'option';

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
		$file         = State::get()['file'];
		if ( self::IN_OPTION === $file ) {
			$data = get_option( self::OPTION, null );
		} elseif ( '' !== $file && preg_match( '/^rules-[a-f0-9]{32}\.php$/', $file ) && is_file( self::dir() . '/' . $file ) ) {
			$data = include self::dir() . '/' . $file;
		} else {
			$data = null;
		}
		if ( is_array( $data ) && isset( $data['hosts'] ) ) {
			self::$loaded = new RuleSet( $data );
		}

		return self::$loaded;
	}

	/**
	 * Keeps a new copy, and removes the one it replaces.
	 *
	 * @param RuleSet $rules The new copy.
	 * @return string Its `file` for State: a file name, or `option`.
	 */
	public static function save( RuleSet $rules ): string {
		$previous = State::get()['file'];
		$file     = self::write_file( $rules );
		if ( null === $file ) {
			update_option( self::OPTION, $rules->to_array(), false );
			$file = self::IN_OPTION;
		} elseif ( self::IN_OPTION === $previous ) {
			delete_option( self::OPTION );
		}
		self::$loaded = $rules;
		self::remove_old( $file );

		return $file;
	}

	/** Removes every copy (disconnecting, uninstalling). */
	public static function clear(): void {
		delete_option( self::OPTION );
		self::remove_old( '' );
		self::$loaded = false;
	}

	/** Forgets the copy loaded in this request. */
	public static function flush(): void {
		self::$loaded = false;
	}

	/**
	 * The directory of the copies: one per site of a network.
	 */
	public static function dir(): string {
		$dir = WP_CONTENT_DIR . '/cache/redirhub-redirects';
		if ( is_multisite() ) {
			$dir .= '/site-' . get_current_blog_id();
		}

		return $dir;
	}

	/** Whether copies can be kept as files (shown on the admin page). */
	public static function writable(): bool {
		return self::IN_OPTION !== State::get()['file'] || self::prepare_dir();
	}

	/**
	 * Writes the copy to a new file and checks it reads back.
	 *
	 * @param RuleSet $rules The copy.
	 * @return string|null The file name, or null where files cannot be written.
	 */
	private static function write_file( RuleSet $rules ): ?string {
		if ( ! self::prepare_dir() ) {
			return null;
		}
		$name = 'rules-' . bin2hex( random_bytes( 16 ) ) . '.php';
		$path = self::dir() . '/' . $name;
		$tmp  = $path . '.' . getmypid() . '.tmp';
		$php  = "<?php\n// RedirHub Redirects: the rules in use (ETag " . str_replace( array( "\n", '?>' ), '', $rules->etag() ) . "). Generated, do not edit.\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $rules->to_array(), true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem may ask for FTP credentials; this is a cache file.
		if ( false === @file_put_contents( $tmp, $php, LOCK_EX ) || ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );

			return null;
		}
		// phpcs:enable
		$read = include $path;
		if ( ! is_array( $read ) || ( $read['etag'] ?? null ) !== $rules->etag() ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

			return null;
		}

		return $name;
	}

	/** Creates the directory, closed to the web, where it is missing. */
	private static function prepare_dir(): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_file( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
		}
		// phpcs:enable

		return wp_is_writable( $dir );
	}

	/**
	 * Removes the copies but one.
	 *
	 * @param string $keep The file name to keep; empty removes all.
	 */
	private static function remove_old( string $keep ): void {
		foreach ( (array) glob( self::dir() . '/rules-*.php*' ) as $path ) {
			if ( basename( (string) $path ) !== $keep ) {
				@unlink( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
