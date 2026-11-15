<?php
/**
 * What a link asks for beyond its target: plugins, a password, further destinations.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * The options column of the format (integration-spec, "Options"), carried out as RedirHub's
 * edge does (om-script redirector/internal/services): the same bot and WeChat checks, the same
 * unlock cookie, the same limit on password attempts, the same picking of a destination. Pure:
 * the plugin passes in the clock and the counter.
 */
final class LinkOptions {

	/** How long a correct password keeps a link unlocked. */
	const UNLOCK_TTL = 86400;

	/** Password attempts per visitor and link in ATTEMPT_WINDOW seconds. */
	const MAX_ATTEMPTS   = 10;
	const ATTEMPT_WINDOW = 60;

	/** The edge's list of crawlers kept out by `nobot`, matched case-insensitively. */
	const BOTS = array(
		'Googlebot',
		'Baiduspider',
		'bingbot',
		'YandexBot',
		'Exabot',
		'facebot',
		'ia_archiver',
		'MJ12bot',
		'AhrefsBot',
		'EasouSpider',
		'YisouSpider',
		'Sosospider',
		'YoudaoBot',
		'360Spider',
		'Sogou web spider',
		'facebookexternalhit',
		'fasthttp',
	);

	/**
	 * Whether `nobot` keeps this visitor out: a known crawler, or a User-Agent shorter than three
	 * characters.
	 *
	 * @param string|null $user_agent The User-Agent header.
	 */
	public static function is_bot( ?string $user_agent ): bool {
		$user_agent = (string) $user_agent;
		if ( strlen( $user_agent ) < 3 ) {
			return true;
		}
		foreach ( self::BOTS as $bot ) {
			if ( false !== stripos( $user_agent, $bot ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether `nowechat` sends this visitor to the "open in your browser" page: WeChat's or QQ's
	 * in-app browser.
	 *
	 * @param string|null $user_agent The User-Agent header.
	 */
	public static function is_wechat( ?string $user_agent ): bool {
		$user_agent = strtolower( (string) $user_agent );

		return false !== strpos( $user_agent, 'micromessenger/' ) || false !== strpos( $user_agent, 'qq/' );
	}

	/**
	 * The unlock cookie's name, per link so unlocking one does not unlock another.
	 *
	 * @param string $link The rule's source column.
	 */
	public static function cookie_name( string $link ): string {
		return '__rh_pw_' . substr( hash( 'sha256', $link ), 0, 16 );
	}

	/**
	 * The unlock cookie's value: `<expiry>.<HMAC-SHA256(key = hash, link|expiry)>`. The password
	 * hash is the secret, so changing the password locks every visitor out again.
	 *
	 * @param string $hash    The bcrypt hash of the `password` option.
	 * @param string $link    The rule's source column.
	 * @param int    $expires The Unix time the unlock ends.
	 */
	public static function cookie_value( string $hash, string $link, int $expires ): string {
		return $expires . '.' . hash_hmac( 'sha256', $link . '|' . $expires, $hash );
	}

	/**
	 * Whether a cookie unlocks the link now.
	 *
	 * @param string|null $value The cookie as sent.
	 * @param string      $hash  The bcrypt hash of the `password` option.
	 * @param string      $link  The rule's source column.
	 * @param int         $now   The Unix time.
	 */
	public static function unlocked( ?string $value, string $hash, string $link, int $now ): bool {
		if ( null === $value || false === strpos( $value, '.' ) ) {
			return false;
		}
		list( $expires ) = explode( '.', $value, 2 );
		if ( ! ctype_digit( $expires ) || $now >= (int) $expires ) {
			return false;
		}

		return hash_equals( self::cookie_value( $hash, $link, (int) $expires ), $value );
	}

	/**
	 * Whether a password matches the link's bcrypt hash (`$2y$`, as Laravel writes it).
	 *
	 * @param string $hash     The hash.
	 * @param string $password What the visitor entered.
	 */
	public static function password_matches( string $hash, string $password ): bool {
		return '' !== $hash && '' !== $password && password_verify( $password, $hash );
	}

	/**
	 * The destination for this visit, as the edge picks it: `order` takes them in turn (the
	 * counter is how many visits came before), anything else at random. One destination is
	 * always the target.
	 *
	 * @param list<string>  $destinations Every destination, the target first.
	 * @param string        $routing      The `routing` option.
	 * @param callable():int $counter     Returns the visit's turn (only asked for `order`).
	 */
	public static function pick( array $destinations, string $routing, callable $counter ): string {
		$count = count( $destinations );
		if ( $count < 2 ) {
			return $destinations[0];
		}
		if ( 'order' === $routing ) {
			return $destinations[ abs( $counter() ) % $count ];
		}

		return $destinations[ random_int( 0, $count - 1 ) ];
	}
}
