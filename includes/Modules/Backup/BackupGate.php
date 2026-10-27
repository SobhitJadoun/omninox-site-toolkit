<?php
/**
 * Backup Level 1 / Pro Gate.
 *
 * Exposes a lightweight entry point in the Free plugin informing the user
 * that full Backup & Restore functionality is available in OmniNox Pro.
 * No backup or restore engine code is executed by the Free plugin.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Backup;

use OmninoxSiteToolkit\Abstracts\AbstractModule;

defined( 'ABSPATH' ) || exit;

class BackupGate extends AbstractModule {

	protected $id      = 'backup';
	protected $icon    = 'backup';
	protected $premium = true;

	public function get_label() {
		return __( 'Backup & Restore', 'omninox-site-toolkit' );
	}

	/**
	 * Register hooks (none for Free gate).
	 */
	public function register() {
		// Free gate does not register background crons or backup hooks.
	}

	/**
	 * Module is available for display.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * No executable backup actions in Free plugin.
	 * Full functionality requires Omninox Pro.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array();
	}

	/**
	 * Informational scan result for the module list.
	 *
	 * @param array $args Optional arguments.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		return array(
			'score'  => 100,
			'status' => 'pass',
			'issues' => array(),
			'meta'   => array(
				'notice' => __( 'Full website backup, migration, restore, rollback, database migration and advanced recovery are available in OmniNox Pro.', 'omninox-site-toolkit' ),
			),
		);
	}

	/**
	 * Execution guard: Free gate rejects any backup/restore execution attempt.
	 *
	 * @param string $action Action name.
	 * @param array  $params Parameters.
	 * @return \WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		return new \WP_Error(
			'omninox_pro_required',
			__( 'Full website backup, migration, restore, rollback, database migration and advanced recovery are available in OmniNox Pro.', 'omninox-site-toolkit' ),
			array( 'status' => 403 )
		);
	}
}
