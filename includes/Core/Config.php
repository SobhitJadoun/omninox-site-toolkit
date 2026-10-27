<?php
/**
 * Backend configuration.
 *
 * Everything here is infrastructure: mail credentials, the support inbox, the
 * AI provider. None of it is exposed in the admin UI, so nobody using the site
 * can change it by accident — edit this file, or override any value from
 * wp-config.php with the matching constant, which is the safer option because
 * a plugin update will not overwrite it.
 *
 *     define( 'OMNINOX_SMTP_PASS', 'abcd efgh ijkl mnop' );
 *
 * Constants always win over the defaults below.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Core;

defined( 'ABSPATH' ) || exit;

class Config {

	/**
	 * Hardcoded defaults. Override any of these with a constant of the same
	 * name in uppercase, prefixed OMNINOX_ — 'smtp_host' becomes OMNINOX_SMTP_HOST.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			/* --------------------------------------------------------- Outgoing */

			'smtp_enabled'    => true,
			'smtp_host'       => 'smtp.gmail.com',
			'smtp_port'       => 587,
			'smtp_encryption' => 'tls',
			'smtp_auth'       => true,
			'smtp_user'       => 'sobhit.deftsoft@gmail.com',

			// A Gmail App Password, not the account password. Spaces are fine;
			// they are stripped before use.
			'smtp_pass'       => 'prqrqlfzqbpyfciy',
			'smtp_from_email' => 'sobhit.deftsoft@gmail.com',
			'smtp_from_name'  => 'Support Team',
			'smtp_verify_ssl' => false,
		);
	}

	/**
	 * Read a value: constant first, then the defaults above.
	 *
	 * @param string $key      Config key.
	 * @param mixed  $fallback Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$constant = 'OMNINOX_' . strtoupper( $key );

		if ( defined( $constant ) ) {
			return constant( $constant );
		}

		$defaults = self::defaults();

		$value = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : $fallback;

		/**
		 * Filter a configuration value.
		 *
		 * @param mixed  $value Value.
		 * @param string $key   Key.
		 */
		return apply_filters( 'omninox_config', $value, $key );
	}

	/**
	 * Read a value as a trimmed string.
	 *
	 * @param string $key Config key.
	 * @return string
	 */
	public static function text( $key ) {
		return trim( (string) self::get( $key, '' ) );
	}

	/**
	 * Read a value as a boolean.
	 *
	 * @param string $key Config key.
	 * @return bool
	 */
	public static function flag( $key ) {
		return (bool) self::get( $key, false );
	}

	/**
	 * Read a value as an integer.
	 *
	 * @param string $key Config key.
	 * @return int
	 */
	public static function number( $key ) {
		return (int) self::get( $key, 0 );
	}
}
