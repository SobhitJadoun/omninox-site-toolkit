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

		$scans   = self::table( 'scans' );
		$issues  = self::table( 'issues' );
		$logs    = self::table( 'logs' );
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

		self::install_media_tables();

		update_option( 'omninox_site_toolkit_db_version', OMNINOX_SITE_TOOLKIT_DB_VERSION );
	}

	/**
	 * Install or update high-performance unused media reference tables.
	 */
	public static function install_media_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$media_refs  = self::table( 'media_references' );
		$scan_state  = self::table( 'media_scan_state' );
		$media_items = self::table( 'media_items' );

		$sql = array();

		$sql[] = "CREATE TABLE {$media_refs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			normalized_url VARCHAR(255) NOT NULL DEFAULT '',
			normalized_filename VARCHAR(191) NOT NULL DEFAULT '',
			source_type VARCHAR(50) NOT NULL DEFAULT 'post',
			source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reference_type VARCHAR(50) NOT NULL DEFAULT 'content',
			reference_field VARCHAR(100) NOT NULL DEFAULT '',
			reference_hash VARCHAR(64) NOT NULL DEFAULT '',
			last_seen DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			scan_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY normalized_filename (normalized_filename),
			KEY source_lookup (source_type, source_id),
			KEY scan_gen (scan_generation),
			KEY ref_hash (reference_hash)
		) {$charset};";

		$sql[] = "CREATE TABLE {$scan_state} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scan_id VARCHAR(64) NOT NULL DEFAULT '',
			mode VARCHAR(20) NOT NULL DEFAULT 'full',
			status VARCHAR(20) NOT NULL DEFAULT 'idle',
			current_phase VARCHAR(50) NOT NULL DEFAULT 'discovery',
			cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			references_indexed BIGINT UNSIGNED NOT NULL DEFAULT 0,
			generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
			phase_state LONGTEXT NULL,
			stats LONGTEXT NULL,
			started_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			error_count INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY scan_id (scan_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$media_items} (
			attachment_id BIGINT UNSIGNED NOT NULL,
			filename VARCHAR(191) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			url VARCHAR(255) NOT NULL DEFAULT '',
			thumb_url VARCHAR(255) NOT NULL DEFAULT '',
			bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			formatted_size VARCHAR(30) NOT NULL DEFAULT '',
			uploaded_date DATE NOT NULL DEFAULT '0000-00-00',
			status VARCHAR(30) NOT NULL DEFAULT 'likely_unused',
			is_quarantined TINYINT(1) NOT NULL DEFAULT 0,
			is_protected TINYINT(1) NOT NULL DEFAULT 0,
			protection_reason VARCHAR(255) NOT NULL DEFAULT '',
			references_count INT NOT NULL DEFAULT 0,
			references_sample TEXT NULL,
			scan_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (attachment_id),
			KEY status_filter (status, is_quarantined),
			KEY filename (filename),
			KEY uploaded_date (uploaded_date),
			KEY scan_generation (scan_generation)
		) {$charset};";

		$url_mappings = self::table( 'url_mappings' );
		$url_rollback = self::table( 'url_rollback' );
		$url_jobs     = self::table( 'url_jobs' );

		$sql[] = "CREATE TABLE {$url_mappings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			size_key VARCHAR(50) NOT NULL DEFAULT '_main',
			format VARCHAR(20) NOT NULL DEFAULT 'webp',
			old_rel VARCHAR(255) NOT NULL DEFAULT '',
			new_rel VARCHAR(255) NOT NULL DEFAULT '',
			old_url VARCHAR(500) NOT NULL DEFAULT '',
			new_url VARCHAR(500) NOT NULL DEFAULT '',
			old_file_exists TINYINT(1) NOT NULL DEFAULT 1,
			new_file_exists TINYINT(1) NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'verified',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY attachment_size (attachment_id, size_key, format),
			KEY status_format (status, format),
			KEY old_url_prefix (old_url(191)),
			KEY format (format)
		) {$charset};";

		$sql[] = "CREATE TABLE {$url_rollback} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(64) NOT NULL DEFAULT '',
			table_name VARCHAR(64) NOT NULL DEFAULT '',
			row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			column_name VARCHAR(64) NOT NULL DEFAULT '',
			original_value LONGTEXT NULL,
			new_value LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY job_id (job_id),
			KEY table_row (table_name, row_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$url_jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(64) NOT NULL DEFAULT '',
			mode VARCHAR(20) NOT NULL DEFAULT 'replace',
			format VARCHAR(20) NOT NULL DEFAULT 'webp',
			status VARCHAR(32) NOT NULL DEFAULT 'idle',
			current_step VARCHAR(50) NOT NULL DEFAULT 'init',
			cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_mappings INT UNSIGNED NOT NULL DEFAULT 0,
			rows_inspected INT UNSIGNED NOT NULL DEFAULT 0,
			rows_replaced INT UNSIGNED NOT NULL DEFAULT 0,
			replacements_count INT UNSIGNED NOT NULL DEFAULT 0,
			elapsed_ms INT UNSIGNED NOT NULL DEFAULT 0,
			checkpoint_data LONGTEXT NULL,
			error_message TEXT NULL,
			started_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY job_id (job_id),
			KEY status (status)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
