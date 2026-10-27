<?php
/**
 * Plugin Name: Omninox Site Toolkit
 * Plugin URI: https://netinnovix.com/plugins/omninox-site-toolkit
 * Description: All-in-one performance, backup, security, SEO, and database optimization tools for WordPress maintenance and diagnostics.
 * Version: 1.1.1
 * Requires at least: 6.7
 * Requires PHP: 7.2
 * Author: Net Innovix
 * Author URI: https://netinnovix.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: omninox-site-toolkit
 * Domain Path: /languages
 *
 * @package OmninoxSiteToolkit
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'omninox_is_valid_utf8' ) ) {
	/**
	 * Polyfill for valid utf8 checking across WordPress core versions.
	 *
	 * @param string $text Text to check.
	 * @return bool
	 */
	function omninox_is_valid_utf8( $text ) {
		if ( '' === (string) $text ) {
			return true;
		}
		if ( function_exists( 'mb_check_encoding' ) ) {
			return mb_check_encoding( $text, 'UTF-8' );
		}
		return (bool) preg_match( '//u', $text );
	}
}

// Plugin constants.
defined( 'OMNINOX_SITE_TOOLKIT_VERSION' ) || define( 'OMNINOX_SITE_TOOLKIT_VERSION', '1.1.1' );
defined( 'OMNINOX_SITE_TOOLKIT_FILE' ) || define( 'OMNINOX_SITE_TOOLKIT_FILE', __FILE__ );
defined( 'OMNINOX_SITE_TOOLKIT_PATH' ) || define( 'OMNINOX_SITE_TOOLKIT_PATH', plugin_dir_path( __FILE__ ) );
defined( 'OMNINOX_SITE_TOOLKIT_URL' ) || define( 'OMNINOX_SITE_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );
defined( 'OMNINOX_SITE_TOOLKIT_BASENAME' ) || define( 'OMNINOX_SITE_TOOLKIT_BASENAME', plugin_basename( __FILE__ ) );
defined( 'OMNINOX_PLUGIN_FILE' ) || define( 'OMNINOX_PLUGIN_FILE', __FILE__ );
defined( 'OMNINOX_PLUGIN_DIR' ) || define( 'OMNINOX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'OMNINOX_PLUGIN_URL' ) || define( 'OMNINOX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'OMNINOX_PLUGIN_BASENAME' ) || define( 'OMNINOX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
defined( 'OMNINOX_SITE_TOOLKIT_PLUGIN_DIR' ) || define( 'OMNINOX_SITE_TOOLKIT_PLUGIN_DIR', __DIR__ );
defined( 'OMNINOX_SITE_TOOLKIT_PLUGIN_URL' ) || define( 'OMNINOX_SITE_TOOLKIT_PLUGIN_URL', plugins_url( '', __FILE__ ) );
defined( 'OMNINOX_SITE_TOOLKIT_ADMIN_PAGE' ) || define( 'OMNINOX_SITE_TOOLKIT_ADMIN_PAGE', 'omninox-site-toolkit' );
defined( 'OMNINOX_SITE_TOOLKIT_MIN_PHP' ) || define( 'OMNINOX_SITE_TOOLKIT_MIN_PHP', '7.2' );
defined( 'OMNINOX_SITE_TOOLKIT_MIN_WP' ) || define( 'OMNINOX_SITE_TOOLKIT_MIN_WP', '6.7' );
defined( 'OMNINOX_SITE_TOOLKIT_DB_VERSION' ) || define( 'OMNINOX_SITE_TOOLKIT_DB_VERSION', '1.1.1' );

require_once OMNINOX_SITE_TOOLKIT_PATH . 'includes/Core/Autoloader.php';
\OmninoxSiteToolkit\Core\Autoloader::register();

register_activation_hook( __FILE__, array( '\OmninoxSiteToolkit\Installer\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\OmninoxSiteToolkit\Installer\Deactivator', 'deactivate' ) );

/**
 * Check minimum runtime requirements (PHP 7.2+, WordPress 6.7+).
 *
 * @return bool
 */
function omninox_check_runtime_requirements() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, OMNINOX_SITE_TOOLKIT_MIN_PHP, '<' ) ) {
		add_action( 'admin_notices', 'omninox_php_requirement_notice' );
		return false;
	}

	if ( version_compare( $wp_version, OMNINOX_SITE_TOOLKIT_MIN_WP, '<' ) ) {
		add_action( 'admin_notices', 'omninox_wp_requirement_notice' );
		return false;
	}

	return true;
}

/**
 * Admin notice for insufficient PHP version.
 */
function omninox_php_requirement_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Omninox Site Toolkit:', 'omninox-site-toolkit' ); ?></strong>
			<?php
			printf(
				/* translators: 1: Required PHP version, 2: Current PHP version */
				esc_html__( 'This plugin requires PHP %1$s or higher. Your server is running PHP %2$s. Please upgrade PHP to run Omninox.', 'omninox-site-toolkit' ),
				esc_html( OMNINOX_SITE_TOOLKIT_MIN_PHP ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Admin notice for insufficient WordPress version.
 */
function omninox_wp_requirement_notice() {
	global $wp_version;
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Omninox Site Toolkit:', 'omninox-site-toolkit' ); ?></strong>
			<?php
			printf(
				/* translators: 1: Required WordPress version, 2: Current WordPress version */
				esc_html__( 'This plugin requires WordPress %1$s or higher. Your site is running WordPress %2$s. Please update WordPress to run Omninox.', 'omninox-site-toolkit' ),
				esc_html( OMNINOX_SITE_TOOLKIT_MIN_WP ),
				esc_html( $wp_version )
			);
			?>
		</p>
	</div>
	<?php
}

if ( ! function_exists( 'omninox_site_toolkit' ) ) {
	/**
	 * Main plugin accessor.
	 *
	 * @return \OmninoxSiteToolkit\Core\Plugin|null
	 */
	function omninox_site_toolkit() {
		if ( ! omninox_check_runtime_requirements() ) {
			return null;
		}
		return \OmninoxSiteToolkit\Core\Plugin::instance();
	}
}

add_action( 'plugins_loaded', 'omninox_site_toolkit', 5 );
