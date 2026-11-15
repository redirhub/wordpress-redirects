<?php
/**
 * Unit tests run the pure core without WordPress.
 *
 * @package RedirHub\Redirects
 */

// The plugin's files refuse to run outside WordPress; the core needs nothing else of it.
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/autoload.php';
