<?php
/**
 * White-label branding for agencies.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\WhiteLabel;

use OmninoxSiteToolkit\License\LicenseManager;

defined( 'ABSPATH' ) || exit;

class WhiteLabel {

	const OPTION = 'omninox_white_label';

	/**
	 * Branding used by the admin UI and reports.
	 *
	 * @return array
	 */
	public static function branding() {
		$defaults = array(
			'plugin_name' => __( 'Omninox Site Toolkit', 'omninox-site-toolkit' ),
			'company'     => 'Net Innovix',
			'logo_url'    => defined( 'OMNINOX_SITE_TOOLKIT_URL' ) ? OMNINOX_SITE_TOOLKIT_URL . 'assets/images/logo.svg' : '',
			'accent'      => '#0f8f8a',
			'hide_credit' => false,
			'support_url' => '',
		);

		if ( ! LicenseManager::instance()->is_premium() ) {
			return $defaults;
		}

		$stored = (array) get_option( self::OPTION, array() );

		return array(
			'plugin_name' => sanitize_text_field( $stored['plugin_name'] ?? $defaults['plugin_name'] ),
			'company'     => sanitize_text_field( $stored['company'] ?? '' ),
			'logo_url'    => esc_url_raw( $stored['logo_url'] ?? '' ),
			'accent'      => sanitize_hex_color( $stored['accent'] ?? '' ) ?: $defaults['accent'],
			'hide_credit' => ! empty( $stored['hide_credit'] ),
			'support_url' => esc_url_raw( $stored['support_url'] ?? '' ),
		);
	}

	/**
	 * Save branding.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function save( array $input ) {
		if ( ! LicenseManager::instance()->is_premium() ) {
			return self::branding();
		}

		update_option(
			self::OPTION,
			array(
				'plugin_name' => sanitize_text_field( $input['plugin_name'] ?? '' ),
				'company'     => sanitize_text_field( $input['company'] ?? '' ),
				'logo_url'    => esc_url_raw( $input['logo_url'] ?? '' ),
				'accent'      => sanitize_hex_color( $input['accent'] ?? '' ),
				'hide_credit' => ! empty( $input['hide_credit'] ),
				'support_url' => esc_url_raw( $input['support_url'] ?? '' ),
			)
		);

		return self::branding();
	}

	/**
	 * Reset branding to plugin defaults.
	 *
	 * @return array
	 */
	public static function reset() {
		delete_option( self::OPTION );

		return self::branding();
	}
}

