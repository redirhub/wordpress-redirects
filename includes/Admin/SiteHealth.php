<?php
/**
 * Tools → Site Health.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Admin;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Settings;
use RedirHub\Redirects\State;
use RedirHub\Redirects\Store;

/**
 * A Site Health test of the connection, and the plugin's facts in Site Health's Info tab (which
 * a site owner copies into a support request).
 */
final class SiteHealth {

	/**
	 * Adds the test.
	 *
	 * @param array<string, mixed> $tests Site Health's tests.
	 * @return array<string, mixed>
	 */
	public static function tests( $tests ) {
		if ( is_array( $tests ) && '' !== Settings::rules_url() ) {
			$tests['direct']['redirhub_redirects'] = array(
				'label' => __( 'RedirHub redirects', 'redirhub-redirects' ),
				'test'  => array( self::class, 'test' ),
			);
		}
		add_filter( 'debug_information', array( self::class, 'info' ) );

		return $tests;
	}

	/**
	 * The test's result.
	 *
	 * @return array<string, mixed>
	 */
	public static function test(): array {
		$level  = Status::level();
		$state  = State::get();
		$result = array(
			'label'       => __( 'Your redirects are up to date', 'redirhub-redirects' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Redirects', 'redirhub-redirects' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Old and short links on this site land where you set them in RedirHub.', 'redirhub-redirects' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Open RedirHub Redirects', 'redirhub-redirects' ) . '</a></p>',
			'test'        => 'redirhub_redirects',
		);
		if ( Status::FAILING === $level || Status::STALE === $level ) {
			$result['status']      = 'critical';
			$result['label']       = Status::FAILING === $level ? __( 'Your redirects can’t be updated', 'redirhub-redirects' ) : __( 'Your redirects may be out of date', 'redirhub-redirects' );
			$result['description'] = '<p>' . esc_html( Status::error_text( (string) $state['error'] ) ) . '</p>';
		} elseif ( Status::WAITING === $level ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Your domain is not verified yet', 'redirhub-redirects' );
			$result['description'] = '<p>' . esc_html__( 'Press “Check now” on your domain in the RedirHub dashboard to finish connecting it.', 'redirhub-redirects' ) . '</p>';
		}

		return $result;
	}

	/**
	 * The plugin's facts in Site Health → Info.
	 *
	 * @param array<string, mixed> $info Site Health's debug information.
	 * @return array<string, mixed>
	 */
	public static function info( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		$state = State::get();
		$rules = Store::load();

		$info['redirhub-redirects'] = array(
			'label'  => __( 'RedirHub Redirects', 'redirhub-redirects' ),
			'fields' => array(
				'version'    => array(
					'label' => __( 'Version', 'redirhub-redirects' ),
					'value' => REDIRHUB_REDIRECTS_VERSION,
				),
				'status'     => array(
					'label' => __( 'Status', 'redirhub-redirects' ),
					'value' => Status::level(),
				),
				'rules_url'  => array(
					'label'   => __( 'Rules address', 'redirhub-redirects' ),
					'value'   => Page::mask_url( Settings::rules_url() ),
					'private' => true,
				),
				'hosts'      => array(
					'label' => __( 'Hostnames', 'redirhub-redirects' ),
					'value' => null === $rules ? '-' : implode( ', ', $rules->hostnames() ),
				),
				'rules'      => array(
					'label' => __( 'Rules', 'redirhub-redirects' ),
					'value' => null === $rules ? 0 : $rules->count(),
				),
				'etag'       => array(
					'label' => 'ETag',
					'value' => null === $rules ? '-' : $rules->etag(),
				),
				'fetched_at' => array(
					'label' => __( 'Checked for changes', 'redirhub-redirects' ),
					'value' => $state['fetched_at'] ? gmdate( 'c', (int) $state['fetched_at'] ) : '-',
				),
				'error'      => array(
					'label' => __( 'Last error', 'redirhub-redirects' ),
					'value' => '' === $state['error'] ? '-' : (string) $state['error'],
				),
				'store'      => array(
					'label' => __( 'Copy kept in', 'redirhub-redirects' ),
					'value' => Store::IN_OPTION === $state['file'] ? 'database' : 'file',
				),
				'reports'    => array(
					'label' => __( 'Reports', 'redirhub-redirects' ),
					'value' => Settings::reports() ? 'on' : 'off',
				),
			),
		);

		return $info;
	}
}
