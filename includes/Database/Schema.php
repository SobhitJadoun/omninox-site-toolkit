<?php
/**
 * Custom table definitions.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Database;

defined( 'ABSPATH' ) || exit;

class Schema {

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'omninox_' . $name;
	}

	/**
	 * Create or update tables via dbDelta.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$scans = self::table( 'scans' );
		$issues = self::table( 'issues' );
		$logs   = self::table( 'logs' );
		$metrics = self::table( 'metrics' );

		$sql = array();

		$sql[] = "CREATE TABLE {$scans} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			module VARCHAR(50) NOT NULL,
			score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			grade VARCHAR(2) NOT NULL DEFAULT '',
			summary LONGTEXT NULL,
			payload LONGTEXT NULL,
			duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY module_time (module, created_at),
			KEY site_module (site_id, module)
		) {$charset};";

		$sql[] = "CREATE TABLE {$issues} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			module VARCHAR(50) NOT NULL,
			issue_key VARCHAR(120) NOT NULL,
			severity VARCHAR(20) NOT NULL DEFAULT 'notice',
			label TEXT NULL,
			details LONGTEXT NULL,
			resolved TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY scan (scan_id),
			KEY module_sev (module, severity),
			KEY issue_key (issue_key)
		) {$charset};";

		$sql[] = "CREATE TABLE {$logs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(30) NOT NULL DEFAULT 'php',
			level VARCHAR(20) NOT NULL DEFAULT 'error',
			message LONGTEXT NULL,
			file VARCHAR(255) NULL,
			line INT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(190) NULL,
			request_uri VARCHAR(255) NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			hits INT UNSIGNED NOT NULL DEFAULT 1,
			fingerprint CHAR(32) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY type_level (type, level),
			KEY created (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$metrics} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			metric VARCHAR(60) NOT NULL,
			value DOUBLE NOT NULL DEFAULT 0,
			meta LONGTEXT NULL,
			recorded_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY metric_time (metric, recorded_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'omninox_site_toolkit_db_version', OMNINOX_SITE_TOOLKIT_DB_VERSION );
	}
}
