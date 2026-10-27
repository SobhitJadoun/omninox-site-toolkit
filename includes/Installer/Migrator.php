<?php
/**
 * Schema upgrades between releases.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Installer;

use OmninoxSiteToolkit\Database\Schema;

defined( 'ABSPATH' ) || exit;

class Migrator {

	/**
	 * Run dbDelta when the stored version is behind.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'omninox_site_toolkit_db_version', get_option( 'omninox_db_version' ) );

		if ( OMNINOX_SITE_TOOLKIT_DB_VERSION === $stored ) {
			return;
		}

		Schema::install();

		// Tidy up data belonging to features that have since been removed.
		delete_option( 'omninox_pagespeed' );

		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'DELETE FROM ' . \OmninoxSiteToolkit\Database\Schema::table( 'metrics' ) . ' WHERE metric LIKE %s',
				'psi.%'
			)
		);

		// 1.4.0 shipped a single `omninox_chat` table. The support system now uses
		// separate conversation and message tables, and the old rows carry no
		// ticket or status, so there is nothing worth migrating across.
		if ( $stored && version_compare( $stored, '1.5.0', '<' ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( 'chat' ) ); // phpcs:ignore WordPress.DB
		}

		update_option( 'omninox_site_toolkit_db_version', OMNINOX_SITE_TOOLKIT_DB_VERSION );
		update_option( 'omninox_db_version', OMNINOX_SITE_TOOLKIT_DB_VERSION );

		do_action( 'omninox_upgraded', $stored, OMNINOX_SITE_TOOLKIT_DB_VERSION );
	}
}
