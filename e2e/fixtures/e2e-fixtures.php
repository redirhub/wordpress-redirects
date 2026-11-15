<?php
/**
 * E2E fixture (must-use plugin): the site answers several hostnames, as a site with domain
 * mapping does, so WordPress's canonical redirect to the home hostname is off.
 *
 * @package RedirHub\Redirects
 */

remove_action( 'template_redirect', 'redirect_canonical' );

// Records the plugin's purge requests, so the scenarios can see one happens after a rules change.
add_action(
	'redirhub_redirects_purge',
	static function ( $urls ): void {
		$log   = (array) get_option( 'e2e_purges', array() );
		$log[] = null === $urls ? 'all' : implode( ' ', $urls );
		update_option( 'e2e_purges', $log, false );
	}
);
