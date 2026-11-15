<?php
/**
 * The markup of Tools → RedirHub.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Compat;
use RedirHub\Redirects\Core\Path;
use RedirHub\Redirects\Core\RuleSet;
use RedirHub\Redirects\Settings;
use RedirHub\Redirects\State;
use RedirHub\Redirects\Store;

/**
 * The admin page in the RedirHub dashboard's design (assets/src/admin.css): a header with the
 * status, then either the connection form or three tabs: Overview (how the redirects reach
 * visitors, and what to do when something is in the way), Redirects (the list) and Settings.
 * Every value is escaped where it is printed; helpers that return markup escape inside.
 */
final class View {

	const TABS = array( 'overview', 'redirects', 'settings' );

	/**
	 * The page.
	 *
	 * @param array<string, mixed> $flash Messages and data of the last action.
	 */
	public static function render( array $flash ): void {
		$level = Status::level();
		$rules = Store::load();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a tab of a read-only view.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'overview';

		echo '<div id="rh-app" class="rh:min-h-screen">';
		self::header( $level, $rules );
		echo '<div class="rh:mx-auto rh:max-w-[1120px] rh:px-4 rh:md:px-8 rh:py-6 rh:flex rh:flex-col rh:gap-6">';
		echo '<hr class="wp-header-end" style="display:none">';
		foreach ( (array) ( $flash['messages'] ?? array() ) as $message ) {
			self::alert( (string) $message[0], (string) $message[1], true );
		}

		if ( Status::OFF === $level ) {
			self::onboarding( (string) ( $flash['rules_url'] ?? '' ) );
		} else {
			self::tabs( $tab, null === $rules ? 0 : $rules->count() );
			if ( 'redirects' === $tab ) {
				self::redirects( $rules );
			} elseif ( 'settings' === $tab ) {
				self::settings( (string) ( $flash['rules_url'] ?? '' ) );
			} else {
				self::overview( $level, $rules, (array) ( $flash['test'] ?? array() ) );
			}
		}
		echo '</div></div>';
	}

	/* ------------------------------------------------------------------ chrome */

	/**
	 * The header: the mark, the name, the status, and the way to RedirHub.
	 *
	 * @param string       $level Status::level().
	 * @param RuleSet|null $rules The rules in use.
	 */
	private static function header( string $level, ?RuleSet $rules ): void {
		$subtitle = Status::OFF === $level || null === $rules
			? __( 'Every old link, on the right page.', 'redirhub-redirects' )
			/* translators: 1: hostnames, 2: number of redirects. */
			: sprintf( _n( '%1$s · %2$s redirect', '%1$s · %2$s redirects', $rules->count(), 'redirhub-redirects' ), implode( ', ', $rules->hostnames() ), number_format_i18n( $rules->count() ) );
		?>
		<header class="rh:bg-card rh:border-b rh:border-border">
			<div class="rh:mx-auto rh:max-w-[1120px] rh:px-4 rh:md:px-8 rh:py-4 rh:flex rh:flex-wrap rh:items-center rh:gap-x-4 rh:gap-y-3">
				<img src="<?php echo esc_url( plugin_dir_url( REDIRHUB_REDIRECTS_FILE ) . 'assets/logo.svg' ); ?>" alt="" width="32" height="36" class="rh:h-9 rh:w-8">
				<div class="rh:min-w-0 rh:flex-1">
					<div class="rh:flex rh:flex-wrap rh:items-center rh:gap-x-3 rh:gap-y-1">
						<h1 class="rh:text-lg rh:font-semibold rh:tracking-tight rh:text-foreground"><?php esc_html_e( 'RedirHub Redirects', 'redirhub-redirects' ); ?></h1>
						<?php echo self::status_badge( $level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					</div>
					<p class="rh:mt-0.5 rh:truncate rh:text-sm rh:text-muted-foreground"><?php echo esc_html( $subtitle ); ?></p>
				</div>
				<a href="<?php echo esc_url( Page::DASHBOARD ); ?>" target="_blank" rel="noopener" class="<?php echo esc_attr( self::BUTTON_SECONDARY . ' rh:max-sm:hidden' ); ?>">
					<?php esc_html_e( 'Open RedirHub', 'redirhub-redirects' ); ?>
					<?php echo Icons::svg( 'arrow-up-right', 'rh:size-4 rh:text-muted-foreground' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			</div>
		</header>
		<?php
	}

	/**
	 * The tabs of a connected site.
	 *
	 * @param string $current The tab shown.
	 * @param int    $count   The number of redirects.
	 */
	private static function tabs( string $current, int $count ): void {
		$tabs = array(
			'overview'  => __( 'Overview', 'redirhub-redirects' ),
			'redirects' => __( 'Redirects', 'redirhub-redirects' ),
			'settings'  => __( 'Settings', 'redirhub-redirects' ),
		);
		echo '<nav class="rh:flex rh:gap-6 rh:border-b rh:border-border rh:-mb-2" aria-label="' . esc_attr__( 'Sections', 'redirhub-redirects' ) . '">';
		foreach ( $tabs as $tab => $label ) {
			$active = $tab === $current;
			printf(
				'<a href="%1$s" class="%2$s"%3$s data-tab="%4$s">%5$s%6$s</a>',
				esc_url( Page::url( 'overview' === $tab ? array() : array( 'tab' => $tab ) ) ),
				esc_attr( 'rh:-mb-px rh:inline-flex rh:items-center rh:gap-2 rh:border-b-2 rh:pb-3 rh:pt-1 rh:text-sm rh:font-medium rh:transition-colors ' . ( $active ? 'rh:border-primary-hover rh:text-primary-hover' : 'rh:border-transparent rh:text-muted-foreground rh:hover:text-foreground' ) ),
				$active ? ' aria-current="page"' : '',
				esc_attr( $tab ),
				esc_html( $label ),
				'redirects' === $tab ? ' <span class="rh:rounded-full rh:bg-secondary rh:px-2 rh:py-0.5 rh:text-xs rh:font-medium rh:text-secondary-foreground">' . esc_html( number_format_i18n( $count ) ) . '</span>' : ''
			);
		}
		echo '</nav>';
	}

	/* ------------------------------------------------------------------ onboarding */

	/**
	 * Not connected: what the site gets, and the connection form.
	 *
	 * @param string $rules_url The address entered before a failed try.
	 */
	private static function onboarding( string $rules_url ): void {
		$benefits = array(
			array( 'route', __( 'Keep your rankings when URLs change', 'redirhub-redirects' ), __( 'Permanent redirects carry an old page’s search ranking to its new address, one page or a whole site at a time.', 'redirhub-redirects' ) ),
			array( 'link-2', __( 'Short links on your own domain', 'redirhub-redirects' ), __( 'yoursite.com/spring for a flyer or a QR code. Change where it goes at any time, without reprinting.', 'redirhub-redirects' ) ),
			array( 'chart-column', __( 'See every click', 'redirhub-redirects' ), __( 'Clicks per link, where visitors come from, and the missing pages people still try to open.', 'redirhub-redirects' ) ),
			array( 'shield-check', __( 'Nothing to change on your server', 'redirhub-redirects' ), __( 'Your domain and DNS stay where they are. Redirects answer in a few milliseconds.', 'redirhub-redirects' ) ),
		);
		$steps    = array(
			sprintf(
				/* translators: %s: link to the RedirHub dashboard. */
				esc_html__( 'In %s, add your domain and choose “Connect through your own platform”, then WordPress.', 'redirhub-redirects' ),
				'<a class="rh:font-medium rh:text-primary-hover rh:hover:underline" href="' . esc_url( Page::DASHBOARD ) . '" target="_blank" rel="noopener">' . esc_html__( 'RedirHub', 'redirhub-redirects' ) . '</a>'
			),
			esc_html__( 'Copy the two values it shows and paste them below.', 'redirhub-redirects' ),
			esc_html__( 'Connect, then press “Check now” in RedirHub. That’s it.', 'redirhub-redirects' ),
		);
		?>
		<div class="rh:grid rh:items-start rh:gap-8 rh:lg:grid-cols-[minmax(0,1fr)_minmax(0,460px)] rh:lg:pt-4">
			<section class="rh:flex rh:flex-col rh:gap-6">
				<div>
					<span class="rh:inline-flex rh:items-center rh:gap-1.5 rh:rounded-full rh:border rh:border-info-edge rh:bg-info-soft rh:px-2.5 rh:py-0.5 rh:text-xs rh:font-medium rh:text-accent-foreground"><?php echo Icons::svg( 'zap', 'rh:size-3.5' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'About 2 minutes to set up', 'redirhub-redirects' ); ?></span>
					<h2 class="rh:mt-4 rh:text-3xl rh:font-semibold rh:leading-tight rh:tracking-tight rh:text-foreground rh:md:text-4xl"><?php esc_html_e( 'Every old link, on the right page', 'redirhub-redirects' ); ?></h2>
					<p class="rh:mt-3 rh:max-w-xl rh:text-base rh:leading-relaxed rh:text-secondary-foreground"><?php esc_html_e( 'Pages move and links get shared for years. Send every one of those visits where it belongs, and see what your links do.', 'redirhub-redirects' ); ?></p>
				</div>
				<ul class="rh:grid rh:gap-3 rh:sm:grid-cols-2">
					<?php foreach ( $benefits as list( $icon, $title, $text ) ) : ?>
						<li class="rh:flex rh:gap-3 rh:rounded-xl rh:border rh:border-border rh:bg-card rh:p-4 rh:shadow-card">
							<span class="rh:flex rh:size-9 rh:flex-none rh:items-center rh:justify-center rh:rounded-lg rh:bg-accent rh:text-accent-foreground"><?php echo Icons::svg( $icon, 'rh:size-4.5' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="rh:min-w-0">
								<span class="rh:block rh:text-sm rh:font-semibold rh:text-foreground"><?php echo esc_html( $title ); ?></span>
								<span class="rh:mt-1 rh:block rh:text-[13px] rh:leading-relaxed rh:text-muted-foreground"><?php echo esc_html( $text ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<form class="rh-connect rh:order-first rh:lg:order-none rh:rounded-2xl rh:border rh:border-border rh:bg-card rh:shadow-pop" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="redirhub_connect">
				<?php wp_nonce_field( 'redirhub_connect' ); ?>
				<div class="rh:border-b rh:border-border rh:px-6 rh:py-5">
					<h2 class="rh:text-base rh:font-semibold rh:text-foreground"><?php esc_html_e( 'Connect your domain', 'redirhub-redirects' ); ?></h2>
					<ol class="rh:mt-4 rh:flex rh:flex-col rh:gap-3">
						<?php foreach ( $steps as $index => $step ) : ?>
							<li class="rh:flex rh:gap-3 rh:text-sm rh:text-secondary-foreground">
								<span class="rh:flex rh:size-6 rh:flex-none rh:items-center rh:justify-center rh:rounded-full rh:bg-brand-dark rh:text-xs rh:font-semibold rh:text-white"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
								<span class="rh:pt-0.5"><?php echo $step; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
				<div class="rh:flex rh:flex-col rh:gap-5 rh:px-6 rh:py-5">
					<?php self::connection_fields( $rules_url, false ); ?>
				</div>
				<div class="rh:border-t rh:border-border rh:bg-background rh:px-6 rh:py-4 rh:rounded-b-2xl">
					<button type="submit" data-busy="<?php esc_attr_e( 'Connecting…', 'redirhub-redirects' ); ?>" class="<?php echo esc_attr( self::BUTTON_PRIMARY . ' rh:w-full rh:h-11 rh:text-[15px]' ); ?>"><?php esc_html_e( 'Connect', 'redirhub-redirects' ); ?><?php echo Icons::svg( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
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
	private static function connection_fields( string $rules_url, bool $change ): void {
		?>
		<div>
			<label for="rh-rules-url" class="rh:block rh:text-sm rh:font-medium rh:text-secondary-foreground"><?php esc_html_e( 'Rules address', 'redirhub-redirects' ); ?></label>
			<?php if ( Settings::rules_url_is_constant() ) : ?>
				<input type="text" id="rh-rules-url" class="<?php echo esc_attr( self::INPUT . ' rh:font-mono rh:bg-secondary' ); ?>" value="<?php echo esc_attr( Page::mask_url( Settings::rules_url() ) ); ?>" readonly>
				<p class="rh:mt-1.5 rh:text-[13px] rh:text-muted-foreground"><?php esc_html_e( 'Set in wp-config.php (REDIRHUB_RULES_URL).', 'redirhub-redirects' ); ?></p>
			<?php else : ?>
				<input type="text" inputmode="url" id="rh-rules-url" name="rules_url" class="<?php echo esc_attr( self::INPUT . ' rh:font-mono' ); ?>" <?php echo $change ? '' : 'required'; ?> placeholder="<?php echo esc_attr( $change ? Page::mask_url( Settings::rules_url() ) : 'https://…/….txt' ); ?>" value="<?php echo esc_attr( $rules_url ); ?>" autocomplete="off" spellcheck="false">
				<p class="rh:mt-1.5 rh:text-[13px] rh:text-muted-foreground"><?php echo $change ? esc_html__( 'Leave empty to keep the address you saved. Pasting the whole setup text works too.', 'redirhub-redirects' ) : esc_html__( 'Pasting the whole setup text works too: the address is picked out of it.', 'redirhub-redirects' ); ?></p>
			<?php endif; ?>
		</div>
		<div>
			<label for="rh-key" class="rh:flex rh:items-baseline rh:gap-2 rh:text-sm rh:font-medium rh:text-secondary-foreground"><?php esc_html_e( 'Integration key', 'redirhub-redirects' ); ?> <span class="rh:text-[13px] rh:font-normal rh:text-muted-foreground"><?php esc_html_e( 'Optional, to count clicks', 'redirhub-redirects' ); ?></span></label>
			<?php if ( Settings::key_is_constant() ) : ?>
				<input type="text" id="rh-key" class="<?php echo esc_attr( self::INPUT . ' rh:font-mono rh:bg-secondary' ); ?>" value="<?php echo esc_attr( Page::mask_key( Settings::key() ) ); ?>" readonly>
				<p class="rh:mt-1.5 rh:text-[13px] rh:text-muted-foreground"><?php esc_html_e( 'Set in wp-config.php (REDIRHUB_INTEGRATION_KEY).', 'redirhub-redirects' ); ?></p>
			<?php else : ?>
				<input type="password" id="rh-key" name="key" class="<?php echo esc_attr( self::INPUT . ' rh:font-mono' ); ?>" autocomplete="new-password" spellcheck="false" placeholder="<?php echo '' === Settings::key() ? '' : esc_attr( Page::mask_key( Settings::key() ) ); ?>">
				<p class="rh:mt-1.5 rh:text-[13px] rh:text-muted-foreground"><?php echo '' === Settings::key() ? esc_html__( 'Without it your redirects work, but their clicks are not counted. Workspace managers find it in RedirHub.', 'redirhub-redirects' ) : esc_html__( 'Leave empty to keep the key you saved.', 'redirhub-redirects' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ overview */

	/**
	 * How the redirects reach visitors, the status of each part, and what is in the way.
	 *
	 * @param string                    $level   Status::level().
	 * @param RuleSet|null              $rules   The rules in use.
	 * @param array<int, array<string, mixed>> $results The self-test's findings, when it just ran.
	 */
	private static function overview( string $level, ?RuleSet $rules, array $results ): void {
		$state    = State::get();
		$checks   = Status::checked_hosts();
		$reports  = State::activity()['reports'];
		$tracking = Settings::reports() && ( null === $rules || null !== $rules->hits_url() );
		$synced   = null !== $rules && ! in_array( $level, array( Status::FAILING, Status::STALE ), true );
		$verified = array() !== $checks;

		if ( array() !== $results ) {
			self::test_results( $results );
		}
		self::flow( $rules, $synced, $verified, $tracking, $level );
		foreach ( self::diagnostics() as $note ) {
			self::alert( $note[0], $note[1] );
		}

		echo '<section class="rh:overflow-hidden rh:rounded-xl rh:border rh:border-border rh:bg-card rh:shadow-card">';
		echo '<h2 class="rh:border-b rh:border-border rh:px-5 rh:py-3 rh:text-xs rh:font-semibold rh:uppercase rh:tracking-wide rh:text-muted-foreground">' . esc_html__( 'Status', 'redirhub-redirects' ) . '</h2>';
		echo '<ul class="rh:divide-y rh:divide-border">';

		// Redirects.
		if ( null === $rules ) {
			$detail = Status::error_text( (string) $state['error'] );
			$badge  = self::badge( 'danger', __( 'Not downloaded', 'redirhub-redirects' ) );
		} else {
			$detail = sprintf(
				/* translators: 1: when last checked, 2: when last changed. */
				__( 'Checked %1$s · last updated %2$s. Changes you make in RedirHub arrive within a minute.', 'redirhub-redirects' ),
				Status::ago( (int) $state['fetched_at'] ),
				Status::ago( (int) $state['changed_at'] )
			);
			if ( '' !== $state['error'] ) {
				/* translators: 1: when, 2: the error. */
				$detail = sprintf( __( 'Couldn’t update %1$s: %2$s', 'redirhub-redirects' ), Status::ago( (int) $state['error_at'] ), Status::error_text( (string) $state['error'] ) );
			}
			$badge = $synced && '' === $state['error'] ? self::badge( 'success', __( 'Up to date', 'redirhub-redirects' ) ) : self::badge( 'warning', __( 'Using the last good copy', 'redirhub-redirects' ) );
		}
		self::row(
			'rules',
			'refresh-cw',
			null === $rules
				? __( 'Redirects', 'redirhub-redirects' )
				/* translators: %s: number of redirects. */
				: sprintf( _n( '%s redirect live', '%s redirects live', $rules->count(), 'redirhub-redirects' ), '<span data-rules-count="' . esc_attr( (string) $rules->count() ) . '">' . esc_html( number_format_i18n( $rules->count() ) ) . '</span>' ),
			$detail,
			$badge . self::action_button( 'sync', __( 'Update now', 'redirhub-redirects' ), 'refresh-cw' )
		);

		// Domain verified.
		if ( $verified ) {
			$hosts = array();
			foreach ( $checks as $host => $at ) {
				/* translators: 1: hostname, 2: when. */
				$hosts[] = sprintf( __( '%1$s %2$s', 'redirhub-redirects' ), $host, Status::ago( $at ) );
			}
			self::row( 'check', 'shield-check', esc_html__( 'Domain verified', 'redirhub-redirects' ), sprintf( /* translators: %s: hostnames and when they were checked. */ __( 'Checked by RedirHub: %s. It keeps checking that this site runs your latest redirects.', 'redirhub-redirects' ), implode( ', ', $hosts ) ), self::badge( 'success', __( 'Verified', 'redirhub-redirects' ) ) );
		} else {
			self::row( 'check', 'shield-check', esc_html__( 'Verify your domain', 'redirhub-redirects' ), __( 'One last step: press “Check now” on your domain in RedirHub. As soon as it reaches this site, your domain is verified.', 'redirhub-redirects' ), self::badge( 'warning', __( 'Waiting', 'redirhub-redirects' ) ) . self::link_button( Page::DASHBOARD, __( 'Open RedirHub', 'redirhub-redirects' ), true ) );
		}

		// Click tracking.
		if ( ! Settings::reports() ) {
			self::row( 'reports', 'mouse-pointer-click', esc_html__( 'Click tracking', 'redirhub-redirects' ), '' === Settings::key() ? __( 'Add your integration key to see how often each link is clicked.', 'redirhub-redirects' ) : __( 'Switched off in Settings.', 'redirhub-redirects' ), self::badge( 'neutral', __( 'Off', 'redirhub-redirects' ) ) . self::link_button( Page::url( array( 'tab' => 'settings' ) ), __( 'Settings', 'redirhub-redirects' ) ) );
		} elseif ( ! $tracking ) {
			self::row( 'reports', 'mouse-pointer-click', esc_html__( 'Click tracking', 'redirhub-redirects' ), __( 'Click tracking is not available for this domain.', 'redirhub-redirects' ), self::badge( 'neutral', __( 'Not available', 'redirhub-redirects' ) ) );
		} else {
			$failed = '' !== $reports['error'] && (int) $reports['error_at'] > (int) $reports['sent_at'];
			$detail = 0 === (int) $reports['sent_at']
				? __( 'Every click on a redirect is counted. No clicks yet.', 'redirhub-redirects' )
				/* translators: %s: when. */
				: sprintf( __( 'Last click counted %s. See them all in your RedirHub stats.', 'redirhub-redirects' ), Status::ago( (int) $reports['sent_at'] ) );
			if ( $failed ) {
				/* translators: 1: when, 2: the error. */
				$detail .= ' ' . sprintf( __( 'Some clicks could not be counted %1$s (%2$s).', 'redirhub-redirects' ), Status::ago( (int) $reports['error_at'] ), (string) $reports['error'] );
			}
			self::row( 'reports', 'mouse-pointer-click', esc_html__( 'Click tracking', 'redirhub-redirects' ), $detail, $failed ? self::badge( 'warning', __( 'Some missed', 'redirhub-redirects' ) ) : self::badge( 'success', __( 'On', 'redirhub-redirects' ) ) );
		}

		// Test.
		self::row( 'test', 'play', esc_html__( 'Test your redirects', 'redirhub-redirects' ), __( 'Opens your domain from the outside, as a visitor would, through any cache or CDN in front of your site, and tells you what to fix.', 'redirhub-redirects' ), self::action_button( 'test', __( 'Run the test', 'redirhub-redirects' ), 'play' ) );

		echo '</ul></section>';
	}

	/**
	 * The way a visit goes: RedirHub → this site → the visitor, each link with its state.
	 *
	 * @param RuleSet|null $rules    The rules in use.
	 * @param bool         $synced   Whether the rules are current.
	 * @param bool         $verified Whether RedirHub checked the site.
	 * @param bool         $tracking Whether clicks are counted.
	 * @param string       $level    Status::level().
	 */
	private static function flow( ?RuleSet $rules, bool $synced, bool $verified, bool $tracking, string $level ): void {
		$site  = null === $rules ? Status::site_host() : implode( ', ', $rules->hostnames() );
		$nodes = array(
			array( 'layout-dashboard', __( 'RedirHub', 'redirhub-redirects' ), null === $rules ? __( 'No redirects yet', 'redirhub-redirects' ) : sprintf( /* translators: %s: number. */ _n( '%s redirect', '%s redirects', $rules->count(), 'redirhub-redirects' ), number_format_i18n( $rules->count() ) ) ),
			array( 'monitor', __( 'Your site', 'redirhub-redirects' ), $site ),
			array( 'mouse-pointer-click', __( 'Visitors', 'redirhub-redirects' ), $tracking ? __( 'Clicks counted', 'redirhub-redirects' ) : __( 'Clicks not counted', 'redirhub-redirects' ) ),
		);
		$links = array(
			$synced ? array( 'success', __( 'In sync', 'redirhub-redirects' ) ) : array( Status::FAILING === $level ? 'danger' : 'warning', Status::FAILING === $level ? __( 'Can’t update', 'redirhub-redirects' ) : __( 'Update failing', 'redirhub-redirects' ) ),
			$verified ? array( 'success', __( 'Verified', 'redirhub-redirects' ) ) : array( 'warning', __( 'Not verified yet', 'redirhub-redirects' ) ),
		);
		echo '<section class="rh:rounded-xl rh:border rh:border-border rh:bg-card rh:p-4 rh:shadow-card rh:md:p-5" aria-label="' . esc_attr__( 'How your redirects reach visitors', 'redirhub-redirects' ) . '">';
		echo '<div class="rh:flex rh:flex-col rh:items-stretch rh:gap-3 rh:md:flex-row rh:md:items-center rh:md:gap-0">';
		foreach ( $nodes as $index => list( $icon, $title, $text ) ) {
			printf(
				'<div class="rh:flex rh:min-w-0 rh:items-center rh:gap-3 rh:md:flex-none rh:md:max-w-[30%%]"><span class="%1$s">%2$s</span><span class="rh:min-w-0"><span class="rh:block rh:text-sm rh:font-semibold rh:text-foreground">%3$s</span><span class="rh:block rh:truncate rh:font-mono rh:text-xs rh:text-muted-foreground">%4$s</span></span></div>',
				esc_attr( 'rh:flex rh:size-10 rh:flex-none rh:items-center rh:justify-center rh:rounded-xl rh:border rh:border-border ' . ( 1 === $index ? 'rh:bg-accent rh:text-accent-foreground' : 'rh:bg-card rh:text-secondary-foreground' ) ),
				Icons::svg( $icon, 'rh:size-5' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $title ),
				esc_html( $text )
			);
			if ( isset( $links[ $index ] ) ) {
				list( $tone, $label ) = $links[ $index ];
				$line                 = 'success' === $tone ? 'rh:bg-brand-teal' : ( 'danger' === $tone ? 'rh-dash rh:text-danger' : 'rh-dash rh:text-brand-amber' );
				printf(
					'<div class="rh:flex rh:items-center rh:gap-2 rh:pl-[52px] rh:md:flex-1 rh:md:px-3"><span class="%1$s"></span>%2$s<span class="%1$s"></span></div>',
					esc_attr( 'rh:hidden rh:h-0.5 rh:min-w-4 rh:flex-1 rh:md:block ' . $line ),
					self::badge( $tone, $label ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}
		}
		echo '</div></section>';
	}

	/**
	 * The self-test's findings.
	 *
	 * @param array<int, array<string, mixed>> $results The findings.
	 */
	private static function test_results( array $results ): void {
		$ok = ! in_array( false, array_column( $results, 'ok' ), true );
		echo '<section class="rh-test rh:rounded-xl rh:border rh:bg-card rh:shadow-card ' . esc_attr( $ok ? 'rh:border-success-edge' : 'rh:border-danger-edge' ) . '">';
		echo '<h2 class="rh:flex rh:items-center rh:gap-2 rh:border-b rh:border-border rh:px-5 rh:py-3 rh:text-sm rh:font-semibold rh:text-foreground">' . Icons::svg( $ok ? 'circle-check' : 'circle-alert', 'rh:size-4 ' . ( $ok ? 'rh:text-success' : 'rh:text-danger' ) ) . esc_html( $ok ? __( 'Everything works from the outside', 'redirhub-redirects' ) : __( 'Something is in the way', 'redirhub-redirects' ) ) . '</h2><ul class="rh:divide-y rh:divide-border">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $results as $result ) {
			printf(
				'<li class="rh:flex rh:gap-3 rh:px-5 rh:py-3" data-ok="%1$s">%2$s<span class="rh:min-w-0"><span class="rh:block rh:text-sm rh:font-medium rh:text-foreground">%3$s</span><span class="rh:block rh:break-words rh:text-[13px] rh:text-muted-foreground">%4$s</span></span></li>',
				! empty( $result['ok'] ) ? '1' : '0',
				Icons::svg( ! empty( $result['ok'] ) ? 'circle-check' : 'circle-alert', 'rh:mt-0.5 rh:size-4 ' . ( ! empty( $result['ok'] ) ? 'rh:text-success' : 'rh:text-danger' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( (string) $result['title'] ),
				esc_html( (string) $result['detail'] )
			);
		}
		echo '</ul></section>';
	}

	/**
	 * What may keep redirects from working, with what to do.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function diagnostics(): array {
		$notes = array();
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$notes[] = array( 'danger', __( 'Plain permalinks are on, so the web server answers unknown addresses itself and they never reach your redirects. Choose any other structure under Settings → Permalinks.', 'redirhub-redirects' ) );
		}
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $home_path ) {
			/* translators: %s: a path such as /blog/. */
			$notes[] = array( 'warning', sprintf( __( 'WordPress runs under %s: only addresses below it can be redirected, and your domain is verified at its root. Serve WordPress at the root, or connect this domain another way.', 'redirhub-redirects' ), $home_path ) );
		}
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$notes[] = array( 'warning', __( 'This site’s address starts with http://. Your domain is verified over HTTPS, so it needs a certificate.', 'redirhub-redirects' ) );
		}
		$server = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tested for presence.
		if ( 'remote_addr' === Settings::all()['ip_source'] && ( isset( $server['HTTP_CF_CONNECTING_IP'] ) || isset( $server['HTTP_X_FORWARDED_FOR'] ) || isset( $server['HTTP_X_REAL_IP'] ) ) ) {
			$notes[] = array( 'warning', __( 'A proxy or CDN stands in front of this site, so every visitor seems to come from its address: in your stats, and for the limit on password attempts. Choose its header under Settings → Visitor address.', 'redirhub-redirects' ) );
		}
		$caches = Compat::detected();
		if ( array() !== $caches ) {
			/* translators: %s: names of cache plugins. */
			$notes[] = array( 'info', sprintf( __( 'Page cache found (%s). When a redirect changes, its old cached page is cleared for you. A CDN or host cache the plugin can’t reach may need clearing by hand; the test shows it.', 'redirhub-redirects' ), implode( ', ', $caches ) ) );
		}
		if ( Store::IN_OPTION === State::get()['file'] ) {
			$notes[] = array( 'info', __( 'wp-content/cache can’t be written, so your redirects are kept in the database. They work; with many of them they are faster when that folder is writable.', 'redirhub-redirects' ) );
		}

		return $notes;
	}

	/* ------------------------------------------------------------------ redirects */

	/**
	 * The redirects of the site, searchable, a page at a time.
	 *
	 * @param RuleSet|null $rules The rules in use.
	 */
	private static function redirects( ?RuleSet $rules ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters of a list.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$rows = array();
		foreach ( null === $rules ? array() : $rules->to_array()['hosts'] as $host => $entry ) {
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
		$pages = max( 1, (int) ceil( $total / Page::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = array_slice( $rows, ( $paged - 1 ) * Page::PER_PAGE, Page::PER_PAGE );
		$base  = array( 'tab' => 'redirects' ) + ( '' === $search ? array() : array( 's' => $search ) );
		?>
		<section class="rh-rules rh:overflow-hidden rh:rounded-xl rh:border rh:border-border rh:bg-card rh:shadow-card">
			<div class="rh:flex rh:flex-wrap rh:items-center rh:gap-3 rh:border-b rh:border-border rh:px-5 rh:py-4">
				<div class="rh:min-w-0 rh:flex-1">
					<h2 class="rh:text-base rh:font-semibold rh:text-foreground"><?php esc_html_e( 'Redirects in use', 'redirhub-redirects' ); ?></h2>
					<p class="rh:mt-0.5 rh:text-[13px] rh:text-muted-foreground">
						<?php
						printf(
							/* translators: %s: link to RedirHub. */
							esc_html__( 'To add or change one, use %s: it is live here within a minute.', 'redirhub-redirects' ),
							'<a class="rh:font-medium rh:text-primary-hover rh:hover:underline" href="' . esc_url( Page::DASHBOARD ) . '" target="_blank" rel="noopener">RedirHub</a>'
						);
						?>
					</p>
				</div>
				<form method="get" class="rh-search rh:flex rh:w-full rh:gap-2 rh:sm:w-auto">
					<input type="hidden" name="page" value="<?php echo esc_attr( Page::SLUG ); ?>">
					<input type="hidden" name="tab" value="redirects">
					<label class="rh:relative rh:flex-1 rh:sm:w-72">
						<span class="rh:sr-only"><?php esc_html_e( 'Search redirects', 'redirhub-redirects' ); ?></span>
						<?php echo Icons::svg( 'search', 'rh:pointer-events-none rh:absolute rh:left-3 rh:top-1/2 rh:size-4 rh:-translate-y-1/2 rh:text-muted-foreground' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="search" id="rh-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search paths and destinations', 'redirhub-redirects' ); ?>" class="<?php echo esc_attr( self::INPUT . ' rh:mt-0 rh:pl-9' ); ?>">
					</label>
					<button class="<?php echo esc_attr( self::BUTTON_SECONDARY ); ?>"><?php esc_html_e( 'Search', 'redirhub-redirects' ); ?></button>
				</form>
			</div>
			<?php if ( array() === $rows ) : ?>
				<div class="rh:flex rh:flex-col rh:items-center rh:gap-2 rh:px-6 rh:py-14 rh:text-center">
					<span class="rh:flex rh:size-10 rh:items-center rh:justify-center rh:rounded-xl rh:bg-secondary rh:text-muted-foreground"><?php echo Icons::svg( 'route', 'rh:size-5' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<p class="rh:text-sm rh:font-medium rh:text-foreground"><?php echo '' === $search ? esc_html__( 'No redirects yet', 'redirhub-redirects' ) : esc_html__( 'No redirect matches your search', 'redirhub-redirects' ); ?></p>
					<p class="rh:text-[13px] rh:text-muted-foreground"><?php echo '' === $search ? esc_html__( 'Add one in RedirHub and it shows up here within a minute.', 'redirhub-redirects' ) : esc_html__( 'Try part of the old address or of the destination.', 'redirhub-redirects' ); ?></p>
				</div>
			<?php else : ?>
				<table class="rh-table rh:w-full rh:text-left rh:text-sm rh:md:table-fixed">
					<thead class="rh:hidden rh:bg-background rh:text-xs rh:font-medium rh:text-muted-foreground rh:md:table-header-group">
						<tr>
							<th scope="col" class="rh:w-[31%] rh:px-5 rh:py-2.5 rh:font-medium"><?php esc_html_e( 'From', 'redirhub-redirects' ); ?></th>
							<th scope="col" class="rh:w-[33%] rh:px-5 rh:py-2.5 rh:font-medium"><?php esc_html_e( 'To', 'redirhub-redirects' ); ?></th>
							<th scope="col" class="rh:w-[15%] rh:px-5 rh:py-2.5 rh:font-medium"><?php esc_html_e( 'Type', 'redirhub-redirects' ); ?></th>
							<th scope="col" class="rh:px-5 rh:py-2.5 rh:font-medium"><?php esc_html_e( 'Options', 'redirhub-redirects' ); ?></th>
						</tr>
					</thead>
					<tbody class="rh:divide-y rh:divide-border">
					<?php foreach ( $rows as list( $host, $path, $stored ) ) : ?>
						<tr class="rh:block rh:px-5 rh:py-3 rh:md:table-row rh:md:p-0 rh:hover:bg-background">
							<td class="rh:block rh:md:table-cell rh:md:px-5 rh:md:py-3">
								<?php if ( '*' === $path ) : ?>
									<span class="rh:font-mono rh:text-[13px] rh:text-foreground"><?php echo esc_html( $host ); ?>/*</span>
									<span class="rh:block rh:text-xs rh:text-muted-foreground"><?php esc_html_e( 'Every address this site can’t find', 'redirhub-redirects' ); ?></span>
								<?php else : ?>
									<a class="rh:wrap-anywhere rh:font-mono rh:text-[13px] rh:text-primary-hover rh:hover:underline" href="<?php echo esc_url( set_url_scheme( 'http://' . $host . Path::escape( $path ) ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $host . $path ); ?></a>
								<?php endif; ?>
							</td>
							<td class="rh-target rh:block rh:md:table-cell rh:md:px-5 rh:md:py-3">
								<span class="rh:flex rh:min-w-0 rh:items-start rh:gap-1.5 rh:text-secondary-foreground"><?php echo Icons::svg( 'corner-down-right', 'rh:mt-0.5 rh:size-3.5 rh:text-muted-foreground rh:md:hidden' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a class="rh:wrap-anywhere rh:hover:underline" href="<?php echo esc_url( $stored[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $stored[1] ); ?></a></span>
							</td>
							<td class="rh:inline-block rh:pt-2 rh:pr-2 rh:md:table-cell rh:md:px-5 rh:md:py-3"><?php echo self::type_badge( $stored[2] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="rh:inline-block rh:pt-2 rh:md:table-cell rh:md:px-5 rh:md:py-3"><?php echo self::option_chips( $stored ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="rh:flex rh:flex-wrap rh:items-center rh:justify-between rh:gap-3 rh:border-t rh:border-border rh:px-5 rh:py-3 rh:text-[13px] rh:text-muted-foreground">
					<span>
						<?php
						/* translators: %s: number of redirects. */
						printf( esc_html( _n( '%s redirect', '%s redirects', $total, 'redirhub-redirects' ) ), esc_html( number_format_i18n( $total ) ) );
						?>
					</span>
					<?php if ( $pages > 1 ) : ?>
						<span class="rh:flex rh:items-center rh:gap-2">
							<?php if ( $paged > 1 ) : ?>
								<a class="<?php echo esc_attr( self::BUTTON_SECONDARY ); ?>" href="<?php echo esc_url( Page::url( $base + array( 'paged' => (string) ( $paged - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'redirhub-redirects' ); ?></a>
							<?php endif; ?>
							<span>
								<?php
								/* translators: 1: page, 2: number of pages. */
								printf( esc_html__( 'Page %1$s of %2$s', 'redirhub-redirects' ), esc_html( number_format_i18n( $paged ) ), esc_html( number_format_i18n( $pages ) ) );
								?>
							</span>
							<?php if ( $paged < $pages ) : ?>
								<a class="<?php echo esc_attr( self::BUTTON_SECONDARY ); ?>" href="<?php echo esc_url( Page::url( $base + array( 'paged' => (string) ( $paged + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'redirhub-redirects' ); ?></a>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * A rule's status column as a badge.
	 *
	 * @param int|string $status The status column.
	 */
	private static function type_badge( $status ): string {
		$labels = array(
			301     => __( '301 · Permanent', 'redirhub-redirects' ),
			302     => __( '302 · Temporary', 'redirhub-redirects' ),
			307     => __( '307 · Temporary', 'redirhub-redirects' ),
			308     => __( '308 · Permanent', 'redirhub-redirects' ),
			'frame' => __( 'Frame page', 'redirhub-redirects' ),
		);

		return '<span class="rh:inline-flex rh:whitespace-nowrap rh:rounded-md rh:border rh:border-border rh:bg-background rh:px-2 rh:py-0.5 rh:text-xs rh:font-medium rh:text-secondary-foreground">' . esc_html( $labels[ $status ] ?? (string) $status ) . '</span>';
	}

	/**
	 * The flags and options of a rule as chips.
	 *
	 * @param array $stored The stored rule.
	 */
	private static function option_chips( array $stored ): string {
		$chips   = array();
		$options = $stored[5];
		if ( $stored[3] ) {
			$chips[] = array( 'corner-down-right', __( 'Keeps the path', 'redirhub-redirects' ) );
		}
		if ( $stored[4] ) {
			$chips[] = array( 'link-2', __( 'Keeps the query', 'redirhub-redirects' ) );
		}
		if ( ! empty( $options['password'] ) ) {
			$chips[] = array( 'lock', __( 'Password', 'redirhub-redirects' ) );
		}
		if ( ! empty( $options['destinations'] ) ) {
			$chips[] = array( 'shuffle', 'order' === ( $options['routing'] ?? '' ) ? __( 'Rotates in turn', 'redirhub-redirects' ) : __( 'Rotates at random', 'redirhub-redirects' ) );
		}
		$plugins = array_filter( explode( ',', (string) ( $options['plugins'] ?? '' ) ) );
		if ( in_array( 'nobot', $plugins, true ) ) {
			$chips[] = array( 'bot', __( 'Blocks bots', 'redirhub-redirects' ) );
		}
		if ( in_array( 'nowechat', $plugins, true ) ) {
			$chips[] = array( 'message-circle', __( 'WeChat help', 'redirhub-redirects' ) );
		}
		$html = '';
		foreach ( $chips as list( $icon, $label ) ) {
			$html .= '<span class="rh-badge rh:mb-1 rh:mr-1 rh:inline-flex rh:items-center rh:gap-1 rh:whitespace-nowrap rh:rounded-full rh:bg-secondary rh:px-2 rh:py-0.5 rh:text-xs rh:text-secondary-foreground">' . Icons::svg( $icon, 'rh:size-3' ) . esc_html( $label ) . '</span>';
		}
		if ( ! Settings::link_options() && array() !== $options ) {
			$html .= '<span class="rh-badge rh-badge--off rh:inline-flex rh:rounded-full rh:bg-warning-soft rh:px-2 rh:py-0.5 rh:text-xs rh:text-warning">' . esc_html__( 'Extras off', 'redirhub-redirects' ) . '</span>';
		}

		return '' === $html ? '<span class="rh:text-muted-foreground">—</span>' : $html;
	}

	/* ------------------------------------------------------------------ settings */

	/**
	 * The options, the connection and disconnecting.
	 *
	 * @param string $rules_url The address entered before a failed try.
	 */
	private static function settings( string $rules_url ): void {
		$settings = Settings::all();
		$server   = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shown escaped.
		$seen     = array(
			'remote_addr' => (string) ( $server['REMOTE_ADDR'] ?? '' ),
			'cloudflare'  => (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ),
			'x_real_ip'   => (string) ( $server['HTTP_X_REAL_IP'] ?? '' ),
			'custom'      => '',
		);
		$sources  = array(
			'remote_addr' => array( __( 'Direct connection', 'redirhub-redirects' ), __( 'Right for most sites, unless a proxy or CDN stands in front.', 'redirhub-redirects' ) ),
			'cloudflare'  => array( __( 'Cloudflare', 'redirhub-redirects' ), __( 'The CF-Connecting-IP header Cloudflare sets.', 'redirhub-redirects' ) ),
			'x_real_ip'   => array( __( 'X-Real-IP', 'redirhub-redirects' ), __( 'The header your own proxy sets.', 'redirhub-redirects' ) ),
			'custom'      => array( __( 'Another header', 'redirhub-redirects' ), __( 'Only one your proxy overwrites: a visitor can send any header.', 'redirhub-redirects' ) ),
		);
		$toggles  = array(
			array( 'reports', __( 'Click tracking', 'redirhub-redirects' ), __( 'Count every click on a redirect, and every missing page if you turned that on for the domain. Visitors never wait for it; no cookies.', 'redirhub-redirects' ) ),
			array( 'link_options', __( 'Link extras', 'redirhub-redirects' ), __( 'Use the password, bot blocking, WeChat help and rotating destinations you set on a link. Off: such links go straight to their main destination.', 'redirhub-redirects' ) ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rh:overflow-hidden rh:rounded-xl rh:border rh:border-border rh:bg-card rh:shadow-card">
			<input type="hidden" name="action" value="redirhub_settings">
			<input type="hidden" name="tab" value="settings">
			<?php wp_nonce_field( 'redirhub_settings' ); ?>
			<ul class="rh:divide-y rh:divide-border">
				<?php foreach ( $toggles as list( $name, $title, $text ) ) : ?>
					<li>
						<label class="rh:flex rh:cursor-pointer rh:items-start rh:gap-4 rh:px-5 rh:py-4">
							<span class="rh:min-w-0 rh:flex-1">
								<span class="rh:block rh:text-sm rh:font-semibold rh:text-foreground"><?php echo esc_html( $title ); ?></span>
								<span class="rh:mt-0.5 rh:block rh:text-[13px] rh:leading-relaxed rh:text-muted-foreground"><?php echo esc_html( $text ); ?></span>
							</span>
							<input type="checkbox" role="switch" class="rh-switch rh:mt-0.5" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $settings[ $name ] ); ?>>
						</label>
					</li>
				<?php endforeach; ?>
				<li class="rh:px-5 rh:py-4">
					<fieldset>
						<legend class="rh:text-sm rh:font-semibold rh:text-foreground"><?php esc_html_e( 'Visitor address', 'redirhub-redirects' ); ?></legend>
						<p class="rh:mt-0.5 rh:text-[13px] rh:text-muted-foreground"><?php esc_html_e( 'Where your stats take the visitor’s address from. Your own address is shown next to each source.', 'redirhub-redirects' ); ?></p>
						<div class="rh:mt-3 rh:grid rh:gap-2 rh:sm:grid-cols-2">
							<?php foreach ( $sources as $source => list( $title, $text ) ) : ?>
								<label class="rh:flex rh:cursor-pointer rh:gap-3 rh:rounded-lg rh:border rh:border-border rh:p-3 rh:has-checked:border-primary rh:has-checked:bg-info-soft">
									<input type="radio" class="rh-radio" name="ip_source" value="<?php echo esc_attr( $source ); ?>" <?php checked( $settings['ip_source'], $source ); ?>>
									<span class="rh:min-w-0 rh:flex-1">
										<span class="rh:flex rh:flex-wrap rh:items-center rh:gap-2 rh:text-sm rh:font-medium rh:text-foreground"><?php echo esc_html( $title ); ?>
											<?php if ( '' !== $seen[ $source ] ) : ?>
												<code class="rh:rounded rh:bg-secondary rh:px-1.5 rh:py-0.5 rh:text-xs rh:font-normal rh:text-secondary-foreground"><?php echo esc_html( $seen[ $source ] ); ?></code>
											<?php endif; ?>
										</span>
										<span class="rh:mt-0.5 rh:block rh:text-xs rh:text-muted-foreground"><?php echo esc_html( $text ); ?></span>
										<?php if ( 'custom' === $source ) : ?>
											<input type="text" name="ip_header" value="<?php echo esc_attr( (string) $settings['ip_header'] ); ?>" placeholder="X-Client-IP" class="<?php echo esc_attr( self::INPUT . ' rh:mt-2 rh:h-8 rh:font-mono rh:text-xs' ); ?>">
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				</li>
			</ul>
			<div class="rh:flex rh:justify-end rh:border-t rh:border-border rh:bg-background rh:px-5 rh:py-3">
				<button type="submit" class="<?php echo esc_attr( self::BUTTON_PRIMARY ); ?>"><?php esc_html_e( 'Save settings', 'redirhub-redirects' ); ?></button>
			</div>
		</form>

		<section class="rh-connection rh:overflow-hidden rh:rounded-xl rh:border rh:border-border rh:bg-card rh:shadow-card">
			<div class="rh:flex rh:flex-wrap rh:items-center rh:gap-3 rh:px-5 rh:py-4">
				<span class="rh:flex rh:size-9 rh:items-center rh:justify-center rh:rounded-lg rh:bg-secondary rh:text-secondary-foreground"><?php echo Icons::svg( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div class="rh:min-w-0 rh:flex-1">
					<h2 class="rh:text-sm rh:font-semibold rh:text-foreground"><?php esc_html_e( 'Connection', 'redirhub-redirects' ); ?></h2>
					<p class="rh:mt-0.5 rh:flex rh:flex-wrap rh:gap-x-3 rh:text-[13px] rh:text-muted-foreground">
						<span><?php esc_html_e( 'Rules address', 'redirhub-redirects' ); ?> <code class="rh:text-secondary-foreground"><?php echo esc_html( Page::mask_url( Settings::rules_url() ) ); ?></code></span>
						<span><?php esc_html_e( 'Integration key', 'redirhub-redirects' ); ?> <code class="rh:text-secondary-foreground"><?php echo '' === Settings::key() ? esc_html__( 'none', 'redirhub-redirects' ) : esc_html( Page::mask_key( Settings::key() ) ); ?></code></span>
					</p>
				</div>
				<button type="button" class="<?php echo esc_attr( self::BUTTON_SECONDARY ); ?>" data-change-toggle aria-expanded="<?php echo '' === $rules_url ? 'false' : 'true'; ?>"><?php esc_html_e( 'Change', 'redirhub-redirects' ); ?></button>
			</div>
			<div data-change-form <?php echo '' === $rules_url ? 'hidden' : ''; ?>>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rh:flex rh:flex-col rh:gap-5 rh:border-t rh:border-border rh:px-5 rh:py-5">
				<input type="hidden" name="action" value="redirhub_connect">
				<input type="hidden" name="tab" value="settings">
				<?php wp_nonce_field( 'redirhub_connect' ); ?>
				<?php self::connection_fields( $rules_url, true ); ?>
				<div><button type="submit" data-busy="<?php esc_attr_e( 'Saving…', 'redirhub-redirects' ); ?>" class="<?php echo esc_attr( self::BUTTON_PRIMARY ); ?>"><?php esc_html_e( 'Save and update', 'redirhub-redirects' ); ?></button></div>
			</form>
			</div>
		</section>

		<section class="rh:flex rh:flex-wrap rh:items-center rh:gap-4 rh:rounded-xl rh:border rh:border-danger-edge rh:bg-card rh:px-5 rh:py-4 rh:shadow-card">
			<div class="rh:min-w-0 rh:flex-1">
				<h2 class="rh:text-sm rh:font-semibold rh:text-foreground"><?php esc_html_e( 'Disconnect this site', 'redirhub-redirects' ); ?></h2>
				<p class="rh:mt-0.5 rh:text-[13px] rh:text-muted-foreground"><?php esc_html_e( 'Your redirects stop at once and every page works as before. Your redirects stay in RedirHub.', 'redirhub-redirects' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rh-disconnect">
				<input type="hidden" name="action" value="redirhub_disconnect">
				<?php wp_nonce_field( 'redirhub_disconnect' ); ?>
				<button type="submit" data-confirm class="rh:inline-flex rh:h-9 rh:items-center rh:gap-2 rh:rounded-lg rh:border rh:border-danger-edge rh:bg-card rh:px-3.5 rh:text-sm rh:font-medium rh:text-danger rh:hover:bg-danger-soft rh:cursor-pointer"><?php echo Icons::svg( 'unplug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Disconnect', 'redirhub-redirects' ); ?></button>
			</form>
		</section>
		<p class="rh:text-center rh:text-xs rh:text-muted-foreground">
			<?php
			/* translators: %s: plugin version. */
			printf( esc_html__( 'RedirHub Redirects %s', 'redirhub-redirects' ), esc_html( REDIRHUB_REDIRECTS_VERSION ) );
			?>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------ components */

	/** The dashboard's primary button. */
	const BUTTON_PRIMARY = 'rh:inline-flex rh:h-9 rh:items-center rh:justify-center rh:gap-2 rh:rounded-lg rh:border-0 rh:bg-primary rh:px-4 rh:text-sm rh:font-semibold rh:text-white rh:shadow-card rh:transition-colors rh:hover:bg-primary-hover rh:hover:text-white rh:cursor-pointer';

	/** The dashboard's secondary button. */
	const BUTTON_SECONDARY = 'rh:inline-flex rh:h-9 rh:items-center rh:justify-center rh:gap-2 rh:whitespace-nowrap rh:rounded-lg rh:border rh:border-border rh:bg-card rh:px-3.5 rh:text-sm rh:font-medium rh:text-secondary-foreground rh:shadow-card rh:transition-colors rh:hover:bg-background rh:hover:text-foreground rh:cursor-pointer';

	/** A text field. */
	const INPUT = 'rh:mt-1.5 rh:block rh:h-10 rh:w-full rh:rounded-lg rh:border rh:border-border rh:bg-card rh:px-3 rh:text-sm rh:text-foreground rh:shadow-card rh:placeholder:text-muted-foreground rh:focus:border-primary rh:focus:ring-3 rh:focus:ring-accent';

	/**
	 * The status badge of the header (`.rh-pill`, `data-status`).
	 *
	 * @param string $level Status::level().
	 */
	private static function status_badge( string $level ): string {
		$map = array(
			Status::OFF     => array( 'neutral', __( 'Not connected', 'redirhub-redirects' ) ),
			Status::FAILING => array( 'danger', __( 'Redirects can’t update', 'redirhub-redirects' ) ),
			Status::STALE   => array( 'danger', __( 'Updates stopped', 'redirhub-redirects' ) ),
			Status::WAITING => array( 'warning', __( 'Almost done: verify your domain', 'redirhub-redirects' ) ),
			Status::OK      => array( 'success', __( 'Live', 'redirhub-redirects' ) ),
		);

		return (string) preg_replace( '/^<span class="/', '<span data-status="' . esc_attr( $level ) . '" class="rh-pill ', self::badge( $map[ $level ][0], $map[ $level ][1] ), 1 );
	}

	/**
	 * A status badge with a dot, as in the dashboard.
	 *
	 * @param string $tone  `success`, `warning`, `danger`, `info` or `neutral`.
	 * @param string $label The text.
	 */
	public static function badge( string $tone, string $label ): string {
		$tones = array(
			'success' => 'rh:border-success-edge rh:bg-success-soft rh:text-success',
			'warning' => 'rh:border-warning-edge rh:bg-warning-soft rh:text-warning',
			'danger'  => 'rh:border-danger-edge rh:bg-danger-soft rh:text-danger',
			'info'    => 'rh:border-info-edge rh:bg-info-soft rh:text-accent-foreground',
			'neutral' => 'rh:border-border rh:bg-secondary rh:text-secondary-foreground',
		);

		return '<span class="' . esc_attr( 'rh:inline-flex rh:items-center rh:gap-1.5 rh:whitespace-nowrap rh:rounded-full rh:border rh:px-2 rh:py-0.5 rh:text-xs rh:font-medium ' . ( $tones[ $tone ] ?? $tones['neutral'] ) ) . '"><span class="rh:size-1.5 rh:rounded-full rh:bg-current"></span>' . esc_html( $label ) . '</span>';
	}

	/**
	 * A message, as the dashboard shows one.
	 *
	 * @param string $tone        `success`, `error`/`danger`, `warning` or `info`.
	 * @param string $message     The text.
	 * @param bool   $dismissible Whether it has a close button.
	 */
	private static function alert( string $tone, string $message, bool $dismissible = false ): void {
		$tone   = 'error' === $tone ? 'danger' : $tone;
		$styles = array(
			'success' => array( 'circle-check', 'rh:border-success-edge rh:bg-success-soft', 'rh:text-success' ),
			'warning' => array( 'triangle-alert', 'rh:border-warning-edge rh:bg-warning-soft', 'rh:text-warning' ),
			'danger'  => array( 'circle-alert', 'rh:border-danger-edge rh:bg-danger-soft', 'rh:text-danger' ),
			'info'    => array( 'info', 'rh:border-info-edge rh:bg-info-soft', 'rh:text-accent-foreground' ),
		);
		list( $icon, $box, $ink ) = $styles[ $tone ] ?? $styles['info'];
		printf(
			'<div role="%1$s" data-alert="%2$s" class="%3$s">%4$s<p class="rh:min-w-0 rh:flex-1 rh:text-sm rh:text-foreground">%5$s</p>%6$s</div>',
			'danger' === $tone ? 'alert' : 'status',
			esc_attr( 'danger' === $tone ? 'error' : $tone ),
			esc_attr( 'rh:flex rh:items-start rh:gap-3 rh:rounded-xl rh:border rh:px-4 rh:py-3 ' . $box ),
			Icons::svg( $icon, 'rh:mt-0.5 rh:size-4 ' . $ink ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $message ),
			$dismissible ? '<button type="button" data-dismiss class="rh:-m-1 rh:rounded-md rh:border-0 rh:bg-transparent rh:p-1 rh:text-muted-foreground rh:hover:text-foreground rh:cursor-pointer" aria-label="' . esc_attr__( 'Dismiss', 'redirhub-redirects' ) . '">' . Icons::svg( 'x' ) . '</button>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * A row of the status list.
	 *
	 * @param string $card   Its `data-card` (rules, check, reports, test).
	 * @param string $icon   An icon of Icons.
	 * @param string $title  The title, as escaped HTML.
	 * @param string $detail The text (escaped here).
	 * @param string $aside  The badges and buttons on the right, as escaped HTML.
	 */
	private static function row( string $card, string $icon, string $title, string $detail, string $aside ): void {
		printf(
			'<li data-card="%1$s" class="rh:flex rh:flex-col rh:gap-3 rh:px-5 rh:py-4 rh:sm:flex-row rh:sm:items-center"><span class="rh:flex rh:min-w-0 rh:flex-1 rh:gap-3"><span class="rh:flex rh:size-9 rh:flex-none rh:items-center rh:justify-center rh:rounded-lg rh:bg-secondary rh:text-secondary-foreground">%2$s</span><span class="rh:min-w-0"><span class="rh:block rh:text-sm rh:font-semibold rh:text-foreground">%3$s</span><span class="rh:mt-0.5 rh:block rh:text-[13px] rh:leading-relaxed rh:text-muted-foreground">%4$s</span></span></span><span class="rh:flex rh:flex-none rh:flex-wrap rh:items-center rh:gap-2 rh:pl-12 rh:sm:pl-0">%5$s</span></li>',
			esc_attr( $card ),
			Icons::svg( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_kses( $title, array( 'span' => array( 'data-rules-count' => true ) ) ),
			esc_html( $detail ),
			$aside // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * A one-button form for an action.
	 *
	 * @param string $action The action.
	 * @param string $label  The button's label.
	 * @param string $icon   An icon of Icons.
	 */
	private static function action_button( string $action, string $label, string $icon ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rh:m-0">'
			. '<input type="hidden" name="action" value="redirhub_' . esc_attr( $action ) . '">'
			. wp_nonce_field( 'redirhub_' . $action, '_wpnonce', true, false )
			. '<button type="submit" data-busy="' . esc_attr__( 'Working…', 'redirhub-redirects' ) . '" class="' . esc_attr( self::BUTTON_SECONDARY . ' rh-spin-on-busy' ) . '">' . Icons::svg( $icon, 'rh:size-4 rh:text-muted-foreground' ) . esc_html( $label ) . '</button></form>';
	}

	/**
	 * A link that looks like a secondary button.
	 *
	 * @param string $url      Where it goes.
	 * @param string $label    The text.
	 * @param bool   $external Whether it opens RedirHub in a new tab.
	 */
	private static function link_button( string $url, string $label, bool $external = false ): string {
		return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( self::BUTTON_SECONDARY ) . '"' . ( $external ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $label ) . ( $external ? Icons::svg( 'arrow-up-right', 'rh:size-4 rh:text-muted-foreground' ) : Icons::svg( 'chevron-right', 'rh:size-4 rh:text-muted-foreground' ) ) . '</a>';
	}
}
