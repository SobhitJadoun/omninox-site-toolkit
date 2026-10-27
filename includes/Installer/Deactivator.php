<?php
/**
 * Deactivation routine. Data is preserved.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Installer;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * Clear scheduled work.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'omninox_scheduled_scan' );
		wp_clear_scheduled_hook( 'omninox_daily_maintenance' );
		wp_clear_scheduled_hook( 'omninox_daily_license_revalidate' );
		wp_clear_scheduled_hook( 'omninox_error_monitor' );
		wp_clear_scheduled_hook( 'omninox_chat_expire' );
		wp_clear_scheduled_hook( 'omninox_chat_poll_mailbox' );
		delete_transient( 'omninox_front_html' );
	}
}
