<?php
/**
 * The plugin's settings.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * What the site owner sets: the rules address and the integration key (from the RedirHub
 * dashboard, or as constants in wp-config.php, which win and are shown read-only), where the
 * visitor's address comes from, and whether reports and link options are on. One option, loaded
 * with the others (it is read on every request).
 */
final class Settings {

	const OPTION = 'redirhub_redirects_settings';

	/** The constants of wp-config.php, named as the RedirHub setup names them. */
	const RULES_URL_CONSTANT = 'REDIRHUB_RULES_URL';
	const KEY_CONSTANT       = 'REDIRHUB_INTEGRATION_KEY';

	/** Where the site owner manages the redirects; a constant of that name points elsewhere (a test dashboard). */
	const DASHBOARD          = 'https://app.redirhub.com';
	const DASHBOARD_CONSTANT = 'REDIRHUB_DASHBOARD_URL';

	/** Where the visitor's address comes from: never a header a visitor can send, unless the site owner says a proxy overwrites it. */
	const IP_SOURCES = array( 'remote_addr', 'cloudflare', 'x_real_ip', 'custom' );

	/**
	 * Memoised for the request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cache;

	/**
	 * Every setting, with its default.
	 *
	 * @return array{rules_url: string, key: string, ip_source: string, ip_header: string, reports: bool, link_options: bool}
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = ( is_array( $stored ) ? $stored : array() ) + self::defaults();
		}

		return self::$cache;
	}

	/**
	 * The defaults: reports and link options on, the connection's own address.
	 *
	 * @return array{rules_url: string, key: string, ip_source: string, ip_header: string, reports: bool, link_options: bool}
	 */
	public static function defaults(): array {
		return array(
			'rules_url'    => '',
			'key'          => '',
			'ip_source'    => 'remote_addr',
			'ip_header'    => '',
			'reports'      => true,
			'link_options' => true,
		);
	}

	/** The rules address: the constant, else the setting. */
	public static function rules_url(): string {
		if ( defined( self::RULES_URL_CONSTANT ) ) {
			return trim( (string) constant( self::RULES_URL_CONSTANT ) );
		}

		return trim( (string) self::all()['rules_url'] );
	}

	/** The integration key: the constant, else the setting. */
	public static function key(): string {
		if ( defined( self::KEY_CONSTANT ) ) {
			return trim( (string) constant( self::KEY_CONSTANT ) );
		}

		return trim( (string) self::all()['key'] );
	}

	/** The dashboard's address, without a trailing slash. */
	public static function dashboard(): string {
		$url = defined( self::DASHBOARD_CONSTANT ) ? (string) constant( self::DASHBOARD_CONSTANT ) : self::DASHBOARD;

		return rtrim( '' === $url ? self::DASHBOARD : $url, '/' );
	}

	/** Whether the rules address comes from wp-config.php. */
	public static function rules_url_is_constant(): bool {
		return defined( self::RULES_URL_CONSTANT );
	}

	/** Whether the key comes from wp-config.php. */
	public static function key_is_constant(): bool {
		return defined( self::KEY_CONSTANT );
	}

	/** Whether redirects are reported to RedirHub's analytics (needs the key). */
	public static function reports(): bool {
		return (bool) self::all()['reports'] && '' !== self::key();
	}

	/** Whether passwords, plugins and further destinations of a link are carried out. */
	public static function link_options(): bool {
		return (bool) self::all()['link_options'];
	}

	/**
	 * Changes settings.
	 *
	 * @param array<string, mixed> $changes Settings by name.
	 */
	public static function update( array $changes ): void {
		$settings = array_intersect_key( $changes + self::all(), self::defaults() );
		update_option( self::OPTION, $settings, true );
		self::$cache = null;
	}

	/** Forgets the memo (after another process changed the option, and in tests). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * The visitor's address, from where the settings say: the connection's own address unless the
	 * site owner says a proxy in front overwrites a header.
	 *
	 * @param array<string, mixed> $server `$_SERVER`, unslashed.
	 */
	public static function visitor_ip( array $server ): ?string {
		$settings = self::all();
		$header   = null;
		switch ( $settings['ip_source'] ) {
			case 'cloudflare':
				$header = 'HTTP_CF_CONNECTING_IP';
				break;
			case 'x_real_ip':
				$header = 'HTTP_X_REAL_IP';
				break;
			case 'custom':
				$name   = strtoupper( preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $settings['ip_header'] ) );
				$header = '' === $name ? null : 'HTTP_' . $name;
				break;
		}
		$value = null === $header ? null : ( $server[ $header ] ?? null );
		if ( is_string( $value ) ) {
			// A proxy that appends keeps the address it saw last.
			$parts = array_map( 'trim', explode( ',', $value ) );
			$value = (string) end( $parts );
		}
		if ( ! is_string( $value ) || false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			$value = $server['REMOTE_ADDR'] ?? null;
		}

		return is_string( $value ) && false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : null;
	}
}
