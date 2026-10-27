<?php
/**
 * Admin menu and page shell.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Admin;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Modules\WhiteLabel\WhiteLabel;

defined( 'ABSPATH' ) || exit;

class Menu {

	use Singleton;

	/**
	 * Hook up.
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Register the top-level page.
	 */
	public function register() {
		$branding = WhiteLabel::branding();

		$menu_icon = 'dashicons-shield-alt';
		$icon_file = defined( 'OMNINOX_SITE_TOOLKIT_PATH' ) ? OMNINOX_SITE_TOOLKIT_PATH . 'assets/images/logo-icon.svg' : '';
		if ( file_exists( $icon_file ) ) {
			$svg_content = @file_get_contents( $icon_file );
			if ( $svg_content ) {
				$menu_icon = 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
			}
		}

		add_menu_page(
			$branding['plugin_name'],
			$branding['plugin_name'],
			'manage_options',
			'omninox-site-toolkit',
			array( $this, 'render' ),
			$menu_icon,
			58
		);

		$subpages = array(
			''              => __( 'Dashboard', 'omninox-site-toolkit'),
			'health'        => __( 'Health', 'omninox-site-toolkit'),
			'performance'   => __( 'Performance', 'omninox-site-toolkit'),
			'security'      => __( 'Security', 'omninox-site-toolkit'),
			'database'      => __( 'Database', 'omninox-site-toolkit'),
			'media'         => __( 'Media', 'omninox-site-toolkit'),
			'seo'           => __( 'SEO', 'omninox-site-toolkit'),
			'search'        => __( 'Search', 'omninox-site-toolkit'),
			'accessibility' => __( 'Accessibility', 'omninox-site-toolkit'),
			'errors'        => __( 'Error Log', 'omninox-site-toolkit'),
			'redirects'     => __( 'Redirects & 404s', 'omninox-site-toolkit'),
			
			'analytics'     => __( 'Analytics', 'omninox-site-toolkit'),
			'backup'        => __( 'Backups', 'omninox-site-toolkit'),
			'developer'     => __( 'Developer Tools', 'omninox-site-toolkit'),
			'files'         => __( 'File Manager', 'omninox-site-toolkit'),
			'reports'       => __( 'Reports', 'omninox-site-toolkit'),
			'settings'      => __( 'Settings', 'omninox-site-toolkit'),
		);

		foreach ( $subpages as $slug => $label ) {
			add_submenu_page(
				'omninox-site-toolkit',
				$label,
				$label,
				'manage_options',
				$slug ? 'omninox-site-toolkit#/' . $slug : 'omninox-site-toolkit',
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Send the user to the dashboard right after activation.
	 */
	public function maybe_redirect() {
		if ( ! get_transient( 'omninox_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'omninox_activation_redirect' );

		$has_multi = isset( $_GET['activate-multi'] ) ? sanitize_text_field( wp_unslash( $_GET['activate-multi'] ) ) : '';

		if ( '' === $has_multi && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=omninox-site-toolkit' ) );
			exit;
		}
	}

	/**
	 * Render the app mount point.
	 */
	public function render() {
		$branding = WhiteLabel::branding();

		include OMNINOX_SITE_TOOLKIT_PATH . 'templates/admin.php';
	}
}
