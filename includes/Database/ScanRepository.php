<?php
/**
 * Persistence for scan results and issues.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Database;

defined( 'ABSPATH' ) || exit;

class ScanRepository {

	/**
	 * Store a scan payload and its issues.
	 *
	 * @param string $module  Module id.
	 * @param array  $payload Result payload.
	 * @return int Scan id.
	 */
	public static function save( $module, array $payload ) {
		global $wpdb;

		$table = Schema::table( 'scans' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'site_id'    => get_current_blog_id(),
				'module'     => $module,
				'score'      => (int) ( $payload['score'] ?? 0 ),
				'grade'      => (string) ( $payload['grade'] ?? '' ),
				'summary'    => wp_json_encode( $payload['summary'] ?? array() ),
				'payload'    => wp_json_encode( $payload ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		$scan_id = (int) $wpdb->insert_id;

		self::save_issues( $scan_id, $module, $payload['issues'] ?? array() );
		self::record_score( $module, (int) ( $payload['score'] ?? 0 ) );

		$scores            = (array) get_option( 'omninox_last_scores', array() );
		$scores[ $module ] = array(
			'score' => (int) ( $payload['score'] ?? 0 ),
			'grade' => (string) ( $payload['grade'] ?? '' ),
			'at'    => current_time( 'mysql' ),
		);
		update_option( 'omninox_last_scores', $scores, false );

		return $scan_id;
	}

	/**
	 * Insert issue rows for a scan.
	 *
	 * @param int    $scan_id Scan id.
	 * @param string $module  Module id.
	 * @param array  $issues  Issues.
	 */
	private static function save_issues( $scan_id, $module, array $issues ) {
		global $wpdb;

		$table = Schema::table( 'issues' );
		$now   = current_time( 'mysql' );

		foreach ( $issues as $issue ) {
			if ( 'pass' === ( $issue['severity'] ?? '' ) ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'scan_id'    => $scan_id,
					'module'     => $module,
					'issue_key'  => (string) ( $issue['id'] ?? '' ),
					'severity'   => (string) ( $issue['severity'] ?? 'notice' ),
					'label'      => (string) ( $issue['label'] ?? '' ),
					'details'    => wp_json_encode( $issue ),
					'created_at' => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Record a score point for trend charts.
	 *
	 * @param string $module Module id.
	 * @param int    $score  Score.
	 */
	private static function record_score( $module, $score ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'metrics' ),
			array(
				'metric'      => 'score.' . $module,
				'value'       => $score,
				'recorded_at' => current_time( 'mysql' ),
			),
			array( '%s', '%f', '%s' )
		);
	}

	/**
	 * Latest stored result for a module.
	 *
	 * @param string $module Module id.
	 * @return array|null
	 */
	public static function latest( $module ) {
		global $wpdb;

		$table = Schema::table( 'scans' );

		$row = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE module = %s ORDER BY id DESC LIMIT 1", $module )
		);

		return $row ? json_decode( $row, true ) : null;
	}

	/**
	 * Score history for charts.
	 *
	 * @param string $module Module id.
	 * @param int    $days   Look-back window.
	 * @return array
	 */
	public static function history( $module, $days = 30 ) {
		global $wpdb;

		$table = Schema::table( 'metrics' );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT value, recorded_at FROM {$table} WHERE metric = %s AND recorded_at >= %s ORDER BY recorded_at ASC",
				'score.' . $module,
				$since
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'x' => $row['recorded_at'],
					'y' => (float) $row['value'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Delete rows older than the retention window.
	 *
	 * @param int $days Retention days.
	 */
	public static function prune( $days = 90 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

		foreach ( array( 'scans', 'issues', 'logs' ) as $name ) {
			$table  = Schema::table( $name );
			$column = 'created_at';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
		}

		$metrics = Schema::table( 'metrics' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$metrics} WHERE recorded_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}
