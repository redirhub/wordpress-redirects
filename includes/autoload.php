<?php
/**
 * The plugin's class loader: `RedirHub\Redirects\X\Y` lives in `includes/X/Y.php`.
 *
 * @package RedirHub\Redirects
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'RedirHub\\Redirects\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
