<?php
/**
 * Removes everything the plugin stored, on every site of a network.
 *
 * @package RedirHub\Redirects
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the options, transients, events and cached copies of the current site.
 */
function redirhub_redirects_uninstall_site() {
	foreach ( array( 'redirhub_redirects_settings', 'redirhub_redirects_state', 'redirhub_redirects_activity', 'redirhub_redirects_copy', 'redirhub_redirects_queue', 'redirhub_redirects_turns', 'redirhub_redirects_lock' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'redirhub_redirects_hourly', 'redirhub_redirects_sync', 'redirhub_redirects_report' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	$dir = WP_CONTENT_DIR . '/cache/redirhub-redirects' . ( is_multisite() ? '/site-' . get_current_blog_id() : '' );
	foreach ( array_merge( (array) glob( $dir . '/rules-*' ), array( $dir . '/index.php', $dir . '/.htaccess' ) ) as $file ) {
		@unlink( (string) $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

	global $wpdb;
	// Transients of the log throttle, password attempts and the admin messages.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_redirhub\\_%' OR option_name LIKE '\\_transient\\_timeout\\_redirhub\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

@rmdir( WP_CONTENT_DIR . '/cache/redirhub-redirects' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
