<?php
/**
 * Admin notices for problems that need the site owner.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\State;

/**
 * Two notices, both only for those who can fix them: after activation, a pointer to the
 * connection (on the Plugins screen); when downloads keep failing, a warning on every admin
 * screen, because the site then serves stale redirects.
 */
final class Notices {

	/** Hooks the notices in. */
	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/** Shows what applies. */
	public static function render(): void {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && 'tools_page_' . Page::SLUG === $screen->id ) {
			return;
		}
		$level = Status::level();
		if ( Status::OFF === $level && null !== $screen && 'plugins' === $screen->id ) {
			printf(
				'<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button button-primary">%3$s</a></p></div>',
				esc_html__( 'Almost there: connect your domain, and every old link starts landing on the right page.', 'redirhub-redirects' ),
				esc_url( Page::url() ),
				esc_html__( 'Connect', 'redirhub-redirects' )
			);

			return;
		}
		if ( Status::STALE === $level || Status::FAILING === $level ) {
			$state = State::get();
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Your redirects can’t be updated.', 'redirhub-redirects' ),
				esc_html( Status::error_text( (string) $state['error'] ) ),
				esc_url( Page::url() ),
				esc_html__( 'See what to do', 'redirhub-redirects' )
			);
		}
	}
}
