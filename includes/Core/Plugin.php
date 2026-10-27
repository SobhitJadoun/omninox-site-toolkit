<?php
/**
 * Plugin container.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Core;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Admin\Menu;
use OmninoxSiteToolkit\API\RestController;
use OmninoxSiteToolkit\Scheduler\Scheduler;
use OmninoxSiteToolkit\License\LicenseManager;
use OmninoxSiteToolkit\Installer\Migrator;
use OmninoxSiteToolkit\Notifications\Mailer;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	use Singleton;

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private $modules;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Bootstrap everything.
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Migrator::maybe_upgrade();

		$this->settings = Settings::instance();
		$this->modules  = ModuleRegistry::instance();
		$this->modules->discover();

		// Before anything else that might send: scans, tickets and cron all
		// route through wp_mail and need SMTP in place first.
		Mailer::instance();

		LicenseManager::instance()->boot();
		Scheduler::instance();
		RestController::instance();
		\OmninoxSiteToolkit\Services\CompatibilityFixer::register();

		if ( is_admin() ) {
			Menu::instance();
			Assets::instance();
		}

		add_filter( 'plugin_action_links_' . OMNINOX_SITE_TOOLKIT_BASENAME, array( $this, 'action_links' ) );

		do_action( 'omninox_loaded', $this );
	}

	/**
	 * Load translation textdomain on init.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'omninox-site-toolkit', false, dirname( OMNINOX_SITE_TOOLKIT_BASENAME ) . '/languages' );
	}

	/**
	 * Module registry accessor.
	 *
	 * @return ModuleRegistry
	 */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Settings accessor.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Add a settings shortcut on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=omninox-site-toolkit' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dashboard', 'omninox-site-toolkit' ) . '</a>'
		);

		return $links;
	}
}
