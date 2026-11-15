<?php
/**
 * Tools → RedirHub: connect the site, see what it serves, test it.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\Path;
use RedirHub\Redirects\Plugin;
use RedirHub\Redirects\Reporter;
use RedirHub\Redirects\Settings;
use RedirHub\Redirects\State;
use RedirHub\Redirects\Store;
use RedirHub\Redirects\Sync;

/**
 * The admin page. Every change is a POST to admin-post.php with a nonce, checked against
 * `manage_options`, answered with a redirect back here and a message for the next view.
 */
final class Page {

	const SLUG       = 'redirhub-redirects';
	const CAPABILITY = 'manage_options';

	/** Rules per page of the table. */
	const PER_PAGE = 50;

	/** Where the site owner manages the rules. */
	const DASHBOARD = 'https://app.redirhub.com';

	/** Hooks the page in. */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		foreach ( array( 'connect', 'sync', 'settings', 'disconnect', 'test' ) as $action ) {
			add_action( 'admin_post_redirhub_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( REDIRHUB_REDIRECTS_FILE ), array( self::class, 'action_links' ) );
	}

	/** Tools → RedirHub. */
	public static function menu(): void {
		add_management_page(
			__( 'RedirHub Redirects', 'redirhub-redirects' ),
			__( 'RedirHub', 'redirhub-redirects' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * The page's style and script, on the page only.
	 *
	 * @param string $hook The admin page's hook suffix.
	 */
	public static function assets( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		$base = plugin_dir_url( REDIRHUB_REDIRECTS_FILE ) . 'assets/';
		wp_enqueue_style( 'redirhub-redirects-admin', $base . 'admin.css', array(), REDIRHUB_REDIRECTS_VERSION );
		wp_enqueue_script( 'redirhub-redirects-admin', $base . 'admin.js', array(), REDIRHUB_REDIRECTS_VERSION, true );
		wp_localize_script(
			'redirhub-redirects-admin',
			'redirhubRedirects',
			array(
				'confirmDisconnect' => __( 'Disconnect this site from RedirHub? Its redirects stop at once, until you connect it again.', 'redirhub-redirects' ),
				'copied'            => __( 'Copied', 'redirhub-redirects' ),
			)
		);
	}

	/**
	 * "Settings" on the Plugins screen.
	 *
	 * @param array<string, string> $links The plugin's action links.
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		return array( 'settings' => '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'redirhub-redirects' ) . '</a>' ) + $links;
	}

	/**
	 * The page's address.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'tools.php' ) );
	}

	/* ------------------------------------------------------------------ actions */

	/** Connects (or changes the connection): saves only what downloads. */
	public static function handle_connect(): void {
		self::verify( 'connect' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$pasted = sanitize_textarea_field( wp_unslash( (string) ( $_POST['rules_url'] ?? '' ) ) );
		// Changing only the key: an empty address keeps the saved one.
		$url = Settings::rules_url_is_constant() || ( '' === trim( $pasted ) && '' !== Settings::rules_url() ) ? Settings::rules_url() : self::extract_url( $pasted );
		$key = Settings::key_is_constant() ? '' : trim( sanitize_text_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) ) );
		// phpcs:enable

		$problem = self::url_problem( $url );
		if ( null === $problem && '' !== $key && ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $key ) ) {
			$problem = __( 'That integration key does not look right: it is a run of letters and digits, shown next to the setup in the RedirHub dashboard.', 'redirhub-redirects' );
		}
		if ( null !== $problem ) {
			self::back( array( array( 'error', $problem ) ), array( 'rules_url' => $url ) );
		}

		$previous_settings = Settings::all();
		$previous_state    = State::get();
		$changes           = array( 'rules_url' => $url );
		if ( '' !== $key ) {
			$changes['key'] = $key;
		}
		if ( $url !== $previous_settings['rules_url'] ) {
			State::update(
				array(
					'etag'       => '',
					'attempt_at' => 0,
				)
			);
		}
		Settings::update( $changes );
		$result = Sync::run( true );

		if ( 'failed' === $result['status'] || 'busy' === $result['status'] ) {
			Settings::update( $previous_settings );
			State::update( $previous_state );
			$message = 'busy' === $result['status'] ? __( 'Another download is running. Try again in a moment.', 'redirhub-redirects' ) : Status::error_text( $result['error'] );
			self::back( array( array( 'error', __( 'Not connected.', 'redirhub-redirects' ) . ' ' . $message ) ), array( 'rules_url' => $url ) );
		}

		$messages = array();
		$rules    = Store::load();
		$site     = Status::site_host();
		if ( null !== $rules ) {
			/* translators: 1: number of rules, 2: list of hostnames. */
			$messages[] = array( 'success', sprintf( _n( 'Connected. %1$d redirect for %2$s is live.', 'Connected. %1$d redirects for %2$s are live.', $rules->count(), 'redirhub-redirects' ), $rules->count(), implode( ', ', $rules->hostnames() ) ) );
			if ( ! $rules->has_host( $site ) ) {
				/* translators: 1: the site's hostname, 2: hostnames. */
				$messages[] = array( 'warning', sprintf( __( 'This site’s address is %1$s, but these redirects are for %2$s: only visits to those addresses are redirected.', 'redirhub-redirects' ), $site, implode( ', ', $rules->hostnames() ) ) );
			}
		}
		if ( '' === Settings::key() ) {
			$messages[] = array( 'info', __( 'Your redirects work now. Add the integration key to also see how often each one is clicked.', 'redirhub-redirects' ) );
		}
		self::back( $messages );
	}

	/** Downloads the rules now. */
	public static function handle_sync(): void {
		self::verify( 'sync' );
		$result = Sync::run( true );
		switch ( $result['status'] ) {
			case 'updated':
				$message = $result['changed'] ? array( 'success', __( 'Your latest redirects are live.', 'redirhub-redirects' ) ) : array( 'success', __( 'Your redirects are up to date.', 'redirhub-redirects' ) );
				break;
			case 'unchanged':
				$message = array( 'success', __( 'Your redirects are up to date.', 'redirhub-redirects' ) );
				break;
			case 'busy':
				$message = array( 'warning', __( 'Another download is running. Try again in a moment.', 'redirhub-redirects' ) );
				break;
			default:
				$message = array( 'error', Status::error_text( $result['error'] ) );
		}
		self::back( array( $message ) );
	}

	/** Saves the options. */
	public static function handle_settings(): void {
		self::verify( 'settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$source = sanitize_key( wp_unslash( (string) ( $_POST['ip_source'] ?? 'remote_addr' ) ) );
		$header = preg_replace( '/[^A-Za-z0-9-]/', '', sanitize_text_field( wp_unslash( (string) ( $_POST['ip_header'] ?? '' ) ) ) );
		Settings::update(
			array(
				'ip_source'    => in_array( $source, Settings::IP_SOURCES, true ) ? $source : 'remote_addr',
				'ip_header'    => substr( (string) $header, 0, 64 ),
				'reports'      => ! empty( $_POST['reports'] ),
				'link_options' => ! empty( $_POST['link_options'] ),
			)
		);
		// phpcs:enable
		self::back( array( array( 'success', __( 'Settings saved.', 'redirhub-redirects' ) ) ) );
	}

	/** Disconnects: the redirects stop and every copy goes. */
	public static function handle_disconnect(): void {
		self::verify( 'disconnect' );
		Settings::update(
			array(
				'rules_url' => '',
				'key'       => '',
			)
		);
		Store::clear();
		State::reset();
		delete_option( Reporter::QUEUE );
		wp_clear_scheduled_hook( Plugin::HOURLY );
		wp_clear_scheduled_hook( Sync::HOOK );
		$message = Settings::rules_url_is_constant()
			? array( 'warning', __( 'The rules address is set in wp-config.php: remove REDIRHUB_RULES_URL there to disconnect for good.', 'redirhub-redirects' ) )
			: array( 'success', __( 'Disconnected. This site no longer redirects anything; your pages work as before.', 'redirhub-redirects' ) );
		self::back( array( $message ) );
	}

	/** Runs the self-test. */
	public static function handle_test(): void {
		self::verify( 'test' );
		self::back( array(), array( 'test' => SelfTest::run() ) );
	}

	/**
	 * Checks the nonce and the capability of an action.
	 *
	 * @param string $action The action.
	 */
	private static function verify( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage RedirHub.', 'redirhub-redirects' ), 403 );
		}
		check_admin_referer( 'redirhub_' . $action );
	}

	/**
	 * Back to the page with messages for the next view.
	 *
	 * @param list<array{0: string, 1: string}> $messages Type (`success`, `error`, `warning`, `info`) and text.
	 * @param array<string, mixed>              $extra    Further data for the next view.
	 */
	private static function back( array $messages, array $extra = array() ): void {
		set_transient( self::flash_key(), array( 'messages' => $messages ) + $extra, 120 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the action; only picks the tab to return to.
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : '';
		wp_safe_redirect( self::url( in_array( $tab, array( 'redirects', 'settings' ), true ) ? array( 'tab' => $tab ) : array() ) );
		exit;
	}

	/** The message store of the current user. */
	private static function flash_key(): string {
		return 'redirhub_redirects_flash_' . get_current_user_id();
	}

	/**
	 * The rules address in what was pasted: the address itself, or the first rules file address
	 * in a longer text (e.g. the whole setup instructions of the dashboard).
	 *
	 * @param string $pasted What the site owner pasted.
	 */
	public static function extract_url( string $pasted ): string {
		$pasted = trim( $pasted );
		if ( preg_match( '#^https?://\S+$#i', $pasted ) ) {
			return esc_url_raw( $pasted, array( 'http', 'https' ) );
		}
		if ( preg_match( '#https?://[^\s`"\'<>]+\.txt\b#i', $pasted, $match ) ) {
			return esc_url_raw( $match[0], array( 'http', 'https' ) );
		}

		return esc_url_raw( $pasted, array( 'http', 'https' ) );
	}

	/**
	 * What is wrong with a rules address, or null.
	 *
	 * @param string $url The address.
	 */
	private static function url_problem( string $url ): ?string {
		if ( '' === $url ) {
			return __( 'Paste the rules address from the RedirHub dashboard.', 'redirhub-redirects' );
		}
		if ( ! Path::is_http_url( $url ) ) {
			return __( 'That is not a web address. Paste the rules address from the RedirHub dashboard: it starts with https://.', 'redirhub-redirects' );
		}
		if ( 0 !== stripos( $url, 'https://' ) && ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return __( 'The rules address must start with https://.', 'redirhub-redirects' );
		}

		return null;
	}

	/* ------------------------------------------------------------------ view */

	/** The page (View). */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$flash = get_transient( self::flash_key() );
		delete_transient( self::flash_key() );
		View::render( is_array( $flash ) ? $flash : array() );
	}

	/**
	 * The rules address with its token shortened: it is a credential.
	 *
	 * @param string $url The address.
	 */
	public static function mask_url( string $url ): string {
		return (string) preg_replace_callback(
			'#/([A-Za-z0-9_-]{12,})\.txt$#',
			static function ( array $match ): string {
				return '/' . substr( $match[1], 0, 4 ) . '…' . substr( $match[1], -4 ) . '.txt';
			},
			$url
		);
	}

	/**
	 * The key's last four characters.
	 *
	 * @param string $key The key.
	 */
	public static function mask_key( string $key ): string {
		return '••••' . substr( $key, -4 );
	}
}
