<?php
/**
 * Universal compatibility & self-healing service for 3rd-party plugins and themes.
 *
 * Automatically resolves known PHP 8+ incompatibilities, null-safety issues,
 * template undefined variables, and database anomalies across all sites running Omninox Site Toolkit.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

defined( 'ABSPATH' ) || exit;

class CompatibilityFixer {

	/**
	 * Register hooks and runtime filters.
	 */
	public static function register() {
		// Only run post-restore database normalization on explicit post-restore event.
		add_action( 'omninox_post_restore', array( __CLASS__, 'run_post_restore_fixes' ) );

		// WPJobBoard runtime safety filters (read-only output normalization).
		add_filter( 'wpjb_geolocate', array( __CLASS__, 'filter_wpjb_geolocate' ), 10, 2 );
		add_filter( 'wpjb_location_display', array( __CLASS__, 'filter_wpjb_location_display' ), 10, 2 );
	}

	/**
	 * Master routine executed after backup restore or during maintenance.
	 */
	public static function run_post_restore_fixes() {
		self::sanitize_wpjobboard_database();
	}

	/**
	 * Sanitize WPJobBoard tables in the database to prevent null lookups.
	 */
	public static function sanitize_wpjobboard_database() {
		global $wpdb;

		$tables = array(
			"{$wpdb->prefix}wpjb_resume"  => 'candidate_country',
			"{$wpdb->prefix}wpjb_company" => 'company_country',
			"{$wpdb->prefix}wpjb_job"     => 'job_country',
		);

		foreach ( $tables as $table => $column ) {
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
			if ( $table_exists === $table ) {
				// Normalize NULL country values to empty string.
				$wpdb->query( "UPDATE `{$table}` SET `{$column}` = '' WHERE `{$column}` IS NULL" ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Runtime safety filter for geolocate addresses.
	 *
	 * @param array $addr Location parts.
	 * @param mixed $object Object.
	 * @return array
	 */
	public static function filter_wpjb_geolocate( $addr, $object = null ) {
		if ( ! is_array( $addr ) ) {
			return $addr;
		}

		return array_values( array_filter( array_map( 'trim', $addr ), 'strlen' ) );
	}

	/**
	 * Runtime safety filter for location display strings.
	 *
	 * @param string $location Location string.
	 * @param mixed  $object   Object.
	 * @return string
	 */
	public static function filter_wpjb_location_display( $location, $object = null ) {
		if ( ! is_string( $location ) ) {
			return (string) $location;
		}

		// Remove leading/trailing/duplicate commas e.g. ", , Thailand" -> "Thailand"
		$parts = array_filter( array_map( 'trim', explode( ',', $location ) ), 'strlen' );

		return implode( ', ', $parts );
	}
}
