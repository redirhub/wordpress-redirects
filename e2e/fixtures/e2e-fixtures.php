<?php
/**
 * E2E fixture (must-use plugin): the site answers several hostnames, as a site with domain
 * mapping does, so WordPress's canonical redirect to the home hostname is off.
 *
 * @package RedirHub\Redirects
 */

remove_action( 'template_redirect', 'redirect_canonical' );
