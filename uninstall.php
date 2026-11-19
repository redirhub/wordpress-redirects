<?php
/**
 * Removes everything the plugin stored, on every site of a network.
 *
 * @package RedirHub\Redirects
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the options (the copy of the rules included), transients and events of the current site.
 */
function redirhub_redirects_uninstall_site() {
	foreach ( array( 'redirhub_redirects_settings', 'redirhub_redirects_state', 'redirhub_redirects_activity', 'redirhub_redirects_copy', 'redirhub_redirects_queue', 'redirhub_redirects_turns', 'redirhub_redirects_lock' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'redirhub_redirects_hourly', 'redirhub_redirects_sync', 'redirhub_redirects_report' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	global $wpdb;
	// The copy's paths, and the transients of the log throttle, password attempts and the admin messages.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'redirhub\\_redirects\\_paths\\_%' OR option_name LIKE '\\_transient\\_redirhub\\_%' OR option_name LIKE '\\_transient\\_timeout\\_redirhub\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $redirhub_redirects_site_id ) {
		switch_to_blog( (int) $redirhub_redirects_site_id );
		redirhub_redirects_uninstall_site();
		restore_current_blog();
	}
} else {
	redirhub_redirects_uninstall_site();
}
