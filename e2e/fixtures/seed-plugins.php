<?php
/**
 * Redirects in other redirect plugins, made through those plugins' own code where they are free
 * (Redirection, Rank Math, Safe Redirect Manager, Simple 301 Redirects) and in Yoast SEO
 * Premium's documented option. Run with `wp eval-file` once the plugins are active.
 *
 * @package RedirHub\Redirects
 */

// Every run starts from no redirects in those plugins.
global $wpdb;
foreach ( array( 'redirection_items', 'rank_math_redirections' ) as $table ) {
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ) ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
	}
}
foreach ( get_posts( array( 'post_type' => 'redirect_rule', 'numberposts' => -1, 'post_status' => 'any' ) ) as $post ) {
	wp_delete_post( $post->ID, true );
}

// Redirection: its tables, then redirects of every kind the import meets.
if ( ! get_option( 'redirection_options' ) || ! $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE '{$GLOBALS['wpdb']->prefix}redirection_items'" ) ) {
	WP_CLI::runcommand( 'redirection database install', array( 'exit_error' => false ) );
}
$group = (int) $GLOBALS['wpdb']->get_var( "SELECT id FROM {$GLOBALS['wpdb']->prefix}redirection_groups ORDER BY id LIMIT 1" );
foreach (
	array(
		array( 'url' => '/old-about/', 'action_data' => array( 'url' => '/about-us/' ), 'action_code' => 301 ),
		array( 'url' => '/spring-sale', 'action_data' => array( 'url' => 'https://shop.example.com/sale' ), 'action_code' => 302 ),
		array( 'url' => '/blog/(.*)', 'regex' => true, 'action_data' => array( 'url' => '/news/$1' ), 'action_code' => 301 ),
		array( 'url' => '/paused', 'action_data' => array( 'url' => '/elsewhere' ), 'action_code' => 301, 'status' => 'disabled' ),
		array( 'url' => '/members', 'match_type' => 'login', 'action_data' => array( 'logged_in' => '/account', 'logged_out' => '/login' ), 'action_code' => 302 ),
		array( 'url' => '/retired', 'action_type' => 'error', 'action_code' => 410 ),
	) as $redirect
) {
	$created = Red_Item::create( $redirect + array( 'group_id' => $group, 'match_type' => 'url', 'action_type' => 'url' ) );
	if ( is_wp_error( $created ) ) {
		WP_CLI::error( 'Redirection: ' . $created->get_error_message() );
	}
}

// Rank Math: its redirections table (the module creates it when switched on), then an exact source, a "starts with" one, and a redirect of two sources.
RankMath\Installer::create_tables( array( 'redirections' ) );
RankMath\Redirections\DB::add( array( 'sources' => array( array( 'pattern' => 'rank-old', 'comparison' => 'exact' ) ), 'url_to' => home_url( '/rank-new/' ), 'header_code' => 301 ) );
RankMath\Redirections\DB::add( array( 'sources' => array( array( 'pattern' => 'shop/', 'comparison' => 'start' ) ), 'url_to' => home_url( '/store/' ), 'header_code' => 301 ) );
RankMath\Redirections\DB::add( array( 'sources' => array( array( 'pattern' => 'two-a', 'comparison' => 'exact' ), array( 'pattern' => 'two-b', 'comparison' => 'exact' ) ), 'url_to' => 'https://example.org/two', 'header_code' => 307 ) );

// Safe Redirect Manager: a plain one and a wildcard.
srm_create_redirect( '/srm-old', '/srm-new', 301 );
srm_create_redirect( '/srm-dir/*', '/srm-target', 302 );

// Simple 301 Redirects; /old-about/ is Redirection's already, so it is listed once.
update_option( '301_redirects', array( '/simple-old' => '/simple-new', '/old-about/' => '/somewhere-else', '/simple-wild/*' => '/x' ) );

// Yoast SEO Premium (paid, so only its option): plain, regex and a 410.
update_option(
	'wpseo-premium-redirects-base',
	array(
		array( 'origin' => 'yoast-old', 'url' => 'yoast-new', 'type' => 301, 'format' => 'plain' ),
		array( 'origin' => '^/yoast-(\d+)$', 'url' => '/y/$1', 'type' => 301, 'format' => 'regex' ),
		array( 'origin' => 'yoast-gone', 'url' => '', 'type' => 410, 'format' => 'plain' ),
	)
);
