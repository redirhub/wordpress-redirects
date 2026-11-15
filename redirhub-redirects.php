<?php
/**
 * Plugin Name:       RedirHub Redirects
 * Plugin URI:        https://github.com/redirhub/wordpress-redirects
 * Description:       Send every old, moved or mistyped link to the right page: keep your search rankings, run short links on your own domain and see every click. No server or DNS changes.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            RedirHub
 * Author URI:        https://www.redirhub.com
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       redirhub-redirects
 * Domain Path:       /languages
 *
 * @package RedirHub\Redirects
 */

defined( 'ABSPATH' ) || exit;

define( 'REDIRHUB_REDIRECTS_VERSION', '1.0.0' );
define( 'REDIRHUB_REDIRECTS_FILE', __FILE__ );

require __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( \RedirHub\Redirects\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \RedirHub\Redirects\Plugin::class, 'deactivate' ) );

\RedirHub\Redirects\Plugin::boot();
