<?php
/**
 * A rules file of a format this client does not know.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown for an `@format` whose major version is not 1: the download counts as failed and the
 * last good copy stays in use.
 */
final class UnknownFormat extends \RuntimeException {
}
