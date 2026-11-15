<?php
/**
 * Tools → RedirHub: connect the site, see what it serves, test it.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Compat;
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
			$messages[] = array( 'success', sprintf( _n( 'Connected. %1$d rule for %2$s is in use.', 'Connected. %1$d rules for %2$s are in use.', $rules->count(), 'redirhub-redirects' ), $rules->count(), implode( ', ', $rules->hostnames() ) ) );
			if ( ! $rules->has_host( $site ) ) {
				/* translators: 1: the site's hostname, 2: hostnames. */
				$messages[] = array( 'warning', sprintf( __( 'This site answers as %1$s, but the rules file is for %2$s. Only requests for those hostnames are redirected.', 'redirhub-redirects' ), $site, implode( ', ', $rules->hostnames() ) ) );
			}
		}
		if ( '' === Settings::key() ) {
			$messages[] = array( 'info', __( 'Redirects work without the integration key; add it to see their clicks in RedirHub’s analytics.', 'redirhub-redirects' ) );
		}
		self::back( $messages );
	}

	/** Downloads the rules now. */
	public static function handle_sync(): void {
		self::verify( 'sync' );
		$result = Sync::run( true );
		switch ( $result['status'] ) {
			case 'updated':
				$message = $result['changed'] ? array( 'success', __( 'New rules downloaded and in use.', 'redirhub-redirects' ) ) : array( 'success', __( 'The rules are up to date.', 'redirhub-redirects' ) );
				break;
			case 'unchanged':
				$message = array( 'success', __( 'The rules are up to date.', 'redirhub-redirects' ) );
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
			: array( 'success', __( 'Disconnected. This site no longer serves RedirHub redirects.', 'redirhub-redirects' ) );
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
		wp_safe_redirect( self::url() );
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

	/** The page. */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$flash = get_transient( self::flash_key() );
		delete_transient( self::flash_key() );
		$flash = is_array( $flash ) ? $flash : array();
		$level = Status::level();

		echo '<div class="wrap rh">';
		echo '<h1 class="rh-title"><img class="rh-logo" src="' . esc_url( plugin_dir_url( REDIRHUB_REDIRECTS_FILE ) . 'assets/logo.svg' ) . '" alt="" width="26" height="29">' . esc_html__( 'RedirHub Redirects', 'redirhub-redirects' ) . ' ' . self::pill( $level ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill().
		echo '<hr class="wp-header-end">';
		foreach ( (array) ( $flash['messages'] ?? array() ) as $message ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $message[0] ), esc_html( $message[1] ) );
		}

		if ( Status::OFF === $level ) {
			self::render_connect( (string) ( $flash['rules_url'] ?? '' ) );
		} else {
			if ( ! empty( $flash['test'] ) ) {
				self::render_test( (array) $flash['test'] );
			}
			self::render_overview();
			self::render_diagnostics();
			self::render_rules();
			self::render_settings();
			self::render_connection( (string) ( $flash['rules_url'] ?? '' ) );
		}
		echo '</div>';
	}

	/**
	 * The status pill.
	 *
	 * @param string $level Status::level().
	 */
	private static function pill( string $level ): string {
		$labels = array(
			Status::OFF     => __( 'Not connected', 'redirhub-redirects' ),
			Status::FAILING => __( 'Cannot download the rules', 'redirhub-redirects' ),
			Status::STALE   => __( 'Sync stopped', 'redirhub-redirects' ),
			Status::WAITING => __( 'Waiting for RedirHub’s check', 'redirhub-redirects' ),
			Status::OK      => __( 'Connected', 'redirhub-redirects' ),
		);

		return '<span class="rh-pill rh-pill--' . esc_attr( $level ) . '" data-status="' . esc_attr( $level ) . '">' . esc_html( $labels[ $level ] ) . '</span>';
	}

	/**
	 * The form that connects the site.
	 *
	 * @param string $rules_url The address entered before a failed try.
	 */
	private static function render_connect( string $rules_url ): void {
		?>
		<div class="rh-hero">
			<div class="rh-hero__text">
				<h2><?php esc_html_e( 'Serve your RedirHub redirects from this site', 'redirhub-redirects' ); ?></h2>
				<p><?php esc_html_e( 'Keep your DNS where it is. This site downloads your domain’s redirect rules from RedirHub and answers them itself: old pages, short links, campaign links and catch-alls, with every click in RedirHub’s analytics.', 'redirhub-redirects' ); ?></p>
				<ol class="rh-steps">
					<li>
						<?php
						printf(
							/* translators: %s: link to the RedirHub dashboard. */
							esc_html__( 'In %s, add this site’s domain and choose “Connect through your own platform”, then WordPress.', 'redirhub-redirects' ),
							'<a href="' . esc_url( self::DASHBOARD ) . '" target="_blank" rel="noopener">' . esc_html__( 'the RedirHub dashboard', 'redirhub-redirects' ) . '</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Copy the rules address and the integration key it shows.', 'redirhub-redirects' ); ?></li>
					<li><?php esc_html_e( 'Paste them here and connect. Then press “Check now” in the dashboard.', 'redirhub-redirects' ); ?></li>
				</ol>
			</div>
			<form class="rh-card rh-connect" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="redirhub_connect">
				<?php wp_nonce_field( 'redirhub_connect' ); ?>
				<?php self::connection_fields( $rules_url ); ?>
				<p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Connect', 'redirhub-redirects' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The two fields of the connection.
	 *
	 * @param string $rules_url The address to fill in (only one entered before a failed try: the
	 *                          saved address is a credential and never printed whole).
	 * @param bool   $change    Changing a saved connection: both fields may stay empty.
	 */
	private static function connection_fields( string $rules_url, bool $change = false ): void {
		?>
		<p>
			<label for="rh-rules-url"><strong><?php esc_html_e( 'Rules address', 'redirhub-redirects' ); ?></strong></label><br>
			<?php if ( Settings::rules_url_is_constant() ) : ?>
				<input type="text" id="rh-rules-url" class="large-text code" value="<?php echo esc_attr( self::mask_url( Settings::rules_url() ) ); ?>" readonly>
				<span class="description"><?php esc_html_e( 'Set in wp-config.php (REDIRHUB_RULES_URL).', 'redirhub-redirects' ); ?></span>
			<?php else : ?>
				<input type="text" inputmode="url" id="rh-rules-url" name="rules_url" class="large-text code" <?php echo $change ? '' : 'required'; ?> placeholder="<?php echo esc_attr( $change ? self::mask_url( Settings::rules_url() ) : 'https://…/….txt' ); ?>" value="<?php echo esc_attr( $rules_url ); ?>" autocomplete="off" spellcheck="false">
				<span class="description"><?php echo $change ? esc_html__( 'Leave empty to keep the address you saved. You can also paste the whole setup text: the address is picked out of it.', 'redirhub-redirects' ) : esc_html__( 'You can also paste the whole setup text: the address is picked out of it.', 'redirhub-redirects' ); ?></span>
			<?php endif; ?>
		</p>
		<p>
			<label for="rh-key"><strong><?php esc_html_e( 'Integration key', 'redirhub-redirects' ); ?></strong> <span class="rh-optional"><?php esc_html_e( '(for analytics)', 'redirhub-redirects' ); ?></span></label><br>
			<?php if ( Settings::key_is_constant() ) : ?>
				<input type="text" id="rh-key" class="regular-text code" value="<?php echo esc_attr( self::mask_key( Settings::key() ) ); ?>" readonly>
				<span class="description"><?php esc_html_e( 'Set in wp-config.php (REDIRHUB_INTEGRATION_KEY).', 'redirhub-redirects' ); ?></span>
			<?php else : ?>
				<input type="password" id="rh-key" name="key" class="regular-text code" autocomplete="new-password" spellcheck="false" placeholder="<?php echo '' === Settings::key() ? '' : esc_attr( self::mask_key( Settings::key() ) ); ?>">
				<span class="description"><?php echo '' === Settings::key() ? esc_html__( 'Only workspace managers see it in the dashboard. Without it, redirects work but are not counted.', 'redirhub-redirects' ) : esc_html__( 'Leave empty to keep the key you saved.', 'redirhub-redirects' ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/** The status cards. */
	private static function render_overview(): void {
		$state    = State::get();
		$rules    = Store::load();
		$activity = State::activity();
		$checks   = Status::checked_hosts();
		$reports  = $activity['reports'];
		?>
		<div class="rh-grid">
			<div class="rh-card">
				<h2><?php esc_html_e( 'Rules', 'redirhub-redirects' ); ?></h2>
				<?php if ( null === $rules ) : ?>
					<p class="rh-big">—</p>
					<p class="rh-bad"><?php echo esc_html( Status::error_text( (string) $state['error'] ) ); ?></p>
				<?php else : ?>
					<p class="rh-big" data-rules-count="<?php echo esc_attr( (string) $rules->count() ); ?>"><?php echo esc_html( number_format_i18n( $rules->count() ) ); ?></p>
					<p>
						<?php
						/* translators: %s: hostnames. */
						printf( esc_html__( 'for %s', 'redirhub-redirects' ), '<code>' . esc_html( implode( ', ', $rules->hostnames() ) ) . '</code>' );
						?>
					</p>
					<ul class="rh-facts">
						<li><?php esc_html_e( 'Checked for changes', 'redirhub-redirects' ); ?> <strong><?php echo esc_html( Status::ago( (int) $state['fetched_at'] ) ); ?></strong></li>
						<li><?php esc_html_e( 'Last change', 'redirhub-redirects' ); ?> <strong><?php echo esc_html( Status::ago( (int) $state['changed_at'] ) ); ?></strong></li>
						<li>
							<?php
							/* translators: %d: seconds. */
							printf( esc_html__( 'Checks again every %d seconds', 'redirhub-redirects' ), (int) $state['refresh'] );
							?>
						</li>
					</ul>
					<?php if ( '' !== $state['error'] ) : ?>
						<p class="rh-bad">
							<?php
							/* translators: 1: when, 2: the error. */
							printf( esc_html__( 'Last try %1$s failed: %2$s The last good copy stays in use.', 'redirhub-redirects' ), esc_html( Status::ago( (int) $state['error_at'] ) ), esc_html( Status::error_text( (string) $state['error'] ) ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				<?php self::button( 'sync', __( 'Sync now', 'redirhub-redirects' ) ); ?>
			</div>

			<div class="rh-card">
				<h2><?php esc_html_e( 'RedirHub’s check', 'redirhub-redirects' ); ?></h2>
				<?php if ( array() === $checks ) : ?>
					<p class="rh-big rh-muted"><?php esc_html_e( 'Waiting', 'redirhub-redirects' ); ?></p>
					<p><?php esc_html_e( 'RedirHub has not called this site’s check path yet. Press “Check now” on the domain in the RedirHub dashboard; the domain is connected once it answers.', 'redirhub-redirects' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( self::DASHBOARD ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open RedirHub', 'redirhub-redirects' ); ?></a></p>
				<?php else : ?>
					<p class="rh-big"><?php echo esc_html( Status::ago( max( $checks ) ) ); ?></p>
					<ul class="rh-facts">
						<?php foreach ( $checks as $host => $at ) : ?>
							<li><code><?php echo esc_html( $host ); ?></code> <strong><?php echo esc_html( Status::ago( $at ) ); ?></strong></li>
						<?php endforeach; ?>
					</ul>
					<p class="rh-muted"><?php esc_html_e( 'RedirHub calls the check path regularly to see that this site serves its latest rules.', 'redirhub-redirects' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="rh-card">
				<h2><?php esc_html_e( 'Analytics', 'redirhub-redirects' ); ?></h2>
				<?php if ( ! Settings::reports() ) : ?>
					<p class="rh-big rh-muted"><?php esc_html_e( 'Off', 'redirhub-redirects' ); ?></p>
					<p><?php echo '' === Settings::key() ? esc_html__( 'Add the integration key below to count every redirect in RedirHub.', 'redirhub-redirects' ) : esc_html__( 'Reports are switched off in the settings below.', 'redirhub-redirects' ); ?></p>
				<?php elseif ( null !== $rules && null === $rules->hits_url() ) : ?>
					<p class="rh-big rh-muted"><?php esc_html_e( 'Not offered', 'redirhub-redirects' ); ?></p>
					<p><?php esc_html_e( 'The rules file names no address for reports.', 'redirhub-redirects' ); ?></p>
				<?php else : ?>
					<p class="rh-big"><?php echo esc_html( 0 === (int) $reports['sent_at'] ? __( 'On', 'redirhub-redirects' ) : Status::ago( (int) $reports['sent_at'] ) ); ?></p>
					<p><?php echo 0 === (int) $reports['sent_at'] ? esc_html__( 'Every redirect is reported to RedirHub. Nothing to report yet.', 'redirhub-redirects' ) : esc_html__( 'Last report sent. Every redirect is counted in RedirHub.', 'redirhub-redirects' ); ?></p>
					<?php if ( '' !== $reports['error'] && (int) $reports['error_at'] > (int) $reports['sent_at'] ) : ?>
						<p class="rh-bad">
							<?php
							/* translators: 1: when, 2: the error. */
							printf( esc_html__( 'A report failed %1$s (%2$s). Reports are not retried.', 'redirhub-redirects' ), esc_html( Status::ago( (int) $reports['error_at'] ) ), esc_html( (string) $reports['error'] ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="rh-card">
				<h2><?php esc_html_e( 'Test', 'redirhub-redirects' ); ?></h2>
				<p><?php esc_html_e( 'Requests this site’s check path and one of its redirects over the internet, the way RedirHub and your visitors do, through any cache or CDN in front.', 'redirhub-redirects' ); ?></p>
				<?php self::button( 'test', __( 'Run the test', 'redirhub-redirects' ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The self-test's findings.
	 *
	 * @param array<int, array{ok: bool, title: string, detail: string}> $results The findings.
	 */
	private static function render_test( array $results ): void {
		echo '<div class="rh-card rh-test"><h2>' . esc_html__( 'Test results', 'redirhub-redirects' ) . '</h2><ul>';
		foreach ( $results as $result ) {
			printf(
				'<li class="%1$s" data-ok="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><strong>%4$s</strong><br>%5$s</li>',
				esc_attr( $result['ok'] ? 'rh-ok' : 'rh-fail' ),
				$result['ok'] ? '1' : '0',
				esc_attr( $result['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning' ),
				esc_html( $result['title'] ),
				esc_html( $result['detail'] )
			);
		}
		echo '</ul></div>';
	}

	/** What may keep redirects from working, with what to do. */
	private static function render_diagnostics(): void {
		$notes = array();
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$notes[] = array( 'error', __( 'Plain permalinks are on, so the web server answers unknown paths itself and they never reach WordPress. Choose any other structure under Settings → Permalinks.', 'redirhub-redirects' ) );
		}
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $home_path ) {
			/* translators: %s: a path such as /blog/. */
			$notes[] = array( 'warning', sprintf( __( 'WordPress runs under %s: only requests below it reach this plugin, and RedirHub checks the site at the root. Serve WordPress at the root, or use another integration for this domain.', 'redirhub-redirects' ), $home_path ) );
		}
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$notes[] = array( 'warning', __( 'This site’s address starts with http://. RedirHub checks the site over HTTPS, so the domain needs a certificate.', 'redirhub-redirects' ) );
		}
		$server = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tested for presence.
		if ( 'remote_addr' === Settings::all()['ip_source'] && ( isset( $server['HTTP_CF_CONNECTING_IP'] ) || isset( $server['HTTP_X_FORWARDED_FOR'] ) || isset( $server['HTTP_X_REAL_IP'] ) ) ) {
			$notes[] = array( 'warning', __( 'A proxy or CDN stands in front of this site, but visitor addresses are taken from the connection, which is the proxy’s. Every visitor then shares one address: in analytics, and for the limit on password attempts. Choose the proxy’s header under Settings below.', 'redirhub-redirects' ) );
		}
		$caches = Compat::detected();
		if ( array() !== $caches ) {
			/* translators: %s: names of cache plugins. */
			$notes[] = array( 'info', sprintf( __( 'Page cache found (%s). When the rules change, the plugin purges the pages of the changed paths so a cached page cannot hide a redirect. A CDN or host cache it cannot reach may need a purge by hand; the test above shows it.', 'redirhub-redirects' ), implode( ', ', $caches ) ) );
		}
		if ( Store::IN_OPTION === State::get()['file'] ) {
			$notes[] = array( 'info', __( 'wp-content/cache cannot be written, so the rules are kept in the database. Redirects work; with many rules they are faster when that directory is writable.', 'redirhub-redirects' ) );
		}
		if ( array() === $notes ) {
			return;
		}
		echo '<div class="rh-notes">';
		foreach ( $notes as $note ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $note[0] ), esc_html( $note[1] ) );
		}
		echo '</div>';
	}

	/** The rules of the site, searchable, a page at a time. */
	private static function render_rules(): void {
		$rules = Store::load();
		if ( null === $rules ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters of a list.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$rows = array();
		foreach ( $rules->to_array()['hosts'] as $host => $entry ) {
			$all = $entry['exact'];
			if ( null !== $entry['all'] ) {
				$all['*'] = $entry['all'];
			}
			foreach ( $all as $path => $stored ) {
				if ( '' === $search || false !== stripos( $host . $path . ' ' . $stored[1], $search ) ) {
					$rows[] = array( (string) $host, (string) $path, $stored );
				}
			}
		}
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		?>
		<div class="rh-card rh-rules">
			<div class="rh-rules__head">
				<h2><?php esc_html_e( 'Redirects in use', 'redirhub-redirects' ); ?></h2>
				<form method="get" class="rh-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<label class="screen-reader-text" for="rh-search"><?php esc_html_e( 'Search redirects', 'redirhub-redirects' ); ?></label>
					<input type="search" id="rh-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search paths and destinations', 'redirhub-redirects' ); ?>">
					<button class="button"><?php esc_html_e( 'Search', 'redirhub-redirects' ); ?></button>
				</form>
			</div>
			<p class="rh-muted">
				<?php
				printf(
					/* translators: %s: link to the RedirHub dashboard. */
					esc_html__( 'Read-only: add and change redirects in %s, they reach this site within a minute.', 'redirhub-redirects' ),
					'<a href="' . esc_url( self::DASHBOARD ) . '" target="_blank" rel="noopener">RedirHub</a>'
				);
				?>
			</p>
			<table class="widefat striped rh-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'From', 'redirhub-redirects' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To', 'redirhub-redirects' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'redirhub-redirects' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Options', 'redirhub-redirects' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( array() === $rows ) : ?>
					<tr><td colspan="4"><?php echo '' === $search ? esc_html__( 'No redirects yet. Add one in RedirHub.', 'redirhub-redirects' ) : esc_html__( 'No redirect matches the search.', 'redirhub-redirects' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as list( $host, $path, $stored ) ) : ?>
					<?php $catch_all = '*' === $path; ?>
					<tr>
						<td>
							<?php if ( $catch_all ) : ?>
								<code><?php echo esc_html( $host ); ?>/*</code>
								<br><span class="rh-muted"><?php esc_html_e( 'Every path WordPress answers with “not found”', 'redirhub-redirects' ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( set_url_scheme( 'http://' . $host . Path::escape( $path ) ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $host . $path ); ?></code></a>
							<?php endif; ?>
						</td>
						<td class="rh-target"><a href="<?php echo esc_url( $stored[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $stored[1] ); ?></a></td>
						<td><?php echo esc_html( self::type_label( $stored[2] ) ); ?></td>
						<td><?php echo self::badges( $stored ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badges(). ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						/* translators: %s: number of redirects. */
						printf( esc_html( _n( '%s redirect', '%s redirects', $total, 'redirhub-redirects' ) ), esc_html( number_format_i18n( $total ) ) );
						?>
					</span>
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%', self::url( '' === $search ? array() : array( 's' => $search ) ) ),
								'format'  => '',
								'current' => $paged,
								'total'   => $pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A rule's status column in words.
	 *
	 * @param int|string $status The status column.
	 */
	private static function type_label( $status ): string {
		$labels = array(
			301     => __( 'Permanent (301)', 'redirhub-redirects' ),
			302     => __( 'Temporary (302)', 'redirhub-redirects' ),
			307     => __( 'Temporary (307)', 'redirhub-redirects' ),
			308     => __( 'Permanent (308)', 'redirhub-redirects' ),
			'frame' => __( 'Frame page', 'redirhub-redirects' ),
		);

		return $labels[ $status ] ?? (string) $status;
	}

	/**
	 * The flags and options of a rule as badges.
	 *
	 * @param array $stored The stored rule.
	 */
	private static function badges( array $stored ): string {
		$badges = array();
		if ( $stored[3] ) {
			$badges[] = __( 'keeps the path', 'redirhub-redirects' );
		}
		if ( $stored[4] ) {
			$badges[] = __( 'keeps the query', 'redirhub-redirects' );
		}
		$options = $stored[5];
		$active  = Settings::link_options();
		if ( ! empty( $options['password'] ) ) {
			$badges[] = __( 'password', 'redirhub-redirects' );
		}
		if ( ! empty( $options['destinations'] ) ) {
			$badges[] = 'order' === ( $options['routing'] ?? '' ) ? __( 'rotates in turn', 'redirhub-redirects' ) : __( 'rotates at random', 'redirhub-redirects' );
		}
		$plugins = array_filter( explode( ',', (string) ( $options['plugins'] ?? '' ) ) );
		if ( in_array( 'nobot', $plugins, true ) ) {
			$badges[] = __( 'blocks bots', 'redirhub-redirects' );
		}
		if ( in_array( 'nowechat', $plugins, true ) ) {
			$badges[] = __( 'WeChat page', 'redirhub-redirects' );
		}
		$html = '';
		foreach ( $badges as $badge ) {
			$html .= '<span class="rh-badge">' . esc_html( $badge ) . '</span> ';
		}
		if ( ! $active && array() !== $options ) {
			$html .= '<span class="rh-badge rh-badge--off">' . esc_html__( 'options off', 'redirhub-redirects' ) . '</span>';
		}

		return '' === $html ? '<span class="rh-muted">—</span>' : $html;
	}

	/** The options. */
	private static function render_settings(): void {
		$settings = Settings::all();
		$server   = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shown escaped.
		$seen     = array(
			'remote_addr' => (string) ( $server['REMOTE_ADDR'] ?? '' ),
			'cloudflare'  => (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ),
			'x_real_ip'   => (string) ( $server['HTTP_X_REAL_IP'] ?? '' ),
		);
		$labels   = array(
			'remote_addr' => __( 'The connection (recommended unless a proxy stands in front)', 'redirhub-redirects' ),
			'cloudflare'  => __( 'Cloudflare’s CF-Connecting-IP header', 'redirhub-redirects' ),
			'x_real_ip'   => __( 'The X-Real-IP header my proxy sets', 'redirhub-redirects' ),
			'custom'      => __( 'Another header my proxy sets:', 'redirhub-redirects' ),
		);
		?>
		<form class="rh-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="redirhub_settings">
			<?php wp_nonce_field( 'redirhub_settings' ); ?>
			<h2><?php esc_html_e( 'Settings', 'redirhub-redirects' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Analytics', 'redirhub-redirects' ); ?></th>
					<td><label><input type="checkbox" name="reports" value="1" <?php checked( $settings['reports'] ); ?>> <?php esc_html_e( 'Report each redirect (and, where the domain asks, each “not found” page) to RedirHub’s analytics', 'redirhub-redirects' ); ?></label>
					<p class="description"><?php esc_html_e( 'Sent after the visitor has their answer: address, time, page, referrer and browser, as in a web server log. No cookies.', 'redirhub-redirects' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Link options', 'redirhub-redirects' ); ?></th>
					<td><label><input type="checkbox" name="link_options" value="1" <?php checked( $settings['link_options'] ); ?>> <?php esc_html_e( 'Carry out passwords, bot and WeChat blocking and rotating destinations set on a link', 'redirhub-redirects' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off: such links redirect plainly to their main destination.', 'redirhub-redirects' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Visitor address', 'redirhub-redirects' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Where the visitor’s address comes from', 'redirhub-redirects' ); ?></legend>
							<?php foreach ( $labels as $source => $label ) : ?>
								<label class="rh-radio">
									<input type="radio" name="ip_source" value="<?php echo esc_attr( $source ); ?>" <?php checked( $settings['ip_source'], $source ); ?>>
									<?php echo esc_html( $label ); ?>
									<?php if ( 'custom' === $source ) : ?>
										<input type="text" name="ip_header" class="regular-text code" value="<?php echo esc_attr( (string) $settings['ip_header'] ); ?>" placeholder="X-Client-IP">
									<?php elseif ( '' !== $seen[ $source ] ) : ?>
										<code class="rh-seen"><?php echo esc_html( $seen[ $source ] ); ?></code>
									<?php endif; ?>
								</label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Choose a header only if a proxy in front of this site overwrites it: a visitor can send any header. Your own address is shown next to each source.', 'redirhub-redirects' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'redirhub-redirects' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * The connection: what is saved, and changing or ending it.
	 *
	 * @param string $rules_url The address entered before a failed try.
	 */
	private static function render_connection( string $rules_url ): void {
		?>
		<div class="rh-card rh-connection">
			<h2><?php esc_html_e( 'Connection', 'redirhub-redirects' ); ?></h2>
			<details <?php echo '' === $rules_url ? '' : 'open'; ?>>
				<summary>
					<?php esc_html_e( 'Rules address', 'redirhub-redirects' ); ?> <code><?php echo esc_html( self::mask_url( Settings::rules_url() ) ); ?></code>
					· <?php esc_html_e( 'Integration key', 'redirhub-redirects' ); ?> <code><?php echo '' === Settings::key() ? esc_html__( 'none', 'redirhub-redirects' ) : esc_html( self::mask_key( Settings::key() ) ); ?></code>
					· <a><?php esc_html_e( 'Change', 'redirhub-redirects' ); ?></a>
				</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="redirhub_connect">
					<?php wp_nonce_field( 'redirhub_connect' ); ?>
					<?php self::connection_fields( $rules_url, true ); ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save and download', 'redirhub-redirects' ); ?></button></p>
				</form>
			</details>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rh-disconnect">
				<input type="hidden" name="action" value="redirhub_disconnect">
				<?php wp_nonce_field( 'redirhub_disconnect' ); ?>
				<button type="submit" class="button-link rh-danger" data-confirm><?php esc_html_e( 'Disconnect this site', 'redirhub-redirects' ); ?></button>
			</form>
			<p class="rh-muted">
				<?php
				/* translators: 1: plugin version, 2: format version. */
				printf( esc_html__( 'RedirHub Redirects %1$s · rules file format %2$s', 'redirhub-redirects' ), esc_html( REDIRHUB_REDIRECTS_VERSION ), '1' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * A one-button form for an action.
	 *
	 * @param string $action The action.
	 * @param string $label  The button's label.
	 */
	private static function button( string $action, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="redirhub_<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'redirhub_' . $action ); ?>
			<button type="submit" class="button" data-busy="<?php esc_attr_e( 'Working…', 'redirhub-redirects' ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
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
