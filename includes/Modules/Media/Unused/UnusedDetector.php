<?php
/**
 * Smart Unused Media Detector (Ultra-Fast Engine).
 *
 * Core orchestrator for multi-stage reference extraction, database-backed indexing,
 * variant tracking, adaptive batch processing, and safe bulk cleanup.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused;

use OmninoxSiteToolkit\Database\Schema;
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

	// Multi-stage scan phases
	const PHASE_DISCOVERY          = 'discovery';
	const PHASE_META               = 'indexing_meta';
	const PHASE_CONTENT            = 'indexing_content';
	const PHASE_BUILDERS           = 'indexing_builders';
	const PHASE_TAXONOMIES_THEMES  = 'indexing_taxonomies_themes';
	const PHASE_MATCHING           = 'matching_attachments';
	const PHASE_COMPLETE           = 'complete';

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
	 * Filepath for scan results legacy snapshot (for backward compatibility).
	 *
	 * @return string Path to JSON file.
	 */
	private static function get_snapshot_file(): string {
		return trailingslashit( self::get_storage_dir() ) . 'scan-snapshot.json';
	}

	/**
	 * Human-readable label for scan phases.
	 *
	 * @param string $phase Phase key.
	 * @return string
	 */
	public static function get_phase_label( string $phase ): string {
		switch ( $phase ) {
			case self::PHASE_DISCOVERY:
				return __( 'Discovering Media & Reference Sources…', 'omninox-site-toolkit' );
			case self::PHASE_META:
				return __( 'Indexing Post & Product Metadata…', 'omninox-site-toolkit' );
			case self::PHASE_CONTENT:
				return __( 'Indexing WordPress Content & Blocks…', 'omninox-site-toolkit' );
			case self::PHASE_BUILDERS:
				return __( 'Indexing Elementor & Page Builder Templates…', 'omninox-site-toolkit' );
			case self::PHASE_TAXONOMIES_THEMES:
				return __( 'Indexing Themes, Menus, Widgets & Terms…', 'omninox-site-toolkit' );
			case self::PHASE_MATCHING:
				return __( 'Cross-Verifying Attachments Against Index…', 'omninox-site-toolkit' );
			case self::PHASE_COMPLETE:
				return __( 'Scan Complete! Review Unused Media.', 'omninox-site-toolkit' );
			default:
				return __( 'Scanning Website Media References…', 'omninox-site-toolkit' );
		}
	}

	/**
	 * Retrieve active or latest scan state from database.
	 *
	 * @param string|null $scan_id Optional scan ID.
	 * @return array
	 */
	public static function get_scan_state( ?string $scan_id = null ): array {
		global $wpdb;
		$table = Schema::table( 'media_scan_state' );

		if ( $scan_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id = %s LIMIT 1", $scan_id ), ARRAY_A );
		} else {
			$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
		}

		if ( empty( $row ) ) {
			return array(
				'scan_id'            => '',
				'mode'               => 'full',
				'status'             => 'idle',
				'current_phase'      => self::PHASE_DISCOVERY,
				'cursor_id'          => 0,
				'processed_count'    => 0,
				'total_count'        => 0,
				'references_indexed' => 0,
				'generation'         => 0,
				'phase_state'        => array(),
				'stats'              => array(
					'used'              => 0,
					'likely_unused'     => 0,
					'unknown'           => 0,
					'protected'         => 0,
					'recoverable_bytes' => 0,
				),
			);
		}

		$row['phase_state'] = ! empty( $row['phase_state'] ) ? json_decode( $row['phase_state'], true ) : array();
		$row['stats']       = ! empty( $row['stats'] ) ? json_decode( $row['stats'], true ) : array(
			'used'              => 0,
			'likely_unused'     => 0,
			'unknown'           => 0,
			'protected'         => 0,
			'recoverable_bytes' => 0,
		);

		return $row;
	}

	/**
	 * Persist scan state to database.
	 *
	 * @param array $state Scan state array.
	 */
	public static function save_scan_state( array $state ): void {
		global $wpdb;
		$table = Schema::table( 'media_scan_state' );

		$scan_id = sanitize_key( $state['scan_id'] ?? '' );
		if ( '' === $scan_id ) {
			return;
		}

		$data = array(
			'scan_id'            => $scan_id,
			'mode'               => sanitize_key( $state['mode'] ?? 'full' ),
			'status'             => sanitize_key( $state['status'] ?? 'running' ),
			'current_phase'      => sanitize_key( $state['current_phase'] ?? self::PHASE_DISCOVERY ),
			'cursor_id'          => absint( $state['cursor_id'] ?? 0 ),
			'processed_count'    => absint( $state['processed_count'] ?? 0 ),
			'total_count'        => absint( $state['total_count'] ?? 0 ),
			'references_indexed' => absint( $state['references_indexed'] ?? 0 ),
			'generation'         => absint( $state['generation'] ?? time() ),
			'phase_state'        => wp_json_encode( (array) ( $state['phase_state'] ?? array() ) ),
			'stats'              => wp_json_encode( (array) ( $state['stats'] ?? array() ) ),
			'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			'error_count'        => absint( $state['error_count'] ?? 0 ),
		);

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE scan_id = %s", $scan_id ) );
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ) );
		} else {
			$data['started_at'] = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert( $table, $data );
		}
	}

	/**
	 * Initialize a new multi-stage unused media scan.
	 *
	 * @param array $params Request parameters (mode: 'full' | 'quick').
	 * @return array Initial scan state.
	 */
	public static function start_scan( array $params = array() ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		// Ensure high-performance tables are installed
		Schema::install_media_tables();

		global $wpdb;
		$mode = ( isset( $params['mode'] ) && 'quick' === $params['mode'] ) ? 'quick' : 'full';
		$generation = time();
		$scan_id = 'scan_' . $generation . '_' . wp_generate_password( 6, false, false );

		// Discovery counts
		$total_attachments = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} 
			 WHERE post_type = 'attachment' 
			 AND post_mime_type LIKE 'image/%' 
			 AND post_status = 'inherit'"
		);

		$total_posts = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} 
			 WHERE post_type NOT IN ('attachment', 'revision') 
			 AND post_status IN ('publish','draft','future','private','pending','inherit')"
		);

		$total_meta = (int) $wpdb->get_var(
			"SELECT COUNT(meta_id) FROM {$wpdb->postmeta} 
			 WHERE meta_key IN ('_thumbnail_id', '_product_image_gallery') 
			 OR meta_key NOT LIKE '\\_%'"
		);

		$total_builders = (int) $wpdb->get_var(
			"SELECT COUNT(meta_id) FROM {$wpdb->postmeta} 
			 WHERE meta_key IN ('_elementor_data', '_et_builder_custom_post_content', '_fl_builder_data', '_ct_builder_json')"
		);

		$total_terms = (int) $wpdb->get_var( "SELECT COUNT(meta_id) FROM {$wpdb->termmeta}" );

		// Clean up old references if full scan
		if ( 'full' === $mode ) {
			$ref_table = Schema::table( 'media_references' );
			$items_table = Schema::table( 'media_items' );
			$wpdb->query( "TRUNCATE TABLE {$ref_table}" );
			$wpdb->query( "TRUNCATE TABLE {$items_table}" );
		}

		$total_sources = $total_meta + $total_posts + $total_builders + $total_terms + $total_attachments;

		$initial_state = array(
			'scan_id'            => $scan_id,
			'mode'               => $mode,
			'status'             => 'running',
			'current_phase'      => self::PHASE_META,
			'cursor_id'          => 0,
			'processed_count'    => 0,
			'total_count'        => $total_sources,
			'references_indexed' => 0,
			'generation'         => $generation,
			'phase_state'        => array(
				'attachments_count' => $total_attachments,
				'posts_count'       => $total_posts,
				'meta_count'        => $total_meta,
				'builders_count'    => $total_builders,
				'terms_count'       => $total_terms,
				'phase_processed'   => 0,
			),
			'stats'              => array(
				'total'             => $total_attachments,
				'used'              => 0,
				'likely_unused'     => 0,
				'unknown'           => 0,
				'protected'         => 0,
				'recoverable_bytes' => 0,
			),
		);

		self::save_scan_state( $initial_state );

		return array(
			'status'             => 'running',
			'scan_id'            => $scan_id,
			'phase'              => self::PHASE_META,
			'phase_label'        => self::get_phase_label( self::PHASE_META ),
			'progress'           => 5,
			'images_discovered'  => $total_attachments,
			'sources_processed'  => 0,
			'sources_total'      => $total_sources,
			'references_indexed' => 0,
			'message'            => sprintf( __( 'Discovered %d media images across %d site sources. Starting reference extraction…', 'omninox-site-toolkit' ), $total_attachments, $total_sources ),
		);
	}

	/**
	 * Execute the next batch step across multi-stage scanning pipeline.
	 *
	 * Uses keyset pagination, database reference buffering, and adaptive time budgeting.
	 *
	 * @param int $offset Unused (persisted cursor is used instead).
	 * @param int $batch Batch size.
	 * @return array Step progress.
	 */
	public static function step_scan( int $offset = 0, int $batch = 100 ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		global $wpdb;
		$start_time = microtime( true );

		// Time budget: 8 to 14 seconds
		$max_time    = (int) ini_get( 'max_execution_time' );
		$time_budget = ( $max_time > 0 ) ? max( 5, min( 14, $max_time - 3 ) ) : 10;

		// Memory budget: 80%
		$mem_limit_str  = ini_get( 'memory_limit' );
		$mem_limit      = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $mem_limit_str ) : 134217728;
		$safe_mem_limit = ( $mem_limit > 0 ) ? (int) ( $mem_limit * 0.80 ) : 0;

		$state = self::get_scan_state();
		if ( empty( $state['scan_id'] ) || 'running' !== $state['status'] ) {
			if ( 'complete' === $state['status'] ) {
				return array(
					'status'             => 'complete',
					'done'               => true,
					'progress'           => 100,
					'phase'              => self::PHASE_COMPLETE,
					'phase_label'        => self::get_phase_label( self::PHASE_COMPLETE ),
					'images_discovered'  => $state['stats']['total'] ?? 0,
					'sources_processed'  => $state['total_count'] ?? 0,
					'sources_total'      => $state['total_count'] ?? 0,
					'references_indexed' => $state['references_indexed'] ?? 0,
					'stats'              => $state['stats'],
					'message'            => __( 'Scan complete! Review your unused media results.', 'omninox-site-toolkit' ),
				);
			}
			return self::start_scan();
		}

		$index = new ReferenceIndex();
		$index->set_scan_generation( (int) ( $state['generation'] ?? time() ) );

		$phase        = $state['current_phase'];
		$cursor       = (int) ( $state['cursor_id'] ?? 0 );
		$generation   = (int) ( $state['generation'] ?? time() );
		$phase_state  = (array) ( $state['phase_state'] ?? array() );
		$batch        = max( 50, min( 500, $batch ) );

		// Process loop while within time and memory budget
		while ( ( microtime( true ) - $start_time ) < $time_budget ) {
			if ( $safe_mem_limit > 0 && memory_get_usage() > $safe_mem_limit ) {
				break;
			}

			// =========================================================================
			// PHASE 1: Indexing PostMeta (_thumbnail_id, galleries, custom fields)
			// =========================================================================
			if ( self::PHASE_META === $phase ) {
				$meta_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, post_id, meta_key, meta_value 
						 FROM {$wpdb->postmeta} 
						 WHERE meta_id > %d 
						 AND (
							meta_key IN ('_thumbnail_id', '_product_image_gallery') 
							OR (meta_key NOT LIKE '\\_%' AND meta_value != '')
						 ) 
						 ORDER BY meta_id ASC 
						 LIMIT %d",
						$cursor,
						$batch * 4
					),
					ARRAY_A
				);

				if ( ! empty( $meta_rows ) ) {
					foreach ( $meta_rows as $row ) {
						$cursor  = (int) $row['meta_id'];
						$post_id = (int) $row['post_id'];
						$key     = $row['meta_key'];
						$val     = $row['meta_value'];

						if ( '_thumbnail_id' === $key ) {
							$att_id = (int) $val;
							if ( $att_id > 0 ) {
								$index->add_reference( $att_id, 'post', $post_id, 'core', '_thumbnail_id', '', 'featured_image' );
							}
						} elseif ( '_product_image_gallery' === $key ) {
							foreach ( explode( ',', $val ) as $piece ) {
								$att_id = (int) trim( $piece );
								if ( $att_id > 0 ) {
									$index->add_reference( $att_id, 'product', $post_id, 'woocommerce', '_product_image_gallery', '', 'gallery' );
								}
							}
						} else {
							// Check if scalar numeric ID or contains attachment ID
							if ( is_numeric( $val ) && (int) $val > 0 ) {
								$index->add_reference( (int) $val, 'post', $post_id, 'postmeta', $key, '', 'custom_field' );
							} elseif ( is_string( $val ) && strlen( $val ) > 4 ) {
								foreach ( ReferenceNormalizer::extract_attachment_ids( $val ) as $att_id ) {
									$index->add_reference( $att_id, 'post', $post_id, 'postmeta', $key, '', 'custom_field_embed' );
								}
								foreach ( ReferenceNormalizer::extract_image_urls( $val ) as $url ) {
									$index->add_reference_by_url( $url, 'post', $post_id, 'postmeta', $key, 'custom_field_url' );
								}
							}
						}
					}
					$state['processed_count'] += count( $meta_rows );
				} else {
					// Phase 1 finished -> Advance to Content
					$phase  = self::PHASE_CONTENT;
					$cursor = 0;
					break;
				}
			}

			// =========================================================================
			// PHASE 2: Indexing Post Content (Gutenberg blocks, HTML images, uploads)
			// =========================================================================
			elseif ( self::PHASE_CONTENT === $phase ) {
				$posts = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_type, post_content, post_excerpt 
						 FROM {$wpdb->posts} 
						 WHERE ID > %d 
						 AND post_type NOT IN ('attachment', 'revision') 
						 AND post_status IN ('publish','draft','future','private','pending','inherit') 
						 ORDER BY ID ASC 
						 LIMIT %d",
						$cursor,
						$batch
					),
					ARRAY_A
				);

				if ( ! empty( $posts ) ) {
					foreach ( $posts as $p ) {
						$cursor  = (int) $p['ID'];
						$content = $p['post_content'] . ' ' . $p['post_excerpt'];
						if ( '' === trim( $content ) ) {
							continue;
						}

						// Fast extraction of attachment IDs and URLs
						$ids  = ReferenceNormalizer::extract_attachment_ids( $content );
						$urls = ReferenceNormalizer::extract_image_urls( $content );

						foreach ( $ids as $att_id ) {
							$index->add_reference( $att_id, $p['post_type'], (int) $p['ID'], 'content', 'post_content', '', 'content_id' );
						}
						foreach ( $urls as $u ) {
							$index->add_reference_by_url( $u, $p['post_type'], (int) $p['ID'], 'content', 'post_content', 'content_url' );
						}
					}
					$state['processed_count'] += count( $posts );
				} else {
					// Phase 2 finished -> Advance to Builders
					$phase  = self::PHASE_BUILDERS;
					$cursor = 0;
					break;
				}
			}

			// =========================================================================
			// PHASE 3: Indexing Page Builders (Elementor, Divi, Beaver, etc.)
			// =========================================================================
			elseif ( self::PHASE_BUILDERS === $phase ) {
				$builder_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_id, post_id, meta_key, meta_value 
						 FROM {$wpdb->postmeta} 
						 WHERE meta_id > %d 
						 AND meta_key IN ('_elementor_data', '_et_builder_custom_post_content', '_fl_builder_data', '_ct_builder_json') 
						 AND meta_value != '' 
						 ORDER BY meta_id ASC 
						 LIMIT %d",
						$cursor,
						$batch
					),
					ARRAY_A
				);

				if ( ! empty( $builder_rows ) ) {
					foreach ( $builder_rows as $row ) {
						$cursor  = (int) $row['meta_id'];
						$post_id = (int) $row['post_id'];
						$val     = (string) $row['meta_value'];

						$ids  = ReferenceNormalizer::extract_attachment_ids( $val );
						$urls = ReferenceNormalizer::extract_image_urls( $val );

						foreach ( $ids as $att_id ) {
							$index->add_reference( $att_id, 'builder', $post_id, 'page_builder', $row['meta_key'], '', 'builder_id' );
						}
						foreach ( $urls as $u ) {
							$index->add_reference_by_url( $u, 'builder', $post_id, 'page_builder', $row['meta_key'], 'builder_url' );
						}
					}
					$state['processed_count'] += count( $builder_rows );
				} else {
					// Phase 3 finished -> Advance to Taxonomies & Themes
					$phase  = self::PHASE_TAXONOMIES_THEMES;
					$cursor = 0;
					break;
				}
			}

			// =========================================================================
			// PHASE 4: Indexing Taxonomies, User Meta & Theme Mods
			// =========================================================================
			elseif ( self::PHASE_TAXONOMIES_THEMES === $phase ) {
				// 1. Site logo & icon
				$logo_id = (int) get_theme_mod( 'custom_logo' );
				if ( $logo_id > 0 ) {
					$index->add_reference( $logo_id, 'theme', 0, 'theme_mod', 'custom_logo', '', 'site_logo' );
				}
				$icon_id = (int) get_option( 'site_icon' );
				if ( $icon_id > 0 ) {
					$index->add_reference( $icon_id, 'theme', 0, 'option', 'site_icon', '', 'site_icon' );
				}
				$header_image = get_theme_mod( 'header_image' );
				if ( ! empty( $header_image ) && is_string( $header_image ) ) {
					$index->add_reference_by_url( $header_image, 'theme', 0, 'theme_mod', 'header_image', 'theme_header' );
				}

				// 2. Term Meta (WooCommerce category thumbnails, brand logos)
				$term_rows = $wpdb->get_results(
					"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_value != '' LIMIT 500",
					ARRAY_A
				);
				if ( ! empty( $term_rows ) ) {
					foreach ( $term_rows as $t_row ) {
						if ( is_numeric( $t_row['meta_value'] ) && (int) $t_row['meta_value'] > 0 ) {
							$index->add_reference( (int) $t_row['meta_value'], 'term', (int) $t_row['term_id'], 'termmeta', $t_row['meta_key'], '', 'term_meta' );
						}
					}
				}

				// 3. User avatars / usermeta
				$user_rows = $wpdb->get_results(
					"SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('user_avatar', 'profile_photo', 'header_image') AND meta_value != '' LIMIT 250",
					ARRAY_A
				);
				if ( ! empty( $user_rows ) ) {
					foreach ( $user_rows as $u_row ) {
						if ( is_numeric( $u_row['meta_value'] ) && (int) $u_row['meta_value'] > 0 ) {
							$index->add_reference( (int) $u_row['meta_value'], 'user', (int) $u_row['user_id'], 'usermeta', $u_row['meta_key'], '', 'user_meta' );
						}
					}
				}

				// Phase 4 finished -> Advance to Matching Attachments
				$phase  = self::PHASE_MATCHING;
				$cursor = 0;
				break;
			}

			// =========================================================================
			// PHASE 5: Matching Media Attachments against Reference Index
			// =========================================================================
			elseif ( self::PHASE_MATCHING === $phase ) {
				$attachments = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_date, post_parent 
						 FROM {$wpdb->posts} 
						 WHERE ID > %d 
						 AND post_type = 'attachment' 
						 AND post_mime_type LIKE 'image/%%' 
						 AND post_status = 'inherit' 
						 ORDER BY ID ASC 
						 LIMIT %d",
						$cursor,
						$batch
					),
					ARRAY_A
				);

				if ( empty( $attachments ) ) {
					// Phase 5 finished -> Complete!
					$phase = self::PHASE_COMPLETE;
					break;
				}

				$att_ids = array_map( function ( $a ) { return (int) $a['ID']; }, $attachments );

				// Pre-fetch all postmeta for this batch in 1 fast query
				update_meta_cache( 'post', $att_ids );

				$settings = self::get_settings();
				$protect_days = (int) ( $settings['protect_recent_days'] ?? 30 );
				$protect_logo = ! empty( $settings['protect_site_identity'] );
				$logo_id      = (int) get_theme_mod( 'custom_logo' );
				$icon_id      = (int) get_option( 'site_icon' );
				$now          = time();

				$uploads = wp_upload_dir();
				$basedir = trailingslashit( $uploads['basedir'] );
				$baseurl = trailingslashit( $uploads['baseurl'] );

				// Collect filenames map for batch matching
				$family_filenames_map = array();
				$file_info_map        = array();

				foreach ( $attachments as $att_row ) {
					$att_id   = (int) $att_row['ID'];
					$file_rel = get_post_meta( $att_id, '_wp_attached_file', true );
					$metadata = wp_get_attachment_metadata( $att_id );

					$fns   = array();
					$bytes = 0;

					// Physical path resolution
					$attached_path = function_exists( 'get_attached_file' ) ? get_attached_file( $att_id ) : '';
					$resolved_path = '';
					if ( $attached_path && file_exists( $attached_path ) ) {
						$resolved_path = $attached_path;
					} elseif ( $file_rel ) {
						$norm_rel = ltrim( str_replace( '\\', '/', $file_rel ), '/' );
						if ( file_exists( $basedir . $norm_rel ) ) {
							$resolved_path = $basedir . $norm_rel;
						}
					}

					if ( $file_rel ) {
						$fns[] = wp_basename( $file_rel );
						if ( $resolved_path ) {
							$f_size = @filesize( $resolved_path );
							if ( false !== $f_size && $f_size > 0 ) {
								$bytes += $f_size;
							}
						}

						$dir_for_sizes = $resolved_path ? dirname( $resolved_path ) : ( $basedir . dirname( ltrim( str_replace( '\\', '/', $file_rel ), '/' ) ) );

						if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
							foreach ( $metadata['sizes'] as $s_info ) {
								if ( ! empty( $s_info['file'] ) ) {
									$fns[]  = $s_info['file'];
									$s_path = $dir_for_sizes . DIRECTORY_SEPARATOR . $s_info['file'];
									$s_size = @filesize( $s_path );
									if ( false !== $s_size && $s_size > 0 ) {
										$bytes += $s_size;
									}
								}
							}
						}
					}

					if ( 0 === $bytes && ! empty( $metadata['filesize'] ) ) {
						$bytes = (int) $metadata['filesize'];
					}

					$family_filenames_map[ $att_id ] = $fns;
					$file_info_map[ $att_id ] = array(
						'file_rel'      => $file_rel,
						'resolved_path' => $resolved_path,
						'bytes'         => $bytes,
					);
				}

				// BULK DATABASE MATCH in 1 query!
				$batch_matched = ReferenceIndex::match_attachments_in_db( $att_ids, $family_filenames_map );

				// Process and persist items into omninox_media_items
				$items_table = Schema::table( 'media_items' );
				$item_placeholders = array();
				$item_values       = array();

				foreach ( $attachments as $att_row ) {
					$att_id   = (int) $att_row['ID'];
					$cursor   = $att_id;
					$f_info   = $file_info_map[ $att_id ] ?? array();
					$file_rel = $f_info['file_rel'] ?? '';
					$bytes    = (int) ( $f_info['bytes'] ?? 0 );
					$res_path = $f_info['resolved_path'] ?? '';

					$is_quarantined = (bool) get_post_meta( $att_id, '_omninox_quarantined', true );
					$is_protected   = (bool) get_post_meta( $att_id, '_omninox_protected', true );

					$status            = self::STATUS_UNUSED;
					$protection_reason = '';
					$refs              = $batch_matched[ $att_id ] ?? array();

					if ( $is_protected ) {
						$status = self::STATUS_PROTECTED;
						$protection_reason = __( 'Manually marked as protected by administrator.', 'omninox-site-toolkit' );
					} elseif ( $protect_logo && ( $att_id === $logo_id || $att_id === $icon_id ) ) {
						$status = self::STATUS_PROTECTED;
						$protection_reason = __( 'Active Site Logo or Favicon.', 'omninox-site-toolkit' );
					} elseif ( $protect_days > 0 && ( $now - strtotime( $att_row['post_date'] ) ) < ( $protect_days * DAY_IN_SECONDS ) ) {
						$status = self::STATUS_PROTECTED;
						$protection_reason = sprintf( __( 'Recently uploaded within protected %d-day window.', 'omninox-site-toolkit' ), $protect_days );
					} elseif ( ! empty( $refs ) ) {
						$status = self::STATUS_USED;
					} else {
						// 100% Deletion Safety Secondary Cross-Verification
						$live_refs = self::live_cross_verify_attachment( $att_id, $family_filenames_map[ $att_id ] ?? array(), array(), get_post( $att_id ) );
						if ( ! empty( $live_refs ) ) {
							$refs   = $live_refs;
							$status = self::STATUS_USED;
						} else {
							// Check physical file
							if ( ( ! $res_path && ! $file_rel ) || ( $res_path && false === @filesize( $res_path ) ) ) {
								$status = self::STATUS_UNKNOWN;
								$protection_reason = __( 'Physical file missing or unreadable.', 'omninox-site-toolkit' );
							} else {
								$status = self::STATUS_UNUSED;
							}
						}
					}

					$thumb_url = wp_get_attachment_image_url( $att_id, 'thumbnail' );
					if ( ! $thumb_url && $file_rel ) {
						$thumb_url = $baseurl . ltrim( str_replace( '\\', '/', $file_rel ), '/' );
					}

					$item_placeholders[] = '(%d, %s, %s, %s, %s, %d, %s, %s, %s, %d, %d, %s, %d, %s, %d)';
					$item_values[] = $att_id;
					$item_values[] = wp_basename( $file_rel ?: '' );
					$item_values[] = $att_row['post_title'] ?: wp_basename( $file_rel ?: '' );
					$item_values[] = $file_rel ? ( $baseurl . ltrim( str_replace( '\\', '/', $file_rel ), '/' ) ) : '';
					$item_values[] = $thumb_url ?: '';
					$item_values[] = $bytes;
					$item_values[] = size_format( $bytes );
					$item_values[] = date( 'Y-m-d', strtotime( $att_row['post_date'] ) );
					$item_values[] = $status;
					$item_values[] = $is_quarantined ? 1 : 0;
					$item_values[] = $is_protected ? 1 : 0;
					$item_values[] = $protection_reason;
					$item_values[] = count( $refs );
					$item_values[] = wp_json_encode( array_slice( $refs, 0, 10 ) );
					$item_values[] = $generation;
				}

				if ( ! empty( $item_placeholders ) ) {
					$sql = "INSERT INTO {$items_table} 
						(attachment_id, filename, title, url, thumb_url, bytes, formatted_size, uploaded_date, status, is_quarantined, is_protected, protection_reason, references_count, references_sample, scan_generation) 
						VALUES " . implode( ', ', $item_placeholders ) . " 
						ON DUPLICATE KEY UPDATE 
							filename = VALUES(filename),
							title = VALUES(title),
							url = VALUES(url),
							thumb_url = VALUES(thumb_url),
							bytes = VALUES(bytes),
							formatted_size = VALUES(formatted_size),
							uploaded_date = VALUES(uploaded_date),
							status = VALUES(status),
							is_quarantined = VALUES(is_quarantined),
							is_protected = VALUES(is_protected),
							protection_reason = VALUES(protection_reason),
							references_count = VALUES(references_count),
							references_sample = VALUES(references_sample),
							scan_generation = VALUES(scan_generation)";

					$wpdb->query( $wpdb->prepare( $sql, $item_values ) );
				}

				$state['processed_count'] += count( $attachments );
			}
		}

		// Flush any remaining buffered references
		$index->flush_to_db();

		// Check if scan completed
		$is_done = ( self::PHASE_COMPLETE === $phase );
		if ( $is_done ) {
			$items_table = Schema::table( 'media_items' );
			$stat_rows = $wpdb->get_results(
				"SELECT status, COUNT(*) as cnt, SUM(bytes) as total_bytes 
				 FROM {$items_table} 
				 GROUP BY status",
				ARRAY_A
			);

			$computed_stats = array(
				'total'             => 0,
				'used'              => 0,
				'likely_unused'     => 0,
				'unknown'           => 0,
				'protected'         => 0,
				'recoverable_bytes' => 0,
			);

			foreach ( (array) $stat_rows as $sr ) {
				$st  = $sr['status'];
				$cnt = (int) $sr['cnt'];
				$computed_stats[ $st ] = $cnt;
				$computed_stats['total'] += $cnt;
				if ( self::STATUS_UNUSED === $st ) {
					$computed_stats['recoverable_bytes'] = (int) $sr['total_bytes'];
				}
			}

			$state['status'] = 'complete';
			$state['stats']  = $computed_stats;
			$state['current_phase'] = self::PHASE_COMPLETE;
		} else {
			$state['current_phase'] = $phase;
			$state['cursor_id']     = $cursor;
		}

		// Count total references in DB
		$ref_table = Schema::table( 'media_references' );
		$total_refs = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$ref_table}" );
		$state['references_indexed'] = $total_refs;

		self::save_scan_state( $state );

		// Calculate realistic phase-weighted progress percentage
		$progress_pct = self::calculate_progress( $phase, $cursor, $state );

		return array(
			'status'             => $is_done ? 'complete' : 'running',
			'done'               => $is_done,
			'scan_id'            => $state['scan_id'],
			'phase'              => $phase,
			'phase_label'        => self::get_phase_label( $phase ),
			'progress'           => $progress_pct,
			'images_discovered'  => $state['phase_state']['attachments_count'] ?? 0,
			'sources_processed'  => $state['processed_count'],
			'sources_total'      => $state['total_count'],
			'references_indexed' => $total_refs,
			'stats'              => $state['stats'],
			'message'            => $is_done
				? sprintf( __( 'Done: scanned %1$d images (%2$d likely unused, %3$s recoverable).', 'omninox-site-toolkit' ), $state['stats']['total'] ?? 0, $state['stats']['likely_unused'] ?? 0, size_format( $state['stats']['recoverable_bytes'] ?? 0 ) )
				: sprintf( __( '%1$s (%2$d references indexed)…', 'omninox-site-toolkit' ), self::get_phase_label( $phase ), $total_refs ),
		);
	}

	/**
	 * Compute realistic phase-weighted scan progress.
	 *
	 * @param string $phase Current phase.
	 * @param int    $cursor Keyset cursor.
	 * @param array  $state Active state.
	 * @return int Percentage 0-100.
	 */
	private static function calculate_progress( string $phase, int $cursor, array $state ): int {
		if ( self::PHASE_COMPLETE === $phase ) {
			return 100;
		}

		$phase_weights = array(
			self::PHASE_DISCOVERY         => array( 0, 5 ),
			self::PHASE_META              => array( 5, 20 ),
			self::PHASE_CONTENT           => array( 20, 50 ),
			self::PHASE_BUILDERS          => array( 50, 70 ),
			self::PHASE_TAXONOMIES_THEMES => array( 70, 78 ),
			self::PHASE_MATCHING          => array( 78, 99 ),
		);

		$range = $phase_weights[ $phase ] ?? array( 5, 95 );
		$min   = $range[0];
		$max   = $range[1];

		$p_state = $state['phase_state'] ?? array();
		$total   = 1;

		if ( self::PHASE_META === $phase ) {
			$total = max( 1, (int) ( $p_state['meta_count'] ?? 1 ) );
		} elseif ( self::PHASE_CONTENT === $phase ) {
			$total = max( 1, (int) ( $p_state['posts_count'] ?? 1 ) );
		} elseif ( self::PHASE_BUILDERS === $phase ) {
			$total = max( 1, (int) ( $p_state['builders_count'] ?? 1 ) );
		} elseif ( self::PHASE_MATCHING === $phase ) {
			$total = max( 1, (int) ( $p_state['attachments_count'] ?? 1 ) );
		}

		$fraction = min( 1.0, max( 0.05, ( $state['processed_count'] % $total ) / $total ) );
		$pct = (int) round( $min + ( ( $max - $min ) * $fraction ) );

		return min( 99, max( 5, $pct ) );
	}

	/**
	 * Pause the currently running scan.
	 *
	 * @return array
	 */
	public static function pause_scan(): array {
		$state = self::get_scan_state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'paused';
			self::save_scan_state( $state );
		}
		return array( 'status' => 'paused', 'message' => __( 'Scan paused.', 'omninox-site-toolkit' ) );
	}

	/**
	 * Resume a paused scan.
	 *
	 * @return array
	 */
	public static function resume_scan(): array {
		$state = self::get_scan_state();
		if ( 'paused' === $state['status'] ) {
			$state['status'] = 'running';
			self::save_scan_state( $state );
		}
		return array( 'status' => 'running', 'message' => __( 'Resuming scan…', 'omninox-site-toolkit' ) );
	}

	/**
	 * Cancel the currently running or paused scan.
	 *
	 * @return array
	 */
	public static function cancel_scan(): array {
		$state = self::get_scan_state();
		$state['status'] = 'cancelled';
		self::save_scan_state( $state );

		return array( 'status' => 'cancelled', 'message' => __( 'Scan cancelled.', 'omninox-site-toolkit' ) );
	}

	/**
	 * Retrieve paged results with high-speed indexed SQL filtering and search.
	 *
	 * @param array $params Query arguments.
	 * @return array
	 */
	public static function get_results( array $params = array() ): array {
		global $wpdb;
		$table = Schema::table( 'media_items' );

		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( ! $table_exists ) {
			return array(
				'has_scan'    => false,
				'total'       => 0,
				'total_items' => 0,
				'items'       => array(),
				'stats'       => array(),
			);
		}

		$filter = sanitize_key( $params['filter'] ?? 'likely_unused' );
		if ( empty( $filter ) ) {
			$filter = 'likely_unused';
		}
		$search   = trim( sanitize_text_field( $params['search'] ?? '' ) );
		$page     = max( 1, absint( $params['page'] ?? 1 ) );
		$per_page = max( 10, min( 500, absint( $params['per_page'] ?? 100 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where = array( '1=1' );
		$where_values = array();

		// Strict Status Filtering (default shows only unused)
		if ( 'unused' === $filter || 'likely_unused' === $filter ) {
			$where[] = "status = 'likely_unused' AND is_quarantined = 0";
		} elseif ( 'quarantined' === $filter ) {
			$where[] = "is_quarantined = 1";
		} elseif ( 'protected' === $filter ) {
			$where[] = "(status = 'protected' OR is_protected = 1)";
		} elseif ( 'used' === $filter ) {
			$where[] = "status = 'used'";
		} elseif ( 'unknown' === $filter ) {
			$where[] = "status = 'unknown'";
		}

		// Search
		if ( '' !== $search ) {
			$where[] = "(filename LIKE %s OR title LIKE %s OR attachment_id = %d)";
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where_values[] = $like;
			$where_values[] = $like;
			$where_values[] = (int) $search;
		}

		$where_sql = implode( ' AND ', $where );
		if ( ! empty( $where_values ) ) {
			$where_sql = $wpdb->prepare( $where_sql, $where_values );
		}

		$total_filtered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		$total_all      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} 
				 WHERE {$where_sql} 
				 ORDER BY attachment_id DESC 
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$refs_sample = ! empty( $row['references_sample'] ) ? json_decode( $row['references_sample'], true ) : array();
			$bytes       = (int) $row['bytes'];
			$ref_count   = (int) $row['references_count'];

			$items[] = array(
				'id'                => (int) $row['attachment_id'],
				'title'             => $row['title'] ?: $row['filename'],
				'filename'          => $row['filename'],
				'url'               => $row['url'],
				'thumb_url'         => $row['thumb_url'],
				'bytes'             => $bytes,
				'file_size'         => $bytes,
				'formatted_size'    => $row['formatted_size'] ?: size_format( $bytes ),
				'file_size_fmt'     => $row['formatted_size'] ?: size_format( $bytes ),
				'uploaded'          => $row['uploaded_date'],
				'date'              => $row['uploaded_date'],
				'status'            => $row['status'],
				'is_quarantined'    => (bool) $row['is_quarantined'],
				'is_protected'      => (bool) $row['is_protected'],
				'protection_reason' => $row['protection_reason'],
				'reference_count'   => $ref_count,
				'references_count'  => $ref_count,
				'references'        => is_array( $refs_sample ) ? $refs_sample : array(),
			);
		}

		// Retrieve latest stats
		$state = self::get_scan_state();
		$stats = $state['stats'] ?? array(
			'total'             => $total_all,
			'used'              => 0,
			'likely_unused'     => 0,
			'unknown'           => 0,
			'protected'         => 0,
			'recoverable_bytes' => 0,
		);

		return array(
			'has_scan'         => ( $total_all > 0 ),
			'total'            => $total_filtered,
			'total_items'      => $total_filtered,
			'total_unfiltered' => $total_all,
			'page'             => $page,
			'per_page'         => $per_page,
			'total_pages'      => (int) ceil( $total_filtered / $per_page ),
			'last_scanned'     => ! empty( $state['updated_at'] ) ? date_i18n( 'Y-m-d H:i:s', strtotime( $state['updated_at'] ) ) : '',
			'stats'            => $stats,
			'items'            => $items,
		);
	}

	/**
	 * Retrieve latest scan snapshot (fallback for legacy integrations).
	 *
	 * @return array
	 */
	public static function get_latest_snapshot(): array {
		$res = self::get_results( array( 'filter' => 'all', 'per_page' => 500 ) );
		return array(
			'items' => $res['items'],
			'stats' => $res['stats'],
		);
	}

	/**
	 * Exhaustive live cross-verification for an attachment across all WordPress database tables.
	 *
	 * Ensures 100% deletion safety before any media file is removed or quarantined.
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

		// 6. Custom Field PostMeta (ACF, Meta Box, Pods, WooCommerce meta)
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

		global $wpdb;
		$items_table = Schema::table( 'media_items' );

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
				$wpdb->update( $items_table, array( 'is_quarantined' => 1 ), array( 'attachment_id' => $id ) );
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

		global $wpdb;
		$items_table = Schema::table( 'media_items' );

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
				$wpdb->update( $items_table, array( 'is_quarantined' => 0 ), array( 'attachment_id' => $id ) );
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

		global $wpdb;
		$items_table = Schema::table( 'media_items' );

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
				$wpdb->delete( $items_table, array( 'attachment_id' => $id ) );
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
		global $wpdb;
		$items_table = Schema::table( 'media_items' );

		if ( $protect ) {
			update_post_meta( $attachment_id, '_omninox_protected', 1 );
		} else {
			delete_post_meta( $attachment_id, '_omninox_protected' );
		}

		$wpdb->update(
			$items_table,
			array(
				'is_protected'      => $protect ? 1 : 0,
				'status'            => $protect ? self::STATUS_PROTECTED : self::STATUS_UNUSED,
				'protection_reason' => $protect ? __( 'Protected by administrator.', 'omninox-site-toolkit' ) : '',
			),
			array( 'attachment_id' => $attachment_id )
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
