<?php
/**
 * Getting along with page caches.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * A page cache answers before WordPress, so a page it cached before a rule existed hides the
 * redirect. After the rules change this purges the pages of the changed paths (the whole cache
 * for the first copy or a large change) in every cache plugin it knows. Pages this plugin
 * answers itself are marked uncacheable for page caches (`DONOTCACHEPAGE`, LiteSpeed's header).
 */
final class Compat {

	/**
	 * Marks the answer being sent as one no page cache may keep.
	 */
	public static function do_not_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page caches' shared convention.
		}
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Purges cached pages.
	 *
	 * @param list<string>|null $urls The addresses; null purges the whole cache.
	 * @return list<string> The caches purged, for the admin page.
	 */
	public static function purge( ?array $urls ): array {
		if ( array() === $urls ) {
			return array();
		}
		$purged = array();

		// WP Rocket.
		if ( null === $urls && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'WP Rocket';
		} elseif ( null !== $urls && function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
			$purged[] = 'WP Rocket';
		}
		// LiteSpeed Cache.
		if ( defined( 'LSCWP_V' ) ) {
			if ( null === $urls ) {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- that cache plugin's own hook.
			} else {
				foreach ( $urls as $url ) {
					do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- that cache plugin's own hook.
				}
			}
			$purged[] = 'LiteSpeed Cache';
		}
		// W3 Total Cache.
		if ( null === $urls && function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'W3 Total Cache';
		} elseif ( null !== $urls && function_exists( 'w3tc_flush_url' ) ) {
			foreach ( $urls as $url ) {
				w3tc_flush_url( $url );
			}
			$purged[] = 'W3 Total Cache';
		}
		// WP Super Cache.
		if ( null === $urls && function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'WP Super Cache';
		} elseif ( null !== $urls && function_exists( 'wpsc_delete_url_cache' ) ) {
			foreach ( $urls as $url ) {
				wpsc_delete_url_cache( $url );
			}
			$purged[] = 'WP Super Cache';
		}
		// Cache Enabler.
		if ( class_exists( 'Cache_Enabler' ) ) {
			if ( null === $urls ) {
				do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- that cache plugin's own hook.
			} else {
				foreach ( $urls as $url ) {
					do_action( 'cache_enabler_clear_page_cache_by_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- that cache plugin's own hook.
				}
			}
			$purged[] = 'Cache Enabler';
		}
		// SiteGround Speed Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			if ( null === $urls ) {
				sg_cachepress_purge_cache();
			} else {
				foreach ( $urls as $url ) {
					sg_cachepress_purge_cache( $url );
				}
			}
			$purged[] = 'SiteGround Optimizer';
		}
		// WP Fastest Cache.
		if ( null === $urls && isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache();
			$purged[] = 'WP Fastest Cache';
		}
		// Breeze (Cloudways), Nginx Helper and others listen for this.
		if ( null === $urls ) {
			do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- that cache plugin's own hook.
			do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- that cache plugin's own hook.
		}

		/**
		 * Fires after the plugin asked the page caches to forget pages a new rule changes. A host's
		 * own cache can purge here.
		 *
		 * @param list<string>|null $urls The addresses; null for the whole cache.
		 */
		do_action( 'redirhub_redirects_purge', $urls );

		return $purged;
	}

	/**
	 * The page cache plugins and hosting caches found on the site, for the admin page.
	 *
	 * @return list<string>
	 */
	public static function detected(): array {
		$found = array();
		$known = array(
			'WP Rocket'            => function_exists( 'rocket_clean_domain' ),
			'LiteSpeed Cache'      => defined( 'LSCWP_V' ),
			'W3 Total Cache'       => function_exists( 'w3tc_flush_all' ),
			'WP Super Cache'       => function_exists( 'wp_cache_clear_cache' ),
			'Cache Enabler'        => class_exists( 'Cache_Enabler' ),
			'SiteGround Optimizer' => function_exists( 'sg_cachepress_purge_cache' ),
			'WP Fastest Cache'     => isset( $GLOBALS['wp_fastest_cache'] ),
			'Kinsta'               => defined( 'KINSTAMU_VERSION' ),
			'WP Engine'            => class_exists( 'WpeCommon' ),
		);
		foreach ( $known as $name => $present ) {
			if ( $present ) {
				$found[] = $name;
			}
		}
		if ( array() === $found && defined( 'WP_CACHE' ) && WP_CACHE ) {
			$found[] = 'advanced-cache.php';
		}

		return $found;
	}
}
