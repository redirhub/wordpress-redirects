<?php
/**
 * The frame page of the format.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Pages the format defines (integration-spec, "Frame pages"). The pages that only this client
 * shows (a password form, the WeChat page) are translated and live in the plugin's templates.
 */
final class Pages {

	/**
	 * The page of a `frame` rule: one full-window `<iframe>` showing `src`, titled with the
	 * target's hostname, both HTML-escaped.
	 *
	 * @param string $src    The Location of the rule for the request.
	 * @param string $target The rule's target, whose hostname titles the page.
	 */
	public static function frame( string $src, string $target ): string {
		$title = (string) parse_url( $target, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return implode(
			"\n",
			array(
				'<!DOCTYPE html>',
				'<html>',
				'<head>',
				'<meta charset="utf-8">',
				'<meta name="viewport" content="width=device-width, initial-scale=1">',
				'<meta name="robots" content="noindex">',
				'<title>' . self::escape( $title ) . '</title>',
				'<style>html,body{margin:0;height:100%;overflow:hidden}iframe{display:block;width:100%;height:100%;border:0}</style>',
				'</head>',
				'<body>',
				'<iframe src="' . self::escape( $src ) . '" allowfullscreen></iframe>',
				'</body>',
				'</html>',
				'',
			)
		);
	}

	/**
	 * `&`, `<`, `>`, `"` and `'` as character references.
	 *
	 * @param string $value Text for HTML.
	 */
	public static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}
}
