<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * The hooks: request handling at load, WP-Cron for downloads and queued reports, the admin page,
 * activation and deactivation.
 */
final class Plugin {

	/** The hourly safety net: downloads when due even on a site whose pages are all cached. */
	const HOURLY = 'redirhub_redirects_hourly';

	/** Called once from the plugin file. */
	public static function boot(): void {
		Handler::boot();

		add_action( Sync::HOOK, array( self::class, 'cron_sync' ) );
		add_action( self::HOURLY, array( self::class, 'cron_sync' ) );
		add_action( Reporter::HOOK, array( Reporter::class, 'send_queue' ) );
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'wp_loaded', array( Sync::class, 'purge_pending' ) );

		if ( is_admin() ) {
			Admin\Page::boot();
			Admin\Notices::boot();
		}
		add_filter( 'site_status_tests', array( Admin\SiteHealth::class, 'tests' ) );
		add_filter( 'rocket_cache_reject_uri', array( self::class, 'reject_check_path' ) );
	}

	/** `init`: the hourly event, re-added if a migration lost it. (WordPress loads the translations.) */
	public static function init(): void {
		if ( '' !== Settings::rules_url() && ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
		}
	}

	/** WP-Cron: downloads the rules when the copy is missing or older than its refresh interval. */
	public static function cron_sync(): void {
		State::flush();
		$state = State::get();
		if ( '' === $state['file'] || time() - (int) $state['fetched_at'] >= (int) $state['refresh'] ) {
			Sync::run();
		}
	}

	/**
	 * WP Rocket: never cache the check path.
	 *
	 * @param mixed $uris The paths WP Rocket does not cache.
	 * @return mixed
	 */
	public static function reject_check_path( $uris ) {
		if ( is_array( $uris ) ) {
			$rules  = Store::load();
			$uris[] = null === $rules ? '/_redirhub/check' : $rules->check_path();
		}

		return $uris;
	}

	/** Activation: nothing to set up but the safety net. */
	public static function activate(): void {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
		}
	}

	/**
	 * Deactivation: stops the events (on every site, when deactivated for the network); settings
	 * and the copy stay for a reactivation.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::stop_events();
				restore_current_blog();
			}

			return;
		}
		self::stop_events();
	}

	/** Removes the plugin's events and lock on the current site. */
	private static function stop_events(): void {
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( Sync::HOOK );
		wp_clear_scheduled_hook( Reporter::HOOK );
		delete_option( Sync::LOCK );
	}

	/** The User-Agent of the plugin's own requests (integration-spec, "Identifying the client"). */
	public static function user_agent(): string {
		return 'wordpress-redirects/' . REDIRHUB_REDIRECTS_VERSION;
	}
}
