<?php
/**
 * Feature Gate — defines feature tiers (Free vs Pro) and media conversion quotas.
 *
 * The Free plugin provides rich diagnostics and 100 free image conversions.
 * The Pro add-on hooks in to unlock unlimited conversions and advanced capabilities.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\License;

defined( 'ABSPATH' ) || exit;

class FeatureGate {

	const TIER_FREE = 'free';
	const TIER_PRO  = 'pro';

	/**
	 * Option key holding the lifetime WebP conversion counter.
	 */
	const MEDIA_COUNTER_OPTION = 'omninox_media_conversions';

	/**
	 * Number of WebP conversions allowed in the Free plugin (100).
	 */
	const MEDIA_FREE_LIMIT = 100;

	/**
	 * Complete tier map: Free baseline vs Pro features.
	 *
	 * @return array
	 */
	private static function raw_map() {
		return array(
			'health'        => array(
				'*' => self::TIER_FREE,
			),
			'performance'   => array(
				'*'             => self::TIER_FREE,
				'minify_css'    => self::TIER_PRO,
				'minify_js'     => self::TIER_PRO,
				'lazy_load'     => self::TIER_PRO,
				'delay_runtime' => self::TIER_PRO,
				'critical_css'  => self::TIER_PRO,
			),
			'assets'        => array(
				'*' => self::TIER_PRO,
			),
			'database'      => array(
				'*'              => self::TIER_FREE,
				'search_replace' => self::TIER_PRO,
				'defragment'     => self::TIER_PRO,
				'auto_cleanup'   => self::TIER_PRO,
			),
			'media'         => array(
				'*'                          => self::TIER_FREE,
				'convert_webp'               => self::TIER_FREE,
				'bulk_convert_webp'          => self::TIER_FREE,
				'convert_avif'               => self::TIER_PRO,
				'bulk_convert_avif'          => self::TIER_PRO,
				'fill_alt_filenames'         => self::TIER_PRO,
				'fix_missing_alt'            => self::TIER_PRO,
				'restore_original'           => self::TIER_PRO,
				'restore_originals'          => self::TIER_PRO,
				'restore_replaced_originals' => self::TIER_PRO,
				'replace_original'           => self::TIER_PRO,
				'replace_originals_with_webp'=> self::TIER_PRO,
				'replace_originals_with_avif'=> self::TIER_PRO,
			),
			'seo'           => array(
				'*'             => self::TIER_FREE,
				'schema_markup' => self::TIER_PRO,
				'broken_links'  => self::TIER_PRO,
			),
			'search'        => array(
				'*'                => self::TIER_FREE,
				'live_search'      => self::TIER_PRO,
				'relevance_engine' => self::TIER_PRO,
			),
			'accessibility' => array(
				'*'                  => self::TIER_FREE,
				'fill_alt_filenames' => self::TIER_PRO,
				'bulk_alt_editor'    => self::TIER_PRO,
				'toolbar'            => self::TIER_PRO,
			),
			'security'      => array(
				'*'                      => self::TIER_FREE,
				'xmlrpc_block'           => self::TIER_PRO,
				'login_limit'            => self::TIER_PRO,
				'set_login_limits'       => self::TIER_PRO,
				'block_user_enumeration' => self::TIER_PRO,
				'unblock_ip'             => self::TIER_PRO,
				'clear_lockouts'         => self::TIER_PRO,
				'malware_scan'           => self::TIER_PRO,
				'block_ip'               => self::TIER_PRO,
			),
			'conflicts'     => array(
				'*' => self::TIER_FREE,
			),
			'analytics'     => array(
				'*'               => self::TIER_FREE,
				'history_90_days' => self::TIER_PRO,
				'forecast'        => self::TIER_PRO,
			),
			'errors'        => array(
				'*'            => self::TIER_FREE,
				'error_alerts' => self::TIER_PRO,
			),
			'redirects'     => array(
				'*'                 => self::TIER_FREE,
				'regex_redirect'    => self::TIER_PRO,
				'auto_404_redirect' => self::TIER_PRO,
			),
			'developer'     => array(
				'*'                 => self::TIER_FREE,
				'test_rest'         => self::TIER_PRO,
				'run_cron_event'    => self::TIER_PRO,
				'clear_stuck_crons' => self::TIER_PRO,
				'roles'             => self::TIER_PRO,
			),
			'backup'        => array(
				'*' => self::TIER_PRO,
			),
			'reports'       => array(
				'*'   => self::TIER_FREE,
				'pdf' => self::TIER_PRO,
			),
			'white_label'   => array(
				'*' => self::TIER_PRO,
			),
		);
	}

	/**
	 * The full tier map.
	 *
	 * @return array
	 */
	public static function map() {
		return (array) apply_filters( 'omninox_feature_tier_map', apply_filters( 'omninox_feature_tier_map', self::raw_map() ) );
	}

	/**
	 * Resolve the tier for a module (and optional action).
	 *
	 * @param string      $module_id Module id.
	 * @param string|null $action_id Action id, or null for the module itself.
	 * @return string 'free' or 'pro'.
	 */
	public static function tier( $module_id, $action_id = null ) {
		$map = self::map();

		if ( ! isset( $map[ $module_id ] ) ) {
			return self::TIER_FREE;
		}

		$module_rules = $map[ $module_id ];

		if ( null !== $action_id && isset( $module_rules[ $action_id ] ) ) {
			return $module_rules[ $action_id ];
		}

		if ( isset( $module_rules['*'] ) ) {
			return $module_rules['*'];
		}

		return self::TIER_FREE;
	}

	/**
	 * Whether an entire module is Pro-only.
	 *
	 * @param string $module_id Module id.
	 * @return bool
	 */
	public static function is_module_pro( $module_id ) {
		$map = self::map();
		if ( ! isset( $map[ $module_id ] ) ) {
			return false;
		}

		return ( isset( $map[ $module_id ]['*'] ) && self::TIER_PRO === $map[ $module_id ]['*'] );
	}

	/**
	 * Check if Pro companion plugin is installed and licensed.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return defined( 'OMNINOX_SITE_TOOLKIT_PRO_VERSION' ) && LicenseManager::instance()->is_premium();
	}

	/**
	 * Check if a module or action can be used under current license.
	 *
	 * @param string      $module_id Module id.
	 * @param string|null $action_id Action id.
	 * @return bool
	 */
	public static function can_use( $module_id, $action_id = null ) {
		$tier = self::tier( $module_id, $action_id );

		if ( self::TIER_FREE === $tier ) {
			if ( 'media' === $module_id && 'convert_webp' === $action_id ) {
				return self::media_can_convert();
			}
			return true;
		}

		return self::is_pro_active();
	}

	/* --------------------------------------------------------------- Media */

	/**
	 * Number of WebP media conversions used so far.
	 *
	 * @return int
	 */
	public static function media_conversions_used() {
		return (int) get_option( self::MEDIA_COUNTER_OPTION, 0 );
	}

	/**
	 * Remaining conversions available (-1 for unlimited in Pro).
	 *
	 * @return int
	 */
	public static function media_conversions_remaining() {
		if ( LicenseManager::instance()->is_premium() ) {
			return -1;
		}

		$used = self::media_conversions_used();
		return max( 0, self::MEDIA_FREE_LIMIT - $used );
	}

	/**
	 * Whether a new media conversion is allowed.
	 *
	 * @return bool
	 */
	public static function media_can_convert() {
		if ( LicenseManager::instance()->is_premium() ) {
			return true;
		}

		return self::media_conversions_used() < self::MEDIA_FREE_LIMIT;
	}

	/**
	 * Increment conversion counter.
	 *
	 * @param int $count Number of conversions.
	 * @return int
	 */
	public static function increment_media_counter( $count = 1 ) {
		$used = self::media_conversions_used() + (int) $count;
		update_option( self::MEDIA_COUNTER_OPTION, $used );
		return $used;
	}
}
