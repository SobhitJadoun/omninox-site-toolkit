<?php
/**
 * Admin asset loader.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Core;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\License\LicenseManager;
use OmninoxSiteToolkit\License\FeatureGate;
use OmninoxSiteToolkit\Modules\WhiteLabel\WhiteLabel;

defined( 'ABSPATH' ) || exit;

class Assets {

	use Singleton;

	/**
	 * Hook up.
	 */
	public function boot() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_omninox_refresh_nonce', array( $this, 'ajax_refresh_nonce' ) );
	}

	/**
	 * Refresh the REST API nonce for authenticated administrators.
	 */
	public function ajax_refresh_nonce() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'omninox-site-toolkit' ) ), 403 );
		}

		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Enqueue only on our screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'omninox-site-toolkit') && false === strpos( $hook, 'omninox-site-toolkit' ) ) {
			return;
		}

		$build_asset = OMNINOX_SITE_TOOLKIT_PATH . 'build/index.asset.php';

		if ( file_exists( $build_asset ) ) {
			$asset = require $build_asset;

			wp_enqueue_script( 'omninox-site-toolkit-app', OMNINOX_SITE_TOOLKIT_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
			wp_enqueue_style( 'omninox-site-toolkit-app', OMNINOX_SITE_TOOLKIT_URL . 'build/index.css', array( 'wp-components' ), $asset['version'] );
		} else {
			// No-build fallback UI, ships working out of the box.
			$js_ver  = file_exists( OMNINOX_SITE_TOOLKIT_PATH . 'assets/js/admin.js' ) ? filemtime( OMNINOX_SITE_TOOLKIT_PATH . 'assets/js/admin.js' ) : OMNINOX_SITE_TOOLKIT_VERSION;
			$css_ver = file_exists( OMNINOX_SITE_TOOLKIT_PATH . 'assets/css/admin.css' ) ? filemtime( OMNINOX_SITE_TOOLKIT_PATH . 'assets/css/admin.css' ) : OMNINOX_SITE_TOOLKIT_VERSION;

			wp_enqueue_script( 'omninox-site-toolkit-app', OMNINOX_SITE_TOOLKIT_URL . 'assets/js/admin.js', array( 'wp-i18n' ), $js_ver, true );
			wp_enqueue_style( 'omninox-site-toolkit-app', OMNINOX_SITE_TOOLKIT_URL . 'assets/css/admin.css', array(), $css_ver );
		}

		$rest_root = rest_url( 'omninox-site-toolkit/v1' );
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$parsed = wp_parse_url( $rest_root );
			$scheme = is_ssl() ? 'https' : ( isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'http' );
			$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
			$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
			$rest_root = $scheme . '://' . $host . $path . $query;
		}

		$config_data = array(
			'root'       => esc_url_raw( $rest_root ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'adminUrl'   => admin_url(),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'pluginUrl'  => OMNINOX_SITE_TOOLKIT_URL,
			'siteName'   => get_bloginfo( 'name' ),
			'theme'      => Settings::instance()->get( 'theme', 'auto' ),
			'modules'    => ModuleRegistry::instance()->describe(),
			'license'    => LicenseManager::instance()->summary(),
			'gateMap'    => FeatureGate::map(),
			'upgradeUrl' => 'https://netinnovix.com/plugins/omninox-site-toolkit/buy',
			'plans'      => LicenseManager::instance()->get_pricing_plans(),
			'branding'       => WhiteLabel::branding(),
			'canManage'      => current_user_can( 'manage_options' ),
			'version'        => OMNINOX_SITE_TOOLKIT_VERSION,
			'isProActive'    => FeatureGate::is_pro_active(),
			'isProInstalled' => defined( 'OMNINOX_SITE_TOOLKIT_PRO_VERSION' )
				|| file_exists( \OmninoxSiteToolkit\Helpers\Utils::get_plugins_dir() . '/OmniNox-pro/omninox-site-toolkit-pro.php' )
				|| file_exists( \OmninoxSiteToolkit\Helpers\Utils::get_plugins_dir() . '/omninox-site-toolkit-pro/omninox-site-toolkit-pro.php' ),
			'activateProUrl' => wp_nonce_url(
				admin_url(
					'plugins.php?action=activate&plugin=' . (
						file_exists( \OmninoxSiteToolkit\Helpers\Utils::get_plugins_dir() . '/OmniNox-pro/omninox-site-toolkit-pro.php' )
							? 'OmniNox-pro/omninox-site-toolkit-pro.php'
							: 'omninox-site-toolkit-pro/omninox-site-toolkit-pro.php'
					)
				),
				'activate-plugin_' . (
					file_exists( \OmninoxSiteToolkit\Helpers\Utils::get_plugins_dir() . '/OmniNox-pro/omninox-site-toolkit-pro.php' )
						? 'OmniNox-pro/omninox-site-toolkit-pro.php'
						: 'omninox-site-toolkit-pro/omninox-site-toolkit-pro.php'
				)
			),
		);

		wp_localize_script( 'omninox-site-toolkit-app', 'OmninoxSiteToolkit', $config_data );

		wp_set_script_translations( 'omninox-site-toolkit-app', 'omninox-site-toolkit', OMNINOX_SITE_TOOLKIT_PATH . 'languages' );
	}
}
