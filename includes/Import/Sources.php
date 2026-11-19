<?php
/**
 * Finds the redirects other redirect plugins keep on this site.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Import;

defined( 'ABSPATH' ) || exit;

/**
 * One reader per plugin, each reading where that plugin keeps its redirects: a table, an option
 * or post meta. It reads the data whether the plugin is active or not, so redirects left behind
 * by a plugin that was switched off can move too. Nothing here writes.
 *
 * The readers hand Converter plain items (see there). The storage they read is that of the
 * plugins' current releases (Redirection 5, Rank Math, Yoast SEO Premium, All in One SEO Pro,
 * SEOPress, Safe Redirect Manager, Simple 301 Redirects, 301 Redirects); a table or column that
 * is missing reads as no redirects.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads other plugins' own tables, once, on the admin page.
 */
final class Sources {

	/** The plugins, by id: their name and reader. */
	const PLUGINS = array(
		'redirection'           => array( 'Redirection', 'redirection' ),
		'rank-math'             => array( 'Rank Math', 'rank_math' ),
		'yoast'                 => array( 'Yoast SEO Premium', 'yoast' ),
		'aioseo'                => array( 'All in One SEO', 'aioseo' ),
		'seopress'              => array( 'SEOPress', 'seopress' ),
		'safe-redirect-manager' => array( 'Safe Redirect Manager', 'safe_redirect_manager' ),
		'simple-301-redirects'  => array( 'Simple 301 Redirects', 'simple_301' ),
		'301-redirects'         => array( '301 Redirects', 'eps_301' ),
	);

	/**
	 * Every plugin with redirects on this site, each with the rows that can move and what stays.
	 * The first plugin to list a source keeps it.
	 *
	 * @return array<string, array{name: string, rows: list<array{0: string, 1: string, 2: string, 3: string}>, skipped: array<string, int>}>
	 */
	public static function found(): array {
		$converter = new Converter( home_url() );
		$found     = array();
		foreach ( self::PLUGINS as $id => $plugin ) {
			$items = call_user_func( array( self::class, $plugin[1] ) );
			if ( array() === $items ) {
				continue;
			}
			/* translators: %s: the name of another plugin. */
			$found[ $id ] = array( 'name' => $plugin[0] ) + $converter->convert( $items, sprintf( __( 'Imported from %s (WordPress)', 'redirhub-redirects' ), $plugin[0] ) );
		}

		return $found;
	}

	/**
	 * Whether a table exists with the given columns.
	 *
	 * @param string       $table   Its name, prefix included.
	 * @param list<string> $columns Columns it must have.
	 */
	private static function table( string $table, array $columns ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- only reads the schema.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name, escaped and checked above.
		$have = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

		return array() === array_diff( $columns, (array) $have );
	}

	/**
	 * Redirection: one row per redirect. Only "URL only" matches that redirect to a URL move;
	 * matches on login, referrer, cookie…, error pages and actions that don't redirect stay.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function redirection(): array {
		global $wpdb;
		$items  = $wpdb->prefix . 'redirection_items';
		$groups = $wpdb->prefix . 'redirection_groups';
		if ( ! self::table( $items, array( 'url', 'regex', 'status', 'action_type', 'action_code', 'action_data', 'match_type', 'group_id' ) ) ) {
			return array();
		}
		$items  = esc_sql( $items );
		$groups = esc_sql( $groups );
		if ( self::table( $groups, array( 'id', 'status' ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names, escaped and checked above.
			$rows = $wpdb->get_results( "SELECT i.*, g.status AS group_status FROM `{$items}` i LEFT JOIN `{$groups}` g ON g.id = i.group_id ORDER BY i.position, i.id", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name, escaped and checked above.
			$rows = $wpdb->get_results( "SELECT i.*, 'enabled' AS group_status FROM `{$items}` i ORDER BY i.position, i.id", ARRAY_A );
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'source'    => (string) $row['url'],
					'target'    => (string) $row['action_data'],
					'code'      => (int) $row['action_code'],
					'pattern'   => '1' === (string) $row['regex'],
					// An "error" action (410, 404…) is counted as such by its code; "pass" and "nothing" never redirect.
					'condition' => 'url' !== $row['match_type'] || ! in_array( $row['action_type'], array( 'url', 'error' ), true ),
					'enabled'   => 'enabled' === $row['status'] && 'disabled' !== $row['group_status'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Rank Math: one row per redirect, with one or more sources; only exact sources move.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rank_math(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::table( $table, array( 'sources', 'url_to', 'header_code', 'status' ) ) ) {
			return array();
		}
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name, escaped and checked above.
		$rows  = $wpdb->get_results( "SELECT sources, url_to, header_code, status FROM `{$table}` WHERE status <> 'trashed' ORDER BY id", ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$sources = is_string( $row['sources'] ) && preg_match( '/^a:\d+:\{/', $row['sources'] ) ? unserialize( $row['sources'], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Rank Math stores them serialized; no objects allowed.
			foreach ( is_array( $sources ) ? $sources : array() as $source ) {
				if ( ! is_array( $source ) || ! isset( $source['pattern'] ) ) {
					continue;
				}
				$items[] = array(
					'source'  => (string) $source['pattern'],
					'target'  => (string) $row['url_to'],
					'code'    => (int) $row['header_code'],
					'pattern' => 'exact' !== ( $source['comparison'] ?? 'exact' ),
					'enabled' => 'active' === $row['status'],
				);
			}
		}

		return $items;
	}

	/**
	 * Yoast SEO Premium: an option of plain and regex redirects.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function yoast(): array {
		$items = array();
		foreach ( (array) get_option( 'wpseo-premium-redirects-base', array() ) as $redirect ) {
			if ( is_array( $redirect ) && isset( $redirect['origin'] ) ) {
				$items[] = array(
					'source'  => (string) $redirect['origin'],
					'target'  => (string) ( $redirect['url'] ?? '' ),
					'code'    => (int) ( $redirect['type'] ?? 0 ),
					'pattern' => 'regex' === ( $redirect['format'] ?? 'plain' ),
				);
			}
		}

		return $items;
	}

	/**
	 * All in One SEO (Pro's Redirects): one row per redirect.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function aioseo(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_redirects';
		if ( ! self::table( $table, array( 'source_url', 'target_url', 'type', 'regex', 'enabled' ) ) ) {
			return array();
		}
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name, escaped and checked above.
		$rows = $wpdb->get_results( "SELECT source_url, target_url, type, regex, enabled FROM `{$table}` ORDER BY id", ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'source'  => (string) $row['source_url'],
					'target'  => (string) $row['target_url'],
					'code'    => (int) $row['type'],
					'pattern' => '1' === (string) $row['regex'],
					'enabled' => '1' === (string) $row['enabled'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * SEOPress: a redirect on a post or term (its address to the target), and the redirects of
	 * its 404 monitor (a post whose title is the source).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function seopress(): array {
		global $wpdb;
		$items = array();
		$posts = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = '_seopress_redirections_enabled' AND m.meta_value = 'yes' AND p.post_status = 'publish'" );
		foreach ( (array) $posts as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			$items[] = array(
				'source'  => 'seopress_404' === $post->post_type ? $post->post_title : (string) get_permalink( $post ),
				'target'  => (string) get_post_meta( $post->ID, '_seopress_redirections_value', true ),
				'code'    => (int) get_post_meta( $post->ID, '_seopress_redirections_type', true ),
				'pattern' => 'yes' === get_post_meta( $post->ID, '_seopress_redirections_enabled_regex', true ),
			);
		}
		$terms = $wpdb->get_col( "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_seopress_redirections_enabled' AND meta_value = 'yes'" );
		foreach ( (array) $terms as $id ) {
			$link = get_term_link( (int) $id );
			if ( is_string( $link ) ) {
				$items[] = array(
					'source' => $link,
					'target' => (string) get_term_meta( (int) $id, '_seopress_redirections_value', true ),
					'code'   => (int) get_term_meta( (int) $id, '_seopress_redirections_type', true ),
				);
			}
		}

		return $items;
	}

	/**
	 * Safe Redirect Manager: one published post per redirect, its fields in post meta.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function safe_redirect_manager(): array {
		global $wpdb;
		$rows  = $wpdb->get_results( "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'redirect_rule' AND p.post_status = 'publish' AND m.meta_key LIKE '\\_redirect\\_rule\\_%' ORDER BY p.menu_order, p.ID", ARRAY_A );
		$posts = array();
		foreach ( (array) $rows as $row ) {
			$posts[ (int) $row['post_id'] ][ substr( (string) $row['meta_key'], strlen( '_redirect_rule_' ) ) ] = (string) $row['meta_value'];
		}
		$items = array();
		foreach ( $posts as $post ) {
			$from    = $post['from'] ?? '';
			$items[] = array(
				'source'  => $from,
				'target'  => $post['to'] ?? '',
				'code'    => (int) ( $post['status_code'] ?? 0 ),
				'pattern' => false !== strpos( $from, '*' ) || '1' === ( $post['from_regex'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * Simple 301 Redirects: an option of source → target, all 301; `*` is a wildcard.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function simple_301(): array {
		$items = array();
		foreach ( (array) get_option( '301_redirects', array() ) as $source => $target ) {
			$items[] = array(
				'source'  => (string) $source,
				'target'  => is_string( $target ) ? $target : '',
				'code'    => 301,
				'pattern' => false !== strpos( (string) $source, '*' ),
			);
		}

		return $items;
	}

	/**
	 * 301 Redirects (WebFactory): its own table; a target may be a post, by id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function eps_301(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'redirects';
		if ( ! self::table( $table, array( 'url_from', 'url_to', 'status', 'type' ) ) ) {
			return array();
		}
		$table = esc_sql( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name, escaped and checked above.
		$rows = $wpdb->get_results( "SELECT url_from, url_to, status, type FROM `{$table}` ORDER BY id", ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				$target = 'post' === $row['type'] ? (string) get_permalink( (int) $row['url_to'] ) : (string) $row['url_to'];
				return array(
					'source'  => (string) $row['url_from'],
					'target'  => $target,
					'code'    => (int) $row['status'],
					'enabled' => 'inactive' !== $row['status'],
				);
			},
			(array) $rows
		);
	}
}
