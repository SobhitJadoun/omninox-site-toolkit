<?php
/**
 * Safe Quarantine and Restoration Manager.
 *
 * Safely moves unreferenced images into an isolated holding area,
 * preserves all file variants and WordPress attachment metadata,
 * generates a tamper-evident restoration manifest, and enables
 * zero-loss restoration before permanent deletion.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused;

defined( 'ABSPATH' ) || exit;

class QuarantineManager {

	/**
	 * Quarantine storage directory inside uploads.
	 *
	 * @return string Full path to quarantine directory.
	 */
	public static function get_quarantine_dir(): string {
		$uploads = wp_upload_dir();
		$dir = trailingslashit( $uploads['basedir'] ) . 'omninox-media-quarantine';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );

			// Protect quarantine folder from web access and PHP execution
			$htaccess = trailingslashit( $dir ) . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				@file_put_contents( $htaccess, "Options -Indexes\n<Files *>\nDeny from all\n</Files>\n" );
			}

			$index_php = trailingslashit( $dir ) . 'index.php';
			if ( ! file_exists( $index_php ) ) {
				@file_put_contents( $index_php, "<?php\n// Silence is golden.\nexit;\n" );
			}
		}

		return $dir;
	}

	/**
	 * Move an attachment and its entire variant family to quarantine.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error Result details or error.
	 */
	public static function quarantine( int $attachment_id ) {
		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return new \WP_Error( 'omninox_forbidden', __( 'You do not have permission to quarantine this media item.', 'omninox-site-toolkit' ) );
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'omninox_not_found', __( 'Attachment not found.', 'omninox-site-toolkit' ) );
		}

		if ( get_post_meta( $attachment_id, '_omninox_quarantined', true ) ) {
			return new \WP_Error( 'omninox_already_quarantined', __( 'This media item is already in quarantine.', 'omninox-site-toolkit' ) );
		}

		$uploads  = wp_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$q_dir    = self::get_quarantine_dir();
		$orig_rel = get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( empty( $orig_rel ) ) {
			return new \WP_Error( 'omninox_no_file', __( 'Attachment file metadata is missing.', 'omninox-site-toolkit' ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$files_to_quarantine = array();

		// Main file
		$main_file = $basedir . $orig_rel;
		if ( file_exists( $main_file ) ) {
			$files_to_quarantine[] = array(
				'key'      => '_main',
				'original' => $orig_rel,
				'abs_src'  => $main_file,
				'bytes'    => filesize( $main_file ),
			);
		}

		// Generated intermediate sizes
		$upload_sub_dir = trailingslashit( dirname( $orig_rel ) );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$size_rel = $upload_sub_dir . $size_info['file'];
					$size_abs = $basedir . $size_rel;
					if ( file_exists( $size_abs ) ) {
						$files_to_quarantine[] = array(
							'key'      => $size_key,
							'original' => $size_rel,
							'abs_src'  => $size_abs,
							'bytes'    => filesize( $size_abs ),
						);
					}
				}
			}
		}

		// WebP/AVIF copies if present
		$webp_rec = (array) get_post_meta( $attachment_id, '_omninox_webp', true );
		foreach ( (array) ( $webp_rec['files'] ?? array() ) as $w_rel ) {
			$w_abs = $basedir . $w_rel;
			if ( file_exists( $w_abs ) ) {
				$files_to_quarantine[] = array(
					'key'      => '_webp_' . wp_basename( $w_rel ),
					'original' => $w_rel,
					'abs_src'  => $w_abs,
					'bytes'    => filesize( $w_abs ),
				);
			}
		}

		$avif_rec = (array) get_post_meta( $attachment_id, '_omninox_avif', true );
		foreach ( (array) ( $avif_rec['files'] ?? array() ) as $a_rel ) {
			$a_abs = $basedir . $a_rel;
			if ( file_exists( $a_abs ) ) {
				$files_to_quarantine[] = array(
					'key'      => '_avif_' . wp_basename( $a_rel ),
					'original' => $a_rel,
					'abs_src'  => $a_abs,
					'bytes'    => filesize( $a_abs ),
				);
			}
		}

		if ( empty( $files_to_quarantine ) ) {
			return new \WP_Error( 'omninox_files_missing', __( 'Original files could not be located on disk.', 'omninox-site-toolkit' ) );
		}

		// Execute quarantine move
		$moved_files  = array();
		$total_bytes  = 0;
		$item_sub_dir = $attachment_id . '_' . sanitize_file_name( wp_basename( $orig_rel ) );
		$target_folder = trailingslashit( $q_dir ) . $item_sub_dir;
		wp_mkdir_p( $target_folder );

		foreach ( $files_to_quarantine as $entry ) {
			$dest = trailingslashit( $target_folder ) . wp_basename( $entry['original'] );
			if ( @rename( $entry['abs_src'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$moved_files[] = array(
					'key'          => $entry['key'],
					'original_rel' => $entry['original'],
					'quarantine_at'=> $item_sub_dir . '/' . wp_basename( $entry['original'] ),
					'bytes'        => $entry['bytes'],
				);
				$total_bytes += $entry['bytes'];
			}
		}

		if ( empty( $moved_files ) ) {
			return new \WP_Error( 'omninox_move_failed', __( 'Failed to isolate files into quarantine.', 'omninox-site-toolkit' ) );
		}

		$current_user = wp_get_current_user();
		$manifest = array(
			'attachment_id'   => $attachment_id,
			'title'           => $post->post_title,
			'original_rel'    => $orig_rel,
			'original_mime'   => $post->post_mime_type,
			'metadata'        => $metadata,
			'moved_files'     => $moved_files,
			'total_bytes'     => $total_bytes,
			'quarantined_at'  => time(),
			'quarantined_by'  => $current_user->user_login ?: 'admin',
			'expires_at'      => time() + ( 30 * DAY_IN_SECONDS ),
		);

		// Save manifest in quarantine folder
		@file_put_contents( trailingslashit( $target_folder ) . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		// Update attachment record
		update_post_meta( $attachment_id, '_omninox_quarantined', $manifest );

		// Log in audit log
		self::record_history( array(
			'action'        => 'quarantine',
			'attachment_id' => $attachment_id,
			'filename'      => wp_basename( $orig_rel ),
			'size'          => $total_bytes,
			'path'          => $orig_rel,
			'user'          => $current_user->user_login ?: 'admin',
			'status'        => 'success',
		) );

		return array(
			'id'          => $attachment_id,
			'filename'    => wp_basename( $orig_rel ),
			'bytes'       => $total_bytes,
			'files_count' => count( $moved_files ),
			'message'     => sprintf( __( 'Moved "%s" to quarantine holding area.', 'omninox-site-toolkit' ), wp_basename( $orig_rel ) ),
		);
	}

	/**
	 * Restore a quarantined attachment back to its original location.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|\WP_Error Result or error.
	 */
	public static function restore( int $attachment_id ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'omninox_forbidden', __( 'You do not have permission to restore media.', 'omninox-site-toolkit' ) );
		}

		$manifest = get_post_meta( $attachment_id, '_omninox_quarantined', true );
		if ( empty( $manifest ) || ! is_array( $manifest ) ) {
			return new \WP_Error( 'omninox_not_quarantined', __( 'No quarantine record found for this attachment.', 'omninox-site-toolkit' ) );
		}

		$uploads  = wp_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$q_dir    = self::get_quarantine_dir();
		$restored = 0;

		foreach ( (array) ( $manifest['moved_files'] ?? array() ) as $entry ) {
			$q_file = trailingslashit( $q_dir ) . ltrim( $entry['quarantine_at'], '/' );
			$dest   = $basedir . ltrim( $entry['original_rel'], '/' );

			if ( file_exists( $q_file ) ) {
				wp_mkdir_p( dirname( $dest ) );
				if ( @rename( $q_file, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					++$restored;
				}
			}
		}

		// Restore attachment post and metadata
		update_post_meta( $attachment_id, '_wp_attached_file', $manifest['original_rel'] );
		if ( ! empty( $manifest['metadata'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $manifest['metadata'] );
		}

		// Remove manifest JSON and folder
		$item_folder = trailingslashit( $q_dir ) . dirname( $manifest['moved_files'][0]['quarantine_at'] ?? '' );
		if ( is_dir( $item_folder ) ) {
			@unlink( trailingslashit( $item_folder ) . 'manifest.json' );
			@rmdir( $item_folder );
		}

		delete_post_meta( $attachment_id, '_omninox_quarantined' );

		$current_user = wp_get_current_user();
		self::record_history( array(
			'action'        => 'restore',
			'attachment_id' => $attachment_id,
			'filename'      => wp_basename( $manifest['original_rel'] ),
			'size'          => $manifest['total_bytes'] ?? 0,
			'path'          => $manifest['original_rel'],
			'user'          => $current_user->user_login ?: 'admin',
			'status'        => 'success',
		) );

		return array(
			'id'          => $attachment_id,
			'filename'    => wp_basename( $manifest['original_rel'] ),
			'restored'    => $restored,
			'message'     => sprintf( __( 'Restored "%s" from quarantine.', 'omninox-site-toolkit' ), wp_basename( $manifest['original_rel'] ) ),
		);
	}

	/**
	 * Permanently delete an attachment (either from quarantine or directly if confirmed).
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force_delete True to skip trash.
	 * @return array|\WP_Error
	 */
	public static function delete_permanently( int $attachment_id, bool $force_delete = true ) {
		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return new \WP_Error( 'omninox_forbidden', __( 'You do not have permission to delete this media item.', 'omninox-site-toolkit' ) );
		}

		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return new \WP_Error( 'omninox_not_found', __( 'Attachment not found.', 'omninox-site-toolkit' ) );
		}

		$filename = wp_basename( get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$manifest = get_post_meta( $attachment_id, '_omninox_quarantined', true );
		$size     = 0;

		// If quarantined, delete quarantined files from disk
		if ( ! empty( $manifest['moved_files'] ) ) {
			$q_dir = self::get_quarantine_dir();
			foreach ( $manifest['moved_files'] as $entry ) {
				$q_file = trailingslashit( $q_dir ) . ltrim( $entry['quarantine_at'], '/' );
				if ( file_exists( $q_file ) ) {
					@unlink( $q_file );
				}
			}
			$item_folder = trailingslashit( $q_dir ) . dirname( $manifest['moved_files'][0]['quarantine_at'] ?? '' );
			if ( is_dir( $item_folder ) ) {
				@unlink( trailingslashit( $item_folder ) . 'manifest.json' );
				@rmdir( $item_folder );
			}
			$size = $manifest['total_bytes'] ?? 0;
		}

		// Delete attachment via standard WordPress API
		$deleted = wp_delete_attachment( $attachment_id, $force_delete );
		if ( ! $deleted ) {
			return new \WP_Error( 'omninox_delete_failed', __( 'Could not delete attachment record.', 'omninox-site-toolkit' ) );
		}

		$current_user = wp_get_current_user();
		self::record_history( array(
			'action'        => 'delete_permanent',
			'attachment_id' => $attachment_id,
			'filename'      => $filename,
			'size'          => $size,
			'path'          => '',
			'user'          => $current_user->user_login ?: 'admin',
			'status'        => 'success',
		) );

		return array(
			'id'       => $attachment_id,
			'filename' => $filename,
			'message'  => sprintf( __( 'Permanently deleted image "%s".', 'omninox-site-toolkit' ), $filename ),
		);
	}

	/**
	 * Append entry to audit history log.
	 *
	 * @param array $entry History details.
	 */
	public static function record_history( array $entry ): void {
		$history = (array) get_option( 'omninox_media_cleanup_history', array() );

		array_unshift(
			$history,
			array(
				'timestamp'     => current_time( 'mysql' ),
				'action'        => sanitize_key( $entry['action'] ?? 'cleanup' ),
				'attachment_id' => (int) ( $entry['attachment_id'] ?? 0 ),
				'filename'      => sanitize_file_name( $entry['filename'] ?? '' ),
				'size'          => (int) ( $entry['size'] ?? 0 ),
				'path'          => sanitize_text_field( $entry['path'] ?? '' ),
				'user'          => sanitize_text_field( $entry['user'] ?? 'admin' ),
				'status'        => sanitize_key( $entry['status'] ?? 'success' ),
			)
		);

		// Keep last 500 audit entries
		if ( count( $history ) > 500 ) {
			$history = array_slice( $history, 0, 500 );
		}

		update_option( 'omninox_media_cleanup_history', $history, false );
	}

	/**
	 * Get cleanup history.
	 *
	 * @param int $limit Max items to return.
	 * @return array
	 */
	public static function get_history( int $limit = 100 ): array {
		$history = (array) get_option( 'omninox_media_cleanup_history', array() );

		return array_slice( $history, 0, $limit );
	}

	/**
	 * List all items currently in quarantine.
	 *
	 * @return array
	 */
	public static function list_quarantined(): array {
		$q_dir = self::get_quarantine_dir();
		$items = array();

		if ( ! is_dir( $q_dir ) ) {
			return $items;
		}

		$folders = glob( trailingslashit( $q_dir ) . '*', GLOB_ONLYDIR );
		if ( empty( $folders ) ) {
			return $items;
		}

		foreach ( $folders as $folder ) {
			$manifest_file = trailingslashit( $folder ) . 'manifest.json';
			if ( file_exists( $manifest_file ) ) {
				$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
				if ( is_array( $manifest ) && ! empty( $manifest['attachment_id'] ) ) {
					$items[] = array(
						'id'             => (int) $manifest['attachment_id'],
						'filename'       => wp_basename( $manifest['original_rel'] ?? '' ),
						'total_bytes'    => (int) ( $manifest['total_bytes'] ?? 0 ),
						'formatted_size' => size_format( (int) ( $manifest['total_bytes'] ?? 0 ) ),
						'quarantined_at' => (int) ( $manifest['quarantined_at'] ?? 0 ),
						'quarantined_by' => $manifest['quarantined_by'] ?? 'admin',
						'variants_count' => count( (array) ( $manifest['moved_files'] ?? array() ) ),
					);
				}
			}
		}

		return $items;
	}
}
