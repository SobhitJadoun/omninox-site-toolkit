<?php
/**
 * OmniNox Central URL Mapping Index.
 *
 * Discovers, normalizes, validates, and indexes conversion URL mappings
 * (JPEG/PNG/GIF -> WebP/AVIF) across all registered image sizes.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Replacement;

use OmninoxSiteToolkit\Database\Schema;

defined( 'ABSPATH' ) || exit;

class UrlMappingIndex {

	/**
	 * Build or refresh verified conversion mappings for attachments.
	 *
	 * Uses keyset pagination (ID > cursor) and bulk multi-row database insertion.
	 *
	 * @param string $format Format: webp|avif|both.
	 * @param int    $batch Batch size of attachments to process.
	 * @param int    $cursor Attachment ID keyset cursor.
	 * @return array Batch indexing progress.
	 */
	public static function sync_mappings( string $format = 'webp', int $batch = 100, int $cursor = 0 ): array {
		global $wpdb;

		Schema::install_media_tables();
		$table = Schema::table( 'url_mappings' );

		$formats = ( 'both' === $format || 'all' === $format ) ? array( 'webp', 'avif' ) : array( $format );

		$uploads   = wp_upload_dir();
		$basedir   = trailingslashit( $uploads['basedir'] );
		$baseurl   = trailingslashit( $uploads['baseurl'] );
		$base_path = wp_parse_url( $baseurl, PHP_URL_PATH );

		// Keyset pagination on attachments
		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_mime_type FROM {$wpdb->posts} 
				 WHERE ID > %d 
				 AND post_type = 'attachment' 
				 AND post_mime_type IN ('image/jpeg', 'image/png', 'image/gif') 
				 AND post_status = 'inherit' 
				 ORDER BY ID ASC 
				 LIMIT %d",
				$cursor,
				$batch
			),
			ARRAY_A
		);

		if ( empty( $attachments ) ) {
			$total_verified = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE status = 'verified'" );
			return array(
				'done'           => true,
				'cursor'         => $cursor,
				'processed'      => 0,
				'total_mappings' => $total_verified,
				'message'        => __( 'Mapping index synchronization complete.', 'omninox-site-toolkit' ),
			);
		}

		$att_ids = wp_list_pluck( $attachments, 'ID' );
		update_meta_cache( 'post', $att_ids );

		$insert_placeholders = array();
		$insert_values       = array();
		$now                 = gmdate( 'Y-m-d H:i:s' );
		$max_id              = $cursor;

		foreach ( $attachments as $att ) {
			$att_id = (int) $att['ID'];
			if ( $att_id > $max_id ) {
				$max_id = $att_id;
			}

			$original_file = (string) get_post_meta( $att_id, '_wp_attached_file', true );
			if ( empty( $original_file ) ) {
				continue;
			}

			$metadata   = wp_get_attachment_metadata( $att_id );
			$dir        = trailingslashit( dirname( $original_file ) );
			$candidates = array(
				array( 'key' => '_main', 'file' => $original_file ),
			);

			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_name => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$candidates[] = array( 'key' => $size_name, 'file' => $dir . $size_info['file'] );
				}
			}

			foreach ( $formats as $fmt ) {
				$meta_key = '_omninox_' . $fmt;
				$record   = (array) get_post_meta( $att_id, $meta_key, true );

				if ( empty( $record['files'] ) ) {
					continue;
				}

				foreach ( (array) $record['files'] as $conv_relative ) {
					$conv_stem = preg_replace( '/\.(webp|avif)$/i', '', $conv_relative );

					foreach ( $candidates as $candidate ) {
						$cand_rel  = $candidate['file'];
						$cand_stem = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $cand_rel );

						if ( $cand_stem !== $conv_stem ) {
							continue;
						}

						$old_path = $basedir . $cand_rel;
						$new_path = $basedir . $conv_relative;

						$old_exists = file_exists( $old_path );
						$new_exists = file_exists( $new_path );

						if ( $old_exists && $new_exists ) {
							$old_url = $baseurl . $cand_rel;
							$new_url = $baseurl . $conv_relative;

							$insert_placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s)';
							$insert_values[]       = $att_id;
							$insert_values[]       = $candidate['key'];
							$insert_values[]       = $fmt;
							$insert_values[]       = $cand_rel;
							$insert_values[]       = $conv_relative;
							$insert_values[]       = $old_url;
							$insert_values[]       = $new_url;
							$insert_values[]       = 1;
							$insert_values[]       = 1;
							$insert_values[]       = 'verified';
							$insert_values[]       = $now;
						}

						break;
					}
				}
			}
		}

		if ( ! empty( $insert_placeholders ) ) {
			$sql = "INSERT INTO {$table} 
				(attachment_id, size_key, format, old_rel, new_rel, old_url, new_url, old_file_exists, new_file_exists, status, created_at) 
				VALUES " . implode( ', ', $insert_placeholders ) . " 
				ON DUPLICATE KEY UPDATE 
					new_url = VALUES(new_url), 
					new_rel = VALUES(new_rel), 
					old_file_exists = VALUES(old_file_exists), 
					new_file_exists = VALUES(new_file_exists), 
					status = VALUES(status), 
					created_at = VALUES(created_at)";

			$wpdb->query( $wpdb->prepare( $sql, $insert_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$total_verified = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE status = 'verified'" );

		return array(
			'done'           => false,
			'cursor'         => $max_id,
			'processed'      => count( $attachments ),
			'total_mappings' => $total_verified,
			'message'        => sprintf(
				/* translators: 1: processed attachments, 2: total verified mappings */
				__( 'Indexed %1$d attachments (%2$d verified mappings)…', 'omninox-site-toolkit' ),
				count( $attachments ),
				$total_verified
			),
		);
	}

	/**
	 * Retrieve multi-pattern search-and-replace map.
	 *
	 * Sorted by strlen descending to guarantee that longer variants (e.g. image-300x200.jpg)
	 * are matched before shorter stems (image.jpg).
	 *
	 * @param string $format Target format: webp|avif|both.
	 * @param bool   $include_escaped Include JSON-escaped URLs (e.g. for Elementor/Gutenberg).
	 * @param bool   $include_relative Include relative URL paths.
	 * @return array Associative array of [ search_pattern => replacement_pattern ].
	 */
	public static function get_replacement_map( string $format = 'webp', bool $include_escaped = true, bool $include_relative = true ): array {
		global $wpdb;

		$table = Schema::table( 'url_mappings' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		$format_clause = ( 'both' === $format || 'all' === $format )
			? "format IN ('webp', 'avif')"
			: $wpdb->prepare( 'format = %s', $format );

		$rows = $wpdb->get_results(
			"SELECT old_url, new_url, old_rel, new_rel 
			 FROM {$table} 
			 WHERE {$format_clause} AND status = 'verified' AND new_file_exists = 1",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$uploads   = wp_upload_dir();
		$baseurl   = trailingslashit( $uploads['baseurl'] );
		$base_path = wp_parse_url( $baseurl, PHP_URL_PATH );

		$map = array();

		foreach ( $rows as $row ) {
			$old = $row['old_url'];
			$new = $row['new_url'];
			if ( empty( $old ) || empty( $new ) ) {
				continue;
			}

			// 1. Full Absolute URL
			$map[ $old ] = $new;

			// 2. JSON-Escaped URL (e.g. https:\/\/site.com\/wp-content\/uploads\/...)
			if ( $include_escaped ) {
				$old_json = str_replace( '/', '\/', $old );
				$new_json = str_replace( '/', '\/', $new );
				$map[ $old_json ] = $new_json;
			}

			// 3. Relative URL paths (e.g. /wp-content/uploads/2026/01/pic.jpg)
			if ( $include_relative && ! empty( $row['old_rel'] ) && ! empty( $row['new_rel'] ) ) {
				$map[ $row['old_rel'] ] = $row['new_rel'];

				if ( $base_path ) {
					$rel_old = trailingslashit( $base_path ) . $row['old_rel'];
					$rel_new = trailingslashit( $base_path ) . $row['new_rel'];
					$map[ $rel_old ] = $rel_new;

					if ( $include_escaped ) {
						$map[ str_replace( '/', '\/', $rel_old ) ] = str_replace( '/', '\/', $rel_new );
					}
				}
			}

			// 4. Non-standard port URL alias (e.g. http://site.local:10010 vs http://site.local)
			$parsed = wp_parse_url( $old );
			if ( ! empty( $parsed['port'] ) ) {
				$scheme       = $parsed['scheme'] ?? 'http';
				$host_no_port = $parsed['host'];
				$path_query   = ( $parsed['path'] ?? '' ) . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
				$old_no_port  = $scheme . '://' . $host_no_port . $path_query;
				$new_no_port  = str_replace( ':' . $parsed['port'], '', $new );
				$map[ $old_no_port ] = $new_no_port;

				if ( $include_escaped ) {
					$map[ str_replace( '/', '\/', $old_no_port ) ] = str_replace( '/', '\/', $new_no_port );
				}
			}
		}

		// Sort by key length descending to prevent shorter strings from breaking longer filenames
		uksort( $map, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		return $map;
	}

	/**
	 * Retrieve summary statistics about current conversion mappings.
	 *
	 * @param string $format Format webp|avif|both.
	 * @return array
	 */
	public static function get_summary( string $format = 'webp' ): array {
		global $wpdb;

		Schema::install_media_tables();
		$table = Schema::table( 'url_mappings' );

		$format_clause = ( 'both' === $format || 'all' === $format )
			? "format IN ('webp', 'avif')"
			: $wpdb->prepare( 'format = %s', $format );

		$total_verified = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE {$format_clause} AND status = 'verified'" );
		$attachments    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM {$table} WHERE {$format_clause} AND status = 'verified'" );
		$missing_files  = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table} WHERE {$format_clause} AND new_file_exists = 0" );

		return array(
			'total_mappings'      => $total_verified,
			'distinct_attachments'=> $attachments,
			'missing_files'       => $missing_files,
			'format'              => $format,
		);
	}

	/**
	 * Generate streaming CSV content for export.
	 *
	 * Directly streams from the database without storing millions of pairs in PHP memory.
	 *
	 * @param string $format webp|avif|both.
	 * @param int    $limit Max rows to export (0 for unlimited).
	 * @return string CSV content.
	 */
	public static function export_csv( string $format = 'webp', int $limit = 0 ): string {
		global $wpdb;

		$table = Schema::table( 'url_mappings' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			// Trigger initial sync if empty
			self::sync_mappings( $format, 500, 0 );
		}

		$format_clause = ( 'both' === $format || 'all' === $format )
			? "format IN ('webp', 'avif')"
			: $wpdb->prepare( 'format = %s', $format );

		$limit_clause = ( $limit > 0 ) ? $wpdb->prepare( 'LIMIT %d', $limit ) : '';

		$rows = $wpdb->get_results(
			"SELECT attachment_id, size_key, format, old_url, new_url, status 
			 FROM {$table} 
			 WHERE {$format_clause} 
			 ORDER BY attachment_id ASC, id ASC 
			 {$limit_clause}",
			ARRAY_A
		);

		$fp = fopen( 'php://temp', 'r+' );
		fputcsv( $fp, array( 'Attachment ID', 'Size Key', 'Format', 'Original URL', 'Converted URL', 'Status' ) );

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				fputcsv( $fp, array(
					$r['attachment_id'],
					$r['size_key'],
					strtoupper( $r['format'] ),
					$r['old_url'],
					$r['new_url'],
					$r['status'],
				) );
			}
		}

		rewind( $fp );
		$csv = stream_get_contents( $fp );
		fclose( $fp );

		return (string) $csv;
	}
}
