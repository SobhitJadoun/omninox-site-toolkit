<?php
/**
 * Singleton trait.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Traits;

defined( 'ABSPATH' ) || exit;

trait Singleton {

	/**
	 * Instances keyed by class name.
	 *
	 * @var array
	 */
	protected static $instances = array();

	/**
	 * Get the shared instance.
	 *
	 * @return static
	 */
	public static function instance() {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			self::$instances[ $class ] = new static();

			if ( method_exists( self::$instances[ $class ], 'boot' ) ) {
				self::$instances[ $class ]->boot();
			}
		}

		return self::$instances[ $class ];
	}
}
