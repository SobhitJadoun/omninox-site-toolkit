<?php
/**
 * Contract every module must satisfy.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Interfaces;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	/**
	 * Unique machine id, e.g. "health".
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Register hooks. Called once during plugins_loaded.
	 */
	public function register();

	/**
	 * Run the module scan.
	 *
	 * @param array $args Optional arguments.
	 * @return array Normalised scan result.
	 */
	public function scan( array $args = array() );

	/**
	 * Apply a named fix.
	 *
	 * @param string $action Action id.
	 * @param array  $params Parameters.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() );
}
