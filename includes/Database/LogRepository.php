<?php
/**
 * Error log storage with de-duplication by fingerprint.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Database;

defined( 'ABSPATH' ) || exit;

class LogRepository {

	/**
	 * Record an entry. Repeat entries increment a hit counter.
	 *
	 * @param array $entry Entry data.
	 * @return bool
	 */
	public static function record( array $entry ) {
		global $wpdb;

		$entry = wp_parse_args(
			$entry,
			array(
				'type'    => 'php',
				'level'   => 'error',
				'message' => '',
				'file'    => '',
				'line'    => 0,
				'source'  => '',
			)
		);

		$fingerprint = md5( $entry['type'] . '|' . $entry['message'] . '|' . $entry['file'] . '|' . $entry['line'] );
		$table       = Schema::table( 'logs' );

		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s", $fingerprint )
		);

		if ( $existing ) {
			return (bool) $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, created_at = %s WHERE id = %d", current_time( 'mysql' ), $existing )
			);
		}

		return (bool) $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'type'        => sanitize_key( $entry['type'] ),
				'level'       => sanitize_key( $entry['level'] ),
				'message'     => wp_kses_post( $entry['message'] ),
				'file'        => sanitize_text_field( $entry['file'] ),
				'line'        => absint( $entry['line'] ),
				'source'      => sanitize_text_field( $entry['source'] ),
				'request_uri' => esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
				'user_id'     => get_current_user_id(),
				'fingerprint' => $fingerprint,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Query logs with filters and pagination.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'type'     => '',
				'level'    => '',
				'search'   => '',
				'page'     => 1,
				'per_page' => 25,
			)
		);

		$table  = Schema::table( 'logs' );
		$where  = array( '1=1' );
		$params = array();

		if ( $args['type'] ) {
			$where[]  = 'type = %s';
			$params[] = sanitize_key( $args['type'] );
		}

		if ( $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = sanitize_key( $args['level'] );
		}

		if ( $args['search'] ) {
			$where[]  = '(message LIKE %s OR file LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$clause   = implode( ' AND ', $where );
		$per_page = max( 1, min( 200, absint( $args['per_page'] ) ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		$rows_sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'items' => (array) $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Empty the log table.
	 *
	 * @return int Rows removed.
	 */
	public static function clear() {
		global $wpdb;

		$table = Schema::table( 'logs' );

		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete records whose messages contain one of the supplied fragments.
	 *
	 * @param string[] $patterns Message fragments.
	 * @return int Rows removed.
	 */
	public static function delete_message_patterns( array $patterns ) {
		global $wpdb;

		$patterns = array_filter( array_map( 'sanitize_text_field', $patterns ) );

		if ( ! $patterns ) {
			return 0;
		}

		$table       = Schema::table( 'logs' );
		$conditions  = array_fill( 0, count( $patterns ), 'message LIKE %s' );
		$placeholders = array_map(
			function ( $pattern ) use ( $wpdb ) { return '%' . $wpdb->esc_like( $pattern ) . '%'; },
			$patterns
		);

		$sql = "DELETE FROM {$table} WHERE type = 'php' AND level IN ('fatal', 'error', 'warning') AND (" . implode( ' OR ', $conditions ) . ')';

		return (int) $wpdb->query( $wpdb->prepare( $sql, $placeholders ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
