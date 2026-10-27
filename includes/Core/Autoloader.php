<?php
/**
 * PSR-4 style autoloader for the OmninoxSiteToolkit namespace.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Core;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	const PREFIX = 'OmninoxSiteToolkit\\';

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Map a class name to a file inside /includes.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = OMNINOX_SITE_TOOLKIT_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
