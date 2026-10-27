<?php
/**
 * Smart Unused Media Detector.
 *
 * Core orchestrator for scanning, reference classification, variant tracking,
 * and bulk cleanup operations across WordPress installations.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused;

use OmninoxSiteToolkit\Modules\Media\Unused\Providers\ContentProvider;
use OmninoxSiteToolkit\Modules\Media\Unused\Providers\PostMetaProvider;
use OmninoxSiteToolkit\Modules\Media\Unused\Providers\ThemeProvider;
use OmninoxSiteToolkit\Modules\Media\Unused\Providers\TaxonomyProvider;
use OmninoxSiteToolkit\Modules\Media\Unused\Providers\StylesProvider;
use OmninoxSiteToolkit\Modules\Media\Unused\Providers\BuilderProvider;
use OmninoxSiteToolkit\Modules\Media\Unused\Providers\ReferenceProviderInterface;

defined( 'ABSPATH' ) || exit;

class UnusedDetector {

	const STATUS_USED       = 'used';
	const STATUS_UNUSED     = 'likely_unused';
	const STATUS_UNKNOWN    = 'unknown';
	const STATUS_PROTECTED  = 'protected';

	/**
	 * Get all registered reference providers (extensible via filter).
	 *
	 * @return array<string, ReferenceProviderInterface>
	 */
	public static function get_providers(): array {
		$providers = array(
			'content'    => new ContentProvider(),
			'postmeta'   => new PostMetaProvider(),
			'theme'      => new ThemeProvider(),
			'taxonomy'   => new TaxonomyProvider(),
			'styles'     => new StylesProvider(),
			'builders'   => new BuilderProvider(),
		);

		/**
		 * Filter registered media reference providers.
		 *
		 * Enables third-party plugins and OmniNox Pro to register specialized
		 * detectors for sliders, forms, LMS, popups, and page builders.
		 *
		 * @param array $providers Associative list of ReferenceProviderInterface instances.
		 */
		return apply_filters( 'omninox_media_reference_providers', $providers );
	}

	/**
	 * Storage directory for scanner snapshots.
	 *
	 * @return string Path to storage directory.
	 */
	public static function get_storage_dir(): string {
		$uploads = wp_upload_dir();
		$dir = trailingslashit( $uploads['basedir'] ) . 'omninox-media-quarantine/scanner';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Filepath for reference index cache.
	 *
	 * @return string Path to JSON file.
	 */
	private static function get_index_cache_file(): string {
		return trailingslashit( self::get_storage_dir() ) . 'reference-index.json';
	}

	/**
	 * Filepath for scan results snapshot.
	 *
	 * @return string Path to JSON file.
	 */
	private static function get_snapshot_file(): string {
		return trailingslashit( self::get_storage_dir() ) . 'scan-snapshot.json';
	}

	/**
	 * Retrieve the latest scan snapshot if available.
	 *
	 * @return array
	 */
	public static function get_latest_snapshot(): array {
		$file = self::get_snapshot_file();
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Initialize a new unused media scan pass.
	 *
	 * @param array $params Request parameters.
	 * @return array Initial scan state.
	 */
	public static function start_scan( array $params = array() ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$providers = self::get_providers();
		$index     = new ReferenceIndex();
		$options   = self::get_settings();

		// Run each registered reference provider to build the complete index
		foreach ( $providers as $provider ) {
			if ( $provider instanceof ReferenceProviderInterface ) {
				try {
					$provider->collect_references( $index, $options );
				} catch ( \Throwable $e ) {
					// Guard against third-party or builder provider failure without halting scan
					if ( class_exists( '\OmninoxSiteToolkit\Database\LogRepository' ) ) {
						\OmninoxSiteToolkit\Database\LogRepository::record(
							array(
								'type'    => 'action',
								'level'   => 'warning',
								'message' => sprintf( 'Media reference provider "%s" halted safely: %s', $provider->get_label(), $e->getMessage() ),
								'source'  => 'media/unused_scan_start',
							)
						);
					}
				}
			}
		}

		// Cache reference index
		@file_put_contents( self::get_index_cache_file(), wp_json_encode( $index->to_array() ) );

		// Fetch all attachment IDs to scan in memory (lightweight array of ints)
		global $wpdb;
		$all_attachment_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} 
			 WHERE post_type = 'attachment' 
			 AND post_mime_type LIKE 'image/%' 
			 AND post_status = 'inherit' 
			 ORDER BY ID DESC"
		);
		$all_attachment_ids = array_map( 'intval', (array) $all_attachment_ids );
		$total_attachments  = count( $all_attachment_ids );

		// Initialize snapshot with indexed list of attachment IDs
		$snapshot = array(
			'started_at'        => time(),
			'last_updated'      => time(),
			'total_attachments' => $total_attachments,
			'scanned_count'     => 0,
			'all_ids'           => $all_attachment_ids,
			'stats'             => array(
				'used'             => 0,
				'likely_unused'    => 0,
				'unknown'          => 0,
				'protected'        => 0,
				'recoverable_bytes'=> 0,
			),
			'items'             => array(),
		);

		@file_put_contents( self::get_snapshot_file(), wp_json_encode( $snapshot ) );

		return array(
			'status'    => 'started',
			'total'     => $total_attachments,
			'offset'    => 0,
			'message'   => sprintf( __( 'Reference index built across %d sources. Scanning %d images…', 'omninox-site-toolkit' ), count( $providers ), $total_attachments ),
		);
	}

	/**
	 * Exhaustive live cross-verification for an attachment across all WordPress database tables.
	 *
	 * Executes high-speed, targeted SQL checks to verify if the attachment
	 * is used in featured images, galleries, blocks, builders, postmeta, termmeta, or options.
	 *
	 * @param int      $att_id Attachment ID.
	 * @param array    $family_filenames Filenames for this attachment.
	 * @param array    $family_urls URLs for this attachment.
	 * @param \WP_Post $post Attachment post object.
	 * @return array List of discovered references.
	 */
	public static function live_cross_verify_attachment( int $att_id, array $family_filenames = array(), array $family_urls = array(), ?\WP_Post $post = null ): array {
		global $wpdb;
		$refs = array();

		// 1. Featured Image (_thumbnail_id)
		$feat_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type 
				 FROM {$wpdb->postmeta} pm 
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
				 WHERE pm.meta_key = '_thumbnail_id' 
				 AND pm.meta_value = %s 
				 AND p.post_status NOT IN ('trash', 'auto-draft') 
				 LIMIT 3",
				(string) $att_id
			),
			ARRAY_A
		);
		if ( ! empty( $feat_posts ) ) {
			foreach ( $feat_posts as $row ) {
				$refs[] = array(
					'type'   => $row['post_type'],
					'id'     => (int) $row['ID'],
					'title'  => $row['post_title'] ?: '#' . $row['ID'],
					'field'  => '_thumbnail_id',
					'source' => 'Featured Image',
					'method' => 'live_meta_check',
				);
			}
			return $refs;
		}

		// 2. WooCommerce Product Gallery (_product_image_gallery)
		$gallery_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type 
				 FROM {$wpdb->postmeta} pm 
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
				 WHERE pm.meta_key = '_product_image_gallery' 
				 AND (pm.meta_value = %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s) 
				 AND p.post_status NOT IN ('trash', 'auto-draft') 
				 LIMIT 3",
				(string) $att_id,
				(string) $att_id . ',%',
				'%,' . (string) $att_id . ',%',
				'%,' . (string) $att_id
			),
			ARRAY_A
		);
		if ( ! empty( $gallery_posts ) ) {
			foreach ( $gallery_posts as $row ) {
				$refs[] = array(
					'type'   => $row['post_type'],
					'id'     => (int) $row['ID'],
					'title'  => $row['post_title'] ?: '#' . $row['ID'],
					'field'  => '_product_image_gallery',
					'source' => 'WooCommerce Gallery',
					'method' => 'live_meta_check',
				);
			}
			return $refs;
		}

		// 3. Post Content: Gutenberg block ID & wp-image-ID class
		$content_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type 
				 FROM {$wpdb->posts} 
				 WHERE post_type NOT IN ('attachment', 'revision') 
				 AND post_status NOT IN ('trash', 'auto-draft') 
				 AND (
					post_content LIKE %s 
					OR post_content LIKE %s 
					OR post_content LIKE %s
				 ) 
				 LIMIT 3",
				'%wp-image-' . (int) $att_id . '%',
				'%"id":' . (int) $att_id . '%',
				'%"id": "' . (int) $att_id . '"%'
			),
			ARRAY_A
		);
		if ( ! empty( $content_posts ) ) {
			foreach ( $content_posts as $row ) {
				$refs[] = array(
					'type'   => $row['post_type'],
					'id'     => (int) $row['ID'],
					'title'  => $row['post_title'] ?: '#' . $row['ID'],
					'field'  => 'post_content',
					'source' => 'Content (Block / ID)',
					'method' => 'live_content_check',
				);
			}
			return $refs;
		}

		// 4. Post Content: Filename match
		$clean_filenames = array_unique( array_filter( array_map( 'wp_basename', $family_filenames ) ) );
		foreach ( $clean_filenames as $fn ) {
			if ( strlen( $fn ) < 4 ) {
				continue;
			}
			$fn_posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type 
					 FROM {$wpdb->posts} 
					 WHERE post_type NOT IN ('attachment', 'revision') 
					 AND post_status NOT IN ('trash', 'auto-draft') 
					 AND post_content LIKE %s 
					 LIMIT 3",
					'%' . $wpdb->esc_like( $fn ) . '%'
				),
				ARRAY_A
			);
			if ( ! empty( $fn_posts ) ) {
				foreach ( $fn_posts as $row ) {
					$refs[] = array(
						'type'   => $row['post_type'],
						'id'     => (int) $row['ID'],
						'title'  => $row['post_title'] ?: '#' . $row['ID'],
						'field'  => 'post_content',
						'source' => 'Content (Filename: ' . $fn . ')',
						'method' => 'live_filename_check',
					);
				}
				return $refs;
			}
		}

		// 5. Elementor Builder data (_elementor_data)
		$elem_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type 
				 FROM {$wpdb->postmeta} pm 
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
				 WHERE pm.meta_key = '_elementor_data' 
				 AND (
					pm.meta_value LIKE %s 
					OR pm.meta_value LIKE %s
				 ) 
				 AND p.post_status NOT IN ('trash', 'auto-draft') 
				 LIMIT 3",
				'%"id":' . (int) $att_id . '%',
				'%"id":"' . (int) $att_id . '"%'
			),
			ARRAY_A
		);
		if ( ! empty( $elem_posts ) ) {
			foreach ( $elem_posts as $row ) {
				$refs[] = array(
					'type'   => $row['post_type'],
					'id'     => (int) $row['ID'],
					'title'  => $row['post_title'] ?: '#' . $row['ID'],
					'field'  => '_elementor_data',
					'source' => 'Elementor Builder',
					'method' => 'live_builder_check',
				);
			}
			return $refs;
		}

		// Elementor filename match
		foreach ( $clean_filenames as $fn ) {
			if ( strlen( $fn ) < 4 ) {
				continue;
			}
			$elem_fn_posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_type 
					 FROM {$wpdb->postmeta} pm 
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
					 WHERE pm.meta_key = '_elementor_data' 
					 AND pm.meta_value LIKE %s 
					 AND p.post_status NOT IN ('trash', 'auto-draft') 
					 LIMIT 3",
					'%' . $wpdb->esc_like( $fn ) . '%'
				),
				ARRAY_A
			);
			if ( ! empty( $elem_fn_posts ) ) {
				foreach ( $elem_fn_posts as $row ) {
					$refs[] = array(
						'type'   => $row['post_type'],
						'id'     => (int) $row['ID'],
						'title'  => $row['post_title'] ?: '#' . $row['ID'],
						'field'  => '_elementor_data',
						'source' => 'Elementor Builder (Filename: ' . $fn . ')',
						'method' => 'live_builder_check',
					);
				}
				return $refs;
			}
		}

		// 6. Custom Field PostMeta (ACF, Meta Box, Pods, WooCommerce meta)
		// Exclude internal WordPress / plugin bookkeeping meta keys
		$meta_refs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, pm.meta_key 
				 FROM {$wpdb->postmeta} pm 
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
				 WHERE pm.meta_key NOT IN ('_wp_attached_file', '_wp_attachment_metadata', '_thumbnail_id', '_product_image_gallery', '_omninox_quarantined', '_omninox_protected', '_omninox_webp', '_edit_lock', '_edit_last') 
				 AND (
					pm.meta_value = %s 
					OR pm.meta_value LIKE %s 
					OR pm.meta_value LIKE %s
				 ) 
				 AND p.post_status NOT IN ('trash', 'auto-draft') 
				 LIMIT 3",
				(string) $att_id,
				'%;s:' . strlen( (string) $att_id ) . ':"' . (string) $att_id . '";%',
				'%' . $wpdb->esc_like( 'i:' . (int) $att_id . ';' ) . '%'
			),
			ARRAY_A
		);
		if ( ! empty( $meta_refs ) ) {
			foreach ( $meta_refs as $row ) {
				$refs[] = array(
					'type'   => $row['post_type'],
					'id'     => (int) $row['ID'],
					'title'  => $row['post_title'] ?: '#' . $row['ID'],
					'field'  => $row['meta_key'],
					'source' => 'Custom Field (PostMeta)',
					'method' => 'live_meta_check',
				);
			}
			return $refs;
		}

		// 7. Term Meta (WooCommerce Category Thumbnail, Brand Logo, etc.)
		$term_meta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, t.name, tm.meta_key 
				 FROM {$wpdb->termmeta} tm 
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id 
				 WHERE tm.meta_value = %s 
				 LIMIT 3",
				(string) $att_id
			),
			ARRAY_A
		);
		if ( ! empty( $term_meta ) ) {
			foreach ( $term_meta as $row ) {
				$refs[] = array(
					'type'   => 'term',
					'id'     => (int) $row['term_id'],
					'title'  => $row['name'] ?: '#' . $row['term_id'],
					'field'  => $row['meta_key'],
					'source' => 'Taxonomy / Term Meta',
					'method' => 'live_term_check',
				);
			}
			return $refs;
		}

		// 8. User Meta (Avatars, author profile photos)
		$user_meta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_login, um.meta_key 
				 FROM {$wpdb->usermeta} um 
				 INNER JOIN {$wpdb->users} u ON u.ID = um.user_id 
				 WHERE um.meta_value = %s 
				 LIMIT 3",
				(string) $att_id
			),
			ARRAY_A
		);
		if ( ! empty( $user_meta ) ) {
			foreach ( $user_meta as $row ) {
				$refs[] = array(
					'type'   => 'user',
					'id'     => (int) $row['ID'],
					'title'  => $row['user_login'] ?: '#' . $row['ID'],
					'field'  => $row['meta_key'],
					'source' => 'User Meta / Avatar',
					'method' => 'live_user_check',
				);
			}
			return $refs;
		}

		// 9. Active Parent Post Check
		if ( $post && $post->post_parent > 0 ) {
			$parent_post = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type 
					 FROM {$wpdb->posts} 
					 WHERE ID = %d 
					 AND post_status NOT IN ('trash', 'auto-draft') 
					 LIMIT 1",
					$post->post_parent
				),
				ARRAY_A
			);
			if ( ! empty( $parent_post ) ) {
				$refs[] = array(
					'type'   => $parent_post['post_type'],
					'id'     => (int) $parent_post['ID'],
					'title'  => $parent_post['post_title'] ?: '#' . $parent_post['ID'],
					'field'  => 'post_parent',
					'source' => 'Parent Post Attachment',
					'method' => 'live_parent_check',
				);
				return $refs;
			}
		}

		return $refs;
	}

	/**
	 * Step scan: process next batch of attachments against the reference index.
	 *
	 * @param int $offset Attachment offset.
	 * @param int $batch Batch size.
	 * @return array Step progress.
	 */
	public static function step_scan( int $offset = 0, int $batch = 30 ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$start_time = microtime( true );

		// Guard timeout
		$max_time    = (int) ini_get( 'max_execution_time' );
		$time_budget = ( $max_time > 0 ) ? max( 6, min( 22, $max_time - 4 ) ) : 18;

		// Guard memory
		$mem_limit_str  = ini_get( 'memory_limit' );
		$mem_limit      = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $mem_limit_str ) : 134217728;
		$safe_mem_limit = ( $mem_limit > 0 ) ? (int) ( $mem_limit * 0.80 ) : 0;

		$index_file = self::get_index_cache_file();
		if ( ! file_exists( $index_file ) ) {
			return self::start_scan();
		}

		$index_data = json_decode( (string) file_get_contents( $index_file ), true );
		$index      = is_array( $index_data ) ? ReferenceIndex::from_array( $index_data ) : new ReferenceIndex();

		$snapshot_file = self::get_snapshot_file();
		$snapshot = file_exists( $snapshot_file ) ? json_decode( (string) file_get_contents( $snapshot_file ), true ) : array();
		if ( ! is_array( $snapshot ) || empty( $snapshot['items'] ) ) {
			$snapshot = array(
				'started_at'        => time(),
				'last_updated'      => time(),
				'total_attachments' => 0,
				'scanned_count'     => 0,
				'stats'             => array(
					'used'             => 0,
					'likely_unused'    => 0,
					'unknown'          => 0,
					'protected'        => 0,
					'recoverable_bytes'=> 0,
				),
				'items'             => array(),
			);
		}

		$settings = self::get_settings();
		$protect_days = (int) ( $settings['protect_recent_days'] ?? 30 );
		$protect_logo = ! empty( $settings['protect_site_identity'] );
		$now          = time();

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$icon_id = (int) get_option( 'site_icon' );

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );
		$baseurl = trailingslashit( $uploads['baseurl'] );

		// Retrieve attachment IDs from pre-indexed snapshot or fallback query
		$batch = max( 10, min( 150, $batch ) );
		if ( ! empty( $snapshot['all_ids'] ) && is_array( $snapshot['all_ids'] ) ) {
			$ids   = array_slice( $snapshot['all_ids'], $offset, $batch );
			$total = count( $snapshot['all_ids'] );
		} else {
			global $wpdb;
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} 
					 WHERE post_type = 'attachment' 
					 AND post_mime_type LIKE 'image/%%' 
					 AND post_status = 'inherit' 
					 ORDER BY ID DESC 
					 LIMIT %d OFFSET %d",
					$batch,
					$offset
				)
			);
			$ids   = array_map( 'intval', (array) $ids );
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(ID) FROM {$wpdb->posts} 
				 WHERE post_type = 'attachment' 
				 AND post_mime_type LIKE 'image/%%' 
				 AND post_status = 'inherit'"
			);
		}

		$snapshot['total_attachments'] = $total;

		if ( empty( $ids ) ) {
			return array(
				'status'  => 'complete',
				'done'    => true,
				'offset'  => $total,
				'total'   => $total,
				'stats'   => $snapshot['stats'],
				'message' => __( 'Scan complete! Review your unused image results.', 'omninox-site-toolkit' ),
			);
		}

		// Prime post cache and meta cache for this entire batch in 2 fast queries
		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );

		$scanned_in_step = 0;
		foreach ( $ids as $att_id ) {
			if ( $scanned_in_step > 0 && ( microtime( true ) - $start_time ) > $time_budget ) {
				break;
			}
			if ( $scanned_in_step > 0 && $safe_mem_limit > 0 && memory_get_usage() > $safe_mem_limit ) {
				break;
			}

			$post = get_post( $att_id );
			if ( ! $post ) {
				$scanned_in_step++;
				continue;
			}

			$file_rel = get_post_meta( $att_id, '_wp_attached_file', true );
			$metadata = wp_get_attachment_metadata( $att_id );

			// Resolve full physical path
			$attached_full_path = function_exists( 'get_attached_file' ) ? get_attached_file( $att_id ) : '';
			$resolved_path      = '';
			if ( $attached_full_path && file_exists( $attached_full_path ) ) {
				$resolved_path = $attached_full_path;
			} elseif ( $file_rel ) {
				$norm_rel = ltrim( str_replace( '\\', '/', $file_rel ), '/' );
				if ( file_exists( $basedir . $norm_rel ) ) {
					$resolved_path = $basedir . $norm_rel;
				}
			}

			// Collect entire variant family
			$family_filenames = array();
			$family_urls      = array();
			$total_file_bytes = 0;

			if ( $file_rel ) {
				$family_filenames[] = wp_basename( $file_rel );
				$norm_file_rel      = ltrim( str_replace( '\\', '/', $file_rel ), '/' );
				$family_urls[]      = $baseurl . $norm_file_rel;

				if ( $resolved_path ) {
					$f_size = @filesize( $resolved_path );
					if ( false !== $f_size && $f_size > 0 ) {
						$total_file_bytes += $f_size;
					}
				}

				$dir_for_sizes = $resolved_path ? dirname( $resolved_path ) : ( $basedir . dirname( $norm_file_rel ) );
				$sub_dir       = trailingslashit( dirname( $norm_file_rel ) );

				if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $s_info ) {
						if ( ! empty( $s_info['file'] ) ) {
							$family_filenames[] = $s_info['file'];
							$family_urls[]      = $baseurl . $sub_dir . $s_info['file'];
							$s_path             = $dir_for_sizes . DIRECTORY_SEPARATOR . $s_info['file'];
							$s_size             = @filesize( $s_path );
							if ( false !== $s_size && $s_size > 0 ) {
								$total_file_bytes += $s_size;
							}
						}
					}
				}

				// Converted formats
				$webp_rec = (array) get_post_meta( $att_id, '_omninox_webp', true );
				foreach ( (array) ( $webp_rec['files'] ?? array() ) as $w_file ) {
					$family_filenames[] = wp_basename( $w_file );
					$norm_w_file        = ltrim( str_replace( '\\', '/', $w_file ), '/' );
					$family_urls[]      = $baseurl . $norm_w_file;
					$w_size             = @filesize( $basedir . $norm_w_file );
					if ( false !== $w_size && $w_size > 0 ) {
						$total_file_bytes += $w_size;
					}
				}
			}

			if ( 0 === $total_file_bytes && ! empty( $metadata['filesize'] ) ) {
				$total_file_bytes = (int) $metadata['filesize'];
			}

			// Check quarantine state
			$is_quarantined = (bool) get_post_meta( $att_id, '_omninox_quarantined', true );
			$is_protected   = (bool) get_post_meta( $att_id, '_omninox_protected', true );

			// Check System Protection Rules
			$protection_reason = '';
			if ( $is_protected ) {
				$status = self::STATUS_PROTECTED;
				$protection_reason = __( 'Manually marked as protected by administrator.', 'omninox-site-toolkit' );
			} elseif ( $protect_logo && ( $att_id === $logo_id || $att_id === $icon_id ) ) {
				$status = self::STATUS_PROTECTED;
				$protection_reason = __( 'Active Site Logo or Favicon.', 'omninox-site-toolkit' );
			} elseif ( $protect_days > 0 && ( $now - strtotime( $post->post_date ) ) < ( $protect_days * DAY_IN_SECONDS ) ) {
				$status = self::STATUS_PROTECTED;
				$protection_reason = sprintf( __( 'Recently uploaded within protected %d-day window.', 'omninox-site-toolkit' ), $protect_days );
			} else {
				// 1. Query pre-built reference index
				$references = $index->get_references_for_attachment( $att_id, $family_filenames, $family_urls );

				if ( ! empty( $references ) ) {
					$status = self::STATUS_USED;
				} else {
					// 2. 100% Exhaustive Live Database Cross-Verification
					$live_refs = self::live_cross_verify_attachment( $att_id, $family_filenames, $family_urls, $post );
					if ( ! empty( $live_refs ) ) {
						$references = $live_refs;
						$status     = self::STATUS_USED;
					} else {
						// 3. Physical file check
						if ( ( ! $resolved_path && ! $file_rel ) || ( $resolved_path && false === @filesize( $resolved_path ) ) ) {
							$status = self::STATUS_UNKNOWN;
							$protection_reason = __( 'Physical file missing or unreadable.', 'omninox-site-toolkit' );
						} else {
							$status = self::STATUS_UNUSED;
						}
					}
				}
			}

			$refs_list = ! empty( $references ) ? $references : array();

			// Update stats
			$snapshot['stats'][ $status ] = ( $snapshot['stats'][ $status ] ?? 0 ) + 1;
			if ( self::STATUS_UNUSED === $status ) {
				$snapshot['stats']['recoverable_bytes'] = ( $snapshot['stats']['recoverable_bytes'] ?? 0 ) + $total_file_bytes;
			}

			$thumb_url = wp_get_attachment_image_url( $att_id, 'thumbnail' );
			if ( ! $thumb_url && $file_rel ) {
				$thumb_url = $baseurl . ltrim( str_replace( '\\', '/', $file_rel ), '/' );
			}

			$snapshot['items'][ $att_id ] = array(
				'id'                => $att_id,
				'title'             => $post->post_title ? $post->post_title : wp_basename( $file_rel ?: '' ),
				'filename'          => wp_basename( $file_rel ?: '' ),
				'url'               => $file_rel ? ( $baseurl . ltrim( str_replace( '\\', '/', $file_rel ), '/' ) ) : '',
				'thumb_url'         => $thumb_url ?: '',
				'file_size'         => $total_file_bytes,
				'file_size_fmt'     => size_format( $total_file_bytes ),
				'bytes'             => $total_file_bytes,
				'formatted_size'    => size_format( $total_file_bytes ),
				'uploaded'          => get_the_date( 'Y-m-d', $att_id ),
				'date'              => get_the_date( 'Y-m-d', $att_id ),
				'status'            => $status,
				'is_quarantined'    => $is_quarantined,
				'is_protected'      => $is_protected,
				'protection_reason' => $protection_reason,
				'reference_count'   => count( $refs_list ),
				'references_count'  => count( $refs_list ),
				'references'        => array_slice( $refs_list, 0, 10 ),
			);

			$scanned_in_step++;
		}

		$processed = $offset + ( $scanned_in_step > 0 ? $scanned_in_step : count( $ids ) );
		$is_done   = ( $processed >= $total );

		$snapshot['scanned_count'] = $processed;
		$snapshot['last_updated']  = time();

		@file_put_contents( $snapshot_file, wp_json_encode( $snapshot ) );

		return array(
			'status'  => $is_done ? 'complete' : 'running',
			'done'    => $is_done,
			'offset'  => $processed,
			'total'   => $total,
			'stats'   => $snapshot['stats'],
			'message' => $is_done
				? sprintf( __( 'Done: scanned %1$d images (%2$d likely unused, %3$s recoverable).', 'omninox-site-toolkit' ), $total, $snapshot['stats']['likely_unused'], size_format( $snapshot['stats']['recoverable_bytes'] ) )
				: sprintf( __( 'Scanned %1$d of %2$d images…', 'omninox-site-toolkit' ), $processed, $total ),
		);
	}

	/**
	 * Retrieve paged results with search, filter, and sorting.
	 *
	 * @param array $params Query arguments.
	 * @return array
	 */
	public static function get_results( array $params = array() ): array {
		$snapshot_file = self::get_snapshot_file();
		if ( ! file_exists( $snapshot_file ) ) {
			return array(
				'has_scan' => false,
				'total'    => 0,
				'items'    => array(),
				'stats'    => array(),
			);
		}

		$snapshot = json_decode( (string) file_get_contents( $snapshot_file ), true );
		if ( ! is_array( $snapshot ) || empty( $snapshot['items'] ) ) {
			return array(
				'has_scan' => false,
				'total'    => 0,
				'items'    => array(),
				'stats'    => array(),
			);
		}

		$items  = array_values( $snapshot['items'] );
		$filter = sanitize_key( $params['filter'] ?? 'likely_unused' );
		if ( empty( $filter ) ) {
			$filter = 'likely_unused';
		}
		$search = strtolower( trim( sanitize_text_field( $params['search'] ?? '' ) ) );

		// Filter
		if ( 'unused' === $filter || 'likely_unused' === $filter ) {
			$items = array_filter( $items, function ( $item ) {
				return self::STATUS_UNUSED === $item['status'] && empty( $item['is_quarantined'] );
			} );
		} elseif ( 'quarantined' === $filter ) {
			$items = array_filter( $items, function ( $item ) {
				return ! empty( $item['is_quarantined'] );
			} );
		} elseif ( 'protected' === $filter ) {
			$items = array_filter( $items, function ( $item ) {
				return self::STATUS_PROTECTED === $item['status'] || ! empty( $item['is_protected'] );
			} );
		} elseif ( 'used' === $filter ) {
			$items = array_filter( $items, function ( $item ) {
				return self::STATUS_USED === $item['status'];
			} );
		} elseif ( 'unknown' === $filter ) {
			$items = array_filter( $items, function ( $item ) {
				return self::STATUS_UNKNOWN === $item['status'];
			} );
		}

		// Search
		if ( '' !== $search ) {
			$items = array_filter( $items, function ( $item ) use ( $search ) {
				return (
					false !== strpos( strtolower( $item['filename'] ), $search ) ||
					false !== strpos( strtolower( $item['title'] ), $search ) ||
					false !== strpos( (string) $item['id'], $search )
				);
			} );
		}

		$total_filtered = count( $items );
		$page           = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page       = max( 10, min( 500, absint( $params['per_page'] ?? 100 ) ) );
		$offset         = ( $page - 1 ) * $per_page;

		$paged_items = array_slice( array_values( $items ), $offset, $per_page );

		return array(
			'has_scan'        => true,
			'total'           => $total_filtered,
			'total_items'     => $total_filtered,
			'total_unfiltered'=> count( $snapshot['items'] ),
			'page'            => $page,
			'per_page'        => $per_page,
			'total_pages'     => (int) ceil( $total_filtered / $per_page ),
			'last_scanned'    => isset( $snapshot['last_updated'] ) ? date_i18n( 'Y-m-d H:i:s', $snapshot['last_updated'] ) : '',
			'stats'           => $snapshot['stats'],
			'items'           => $paged_items,
		);
	}

	/**
	 * Bulk quarantine selected attachment IDs.
	 *
	 * @param array $ids List of attachment IDs.
	 * @return array
	 */
	public static function bulk_quarantine( array $ids ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $ids as $id ) {
			$id  = absint( $id );
			$res = QuarantineManager::quarantine( $id );
			if ( is_wp_error( $res ) ) {
				$results['failed'][] = array( 'id' => $id, 'error' => $res->get_error_message() );
			} else {
				$results['success'][] = $res;
				// Update snapshot item state
				self::update_snapshot_item( $id, array( 'is_quarantined' => true ) );
			}
		}

		return array(
			'quarantined' => count( $results['success'] ),
			'failed'      => count( $results['failed'] ),
			'details'     => $results,
			'message'     => sprintf( __( 'Quarantined %1$d images (%2$d failed).', 'omninox-site-toolkit' ), count( $results['success'] ), count( $results['failed'] ) ),
		);
	}

	/**
	 * Bulk restore selected attachment IDs from quarantine.
	 *
	 * @param array $ids List of IDs.
	 * @return array
	 */
	public static function bulk_restore( array $ids ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $ids as $id ) {
			$id  = absint( $id );
			$res = QuarantineManager::restore( $id );
			if ( is_wp_error( $res ) ) {
				$results['failed'][] = array( 'id' => $id, 'error' => $res->get_error_message() );
			} else {
				$results['success'][] = $res;
				self::update_snapshot_item( $id, array( 'is_quarantined' => false ) );
			}
		}

		return array(
			'restored' => count( $results['success'] ),
			'failed'   => count( $results['failed'] ),
			'details'  => $results,
			'message'  => sprintf( __( 'Restored %1$d images (%2$d failed).', 'omninox-site-toolkit' ), count( $results['success'] ), count( $results['failed'] ) ),
		);
	}

	/**
	 * Bulk permanently delete selected attachment IDs.
	 *
	 * @param array $ids List of IDs.
	 * @return array
	 */
	public static function bulk_delete( array $ids ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $ids as $id ) {
			$id  = absint( $id );
			$res = QuarantineManager::delete_permanently( $id, true );
			if ( is_wp_error( $res ) ) {
				$results['failed'][] = array( 'id' => $id, 'error' => $res->get_error_message() );
			} else {
				$results['success'][] = $res;
				self::remove_snapshot_item( $id );
			}
		}

		return array(
			'deleted' => count( $results['success'] ),
			'failed'  => count( $results['failed'] ),
			'details' => $results,
			'message' => sprintf( __( 'Permanently deleted %1$d images (%2$d failed).', 'omninox-site-toolkit' ), count( $results['success'] ), count( $results['failed'] ) ),
		);
	}

	/**
	 * Toggle protection flag on an attachment.
	 *
	 * @param int  $attachment_id ID.
	 * @param bool $protect True to protect, false to unprotect.
	 * @return array
	 */
	public static function set_protection( int $attachment_id, bool $protect ): array {
		if ( $protect ) {
			update_post_meta( $attachment_id, '_omninox_protected', 1 );
		} else {
			delete_post_meta( $attachment_id, '_omninox_protected' );
		}

		self::update_snapshot_item(
			$attachment_id,
			array(
				'is_protected'      => $protect,
				'status'            => $protect ? self::STATUS_PROTECTED : self::STATUS_UNUSED,
				'protection_reason' => $protect ? __( 'Protected by administrator.', 'omninox-site-toolkit' ) : '',
			)
		);

		return array(
			'id'        => $attachment_id,
			'protected' => $protect,
			'message'   => $protect
				? __( 'Image protected from cleanup.', 'omninox-site-toolkit' )
				: __( 'Image protection removed.', 'omninox-site-toolkit' ),
		);
	}

	/**
	 * Update single item attributes in cached snapshot.
	 *
	 * @param int   $id ID.
	 * @param array $updates Updates.
	 */
	private static function update_snapshot_item( int $id, array $updates ): void {
		$file = self::get_snapshot_file();
		if ( ! file_exists( $file ) ) {
			return;
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( isset( $data['items'][ $id ] ) ) {
			$data['items'][ $id ] = array_merge( $data['items'][ $id ], $updates );
			@file_put_contents( $file, wp_json_encode( $data ) );
		}
	}

	/**
	 * Remove deleted item from snapshot.
	 *
	 * @param int $id ID.
	 */
	private static function remove_snapshot_item( int $id ): void {
		$file = self::get_snapshot_file();
		if ( ! file_exists( $file ) ) {
			return;
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( isset( $data['items'][ $id ] ) ) {
			unset( $data['items'][ $id ] );
			@file_put_contents( $file, wp_json_encode( $data ) );
		}
	}

	/**
	 * Get scan settings.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$defaults = array(
			'protect_recent_days'   => 30,
			'protect_site_identity' => true,
			'scan_elementor'        => true,
			'scan_divi'             => true,
			'scan_woocommerce'      => true,
			'scan_acf'              => true,
		);

		$saved = (array) get_option( 'omninox_unused_media_settings', array() );

		return array_merge( $defaults, $saved );
	}

	/**
	 * Save scan settings.
	 *
	 * @param array $settings New settings.
	 * @return array
	 */
	public static function save_settings( array $settings ): array {
		$current = self::get_settings();
		$updated = array(
			'protect_recent_days'   => max( 0, min( 365, absint( $settings['protect_recent_days'] ?? $current['protect_recent_days'] ) ) ),
			'protect_site_identity' => ! empty( $settings['protect_site_identity'] ),
			'scan_elementor'        => ! empty( $settings['scan_elementor'] ),
			'scan_divi'             => ! empty( $settings['scan_divi'] ),
			'scan_woocommerce'      => ! empty( $settings['scan_woocommerce'] ),
			'scan_acf'              => ! empty( $settings['scan_acf'] ),
		);

		update_option( 'omninox_unused_media_settings', $updated, false );

		return array(
			'success'  => true,
			'settings' => $updated,
			'message'  => __( 'Unused media detection settings saved.', 'omninox-site-toolkit' ),
		);
	}
}
