<?php
/**
 * Activation routine.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Installer;

use OmninoxSiteToolkit\Database\Schema;
use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Activator {

	/**
	 * Run on activation (network aware).
	 *
	 * @param bool $network_wide Whether activated network wide.
	 */
	public static function activate( $network_wide = false ) {
		global $wp_version;

		$min_php = defined( 'OMNINOX_SITE_TOOLKIT_MIN_PHP' ) ? OMNINOX_SITE_TOOLKIT_MIN_PHP : '7.2';
		$min_wp  = defined( 'OMNINOX_SITE_TOOLKIT_MIN_WP' ) ? OMNINOX_SITE_TOOLKIT_MIN_WP : '6.7';

		if ( version_compare( PHP_VERSION, $min_php, '<' ) ) {
			if ( function_exists( 'deactivate_plugins' ) && defined( 'OMNINOX_SITE_TOOLKIT_FILE' ) ) {
				deactivate_plugins( plugin_basename( OMNINOX_SITE_TOOLKIT_FILE ) );
			}
			wp_die(
				sprintf(
					/* translators: 1: Required PHP, 2: Current PHP */
					esc_html__( 'Omninox Site Toolkit requires PHP %1$s or higher. Your server is running PHP %2$s.', 'omninox-site-toolkit' ),
					esc_html( $min_php ),
					esc_html( PHP_VERSION )
				),
				esc_html__( 'Plugin Activation Error', 'omninox-site-toolkit' ),
				array( 'back_link' => true )
			);
		}

		if ( version_compare( $wp_version, $min_wp, '<' ) ) {
			if ( function_exists( 'deactivate_plugins' ) && defined( 'OMNINOX_SITE_TOOLKIT_FILE' ) ) {
				deactivate_plugins( plugin_basename( OMNINOX_SITE_TOOLKIT_FILE ) );
			}
			wp_die(
				sprintf(
					/* translators: 1: Required WP, 2: Current WP */
					esc_html__( 'Omninox Site Toolkit requires WordPress %1$s or higher. Your site is running WordPress %2$s.', 'omninox-site-toolkit' ),
					esc_html( $min_wp ),
					esc_html( $wp_version )
				),
				esc_html__( 'Plugin Activation Error', 'omninox-site-toolkit' ),
				array( 'back_link' => true )
			);
		}

		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 500 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install_site();
				restore_current_blog();
			}

			return;
		}

		self::install_site();
	}

	/**
	 * Per-site install steps.
	 */
	private static function install_site() {
		Schema::install();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		self::add_capabilities();

		if ( ! wp_next_scheduled( 'omninox_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'omninox_daily_maintenance' );
		}

		set_transient( 'omninox_activation_redirect', 1, 60 );

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Grant the management capability to administrators.
	 */
	private static function add_capabilities() {
		$role = get_role( 'administrator' );

		if ( $role && ! $role->has_cap( 'omninox_manage' ) ) {
			$role->add_cap( 'omninox_manage' );
		}
	}
}
