<?php
/**
 * OmniNox High-Performance Image URL Replacement Engine.
 *
 * Implements serialization-safe, keyset-paginated, atomic database rewriting
 * for WebP and AVIF conversions with preview, dry-run, and rollback journals.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Replacement;

use OmninoxSiteToolkit\Database\Schema;

defined( 'ABSPATH' ) || exit;

class UrlReplacementEngine {

	public const STEP_INIT        = 'init';
	public const STEP_MAPPING     = 'mapping';
	public const STEP_POSTS       = 'posts';
	public const STEP_POSTMETA    = 'postmeta';
	public const STEP_OPTIONS     = 'options';
	public const STEP_TERMMETA    = 'termmeta';
	public const STEP_ATTACHMENTS = 'attachments';
	public const STEP_COMPLETE    = 'complete';

	/**
	 * Start or initialize a URL replacement job (or dry-run preview).
	 *
	 * @param array $params Request parameters (mode: 'replace' | 'preview', format: 'webp' | 'avif' | 'both').
	 * @return array
	 */
	public static function start_job( array $params = array() ): array {
		global $wpdb;

		Schema::install_media_tables();
		$jobs_table = Schema::table( 'url_jobs' );

		$mode   = ( isset( $params['mode'] ) && 'preview' === $params['mode'] ) ? 'preview' : 'replace';
		$format = sanitize_key( $params['format'] ?? 'webp' );
		if ( ! in_array( $format, array( 'webp', 'avif', 'both' ), true ) ) {
			$format = 'webp';
		}

		$job_id = 'url_job_' . time() . '_' . wp_generate_password( 6, false, false );

		// 1. First sync/verify mapping index
		UrlMappingIndex::sync_mappings( $format, 500, 0 );
		$summary = UrlMappingIndex::get_summary( $format );

		$now = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'job_id'             => $job_id,
			'mode'               => $mode,
			'format'             => $format,
			'status'             => 'running',
			'current_step'       => self::STEP_POSTS,
			'cursor_id'          => 0,
			'total_mappings'     => (int) $summary['total_mappings'],
			'rows_inspected'     => 0,
			'rows_replaced'      => 0,
			'replacements_count' => 0,
			'elapsed_ms'         => 0,
			'checkpoint_data'    => wp_json_encode( array(
				'step_cursors' => array(
					self::STEP_POSTS       => 0,
					self::STEP_POSTMETA    => 0,
					self::STEP_OPTIONS     => 0,
					self::STEP_TERMMETA    => 0,
					self::STEP_ATTACHMENTS => 0,
				),
			) ),
			'started_at'         => $now,
			'updated_at'         => $now,
		);

		$wpdb->insert( $jobs_table, $data );

		return array(
			'job_id'         => $job_id,
			'mode'           => $mode,
			'format'         => $format,
			'status'         => 'running',
			'current_step'   => self::STEP_POSTS,
			'step_label'     => self::get_step_label( self::STEP_POSTS ),
			'total_mappings' => (int) $summary['total_mappings'],
			'progress'       => 5,
			'message'        => sprintf(
				/* translators: 1: mode, 2: mappings count */
				__( 'Started URL %1$s with %2$d verified mappings…', 'omninox-site-toolkit' ),
				'preview' === $mode ? __( 'preview', 'omninox-site-toolkit' ) : __( 'replacement', 'omninox-site-toolkit' ),
				$summary['total_mappings']
			),
		);
	}

	/**
	 * Process the next batch of the active job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $batch Batch size.
	 * @return array
	 */
	public static function step_job( string $job_id, int $batch = 100 ): array {
		global $wpdb;

		$start_time = microtime( true );
		$jobs_table = Schema::table( 'url_jobs' );

		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs_table} WHERE job_id = %s", $job_id ), ARRAY_A );
		if ( ! $job ) {
			return array(
				'done'    => true,
				'status'  => 'error',
				'message' => __( 'Job not found.', 'omninox-site-toolkit' ),
			);
		}

		if ( 'paused' === $job['status'] || 'cancelled' === $job['status'] ) {
			return array(
				'done'         => false,
				'job_id'       => $job_id,
				'status'       => $job['status'],
				'current_step' => $job['current_step'],
				'message'      => sprintf( __( 'Job is %s.', 'omninox-site-toolkit' ), $job['status'] ),
			);
		}

		$mode           = $job['mode'];
		$format         = $job['format'];
		$step           = $job['current_step'];
		$cursor         = (int) $job['cursor_id'];
		$rows_inspected = (int) $job['rows_inspected'];
		$rows_replaced  = (int) $job['rows_replaced'];
		$replacements   = (int) $job['replacements_count'];
		$checkpoint     = json_decode( (string) $job['checkpoint_data'], true ) ?: array();
		$step_cursors   = $checkpoint['step_cursors'] ?? array();

		// Load multi-pattern replacement map
		$map = UrlMappingIndex::get_replacement_map( $format, true, true );
		if ( empty( $map ) ) {
			$job['status']       = self::STEP_COMPLETE;
			$job['current_step'] = self::STEP_COMPLETE;
			$wpdb->update( $jobs_table, array( 'status' => 'complete', 'current_step' => self::STEP_COMPLETE ), array( 'id' => (int) $job['id'] ) );

			return array(
				'done'         => true,
				'job_id'       => $job_id,
				'status'       => 'complete',
				'current_step' => self::STEP_COMPLETE,
				'step_label'   => self::get_step_label( self::STEP_COMPLETE ),
				'progress'     => 100,
				'rows_replaced'=> 0,
				'message'      => __( 'No verified image conversion mappings found to replace.', 'omninox-site-toolkit' ),
			);
		}

		$rollback_table = Schema::table( 'url_rollback' );
		$is_preview     = ( 'preview' === $mode );

		// =====================================================================
		// STEP: Posts (post_content & post_excerpt)
		// =====================================================================
		if ( self::STEP_POSTS === $step ) {
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} 
					 WHERE ID > %d 
					 AND post_type NOT IN ('attachment', 'revision') 
					 ORDER BY ID ASC 
					 LIMIT %d",
					$cursor,
					$batch
				),
				ARRAY_A
			);

			if ( ! empty( $posts ) ) {
				foreach ( $posts as $p ) {
					$cursor = (int) $p['ID'];
					++$rows_inspected;

					$changed_fields = array();

					// 1. post_content
					if ( '' !== trim( (string) $p['post_content'] ) && self::has_upload_indicators( $p['post_content'] ) ) {
						$content_changed = false;
						$new_content     = self::apply_replacements( $map, $p['post_content'], $content_changed );
						if ( $content_changed ) {
							$changed_fields['post_content'] = array(
								'old' => $p['post_content'],
								'new' => $new_content,
							);
						}
					}

					// 2. post_excerpt
					if ( '' !== trim( (string) $p['post_excerpt'] ) && self::has_upload_indicators( $p['post_excerpt'] ) ) {
						$excerpt_changed = false;
						$new_excerpt     = self::apply_replacements( $map, $p['post_excerpt'], $excerpt_changed );
						if ( $excerpt_changed ) {
							$changed_fields['post_excerpt'] = array(
								'old' => $p['post_excerpt'],
								'new' => $new_excerpt,
							);
						}
					}

					if ( ! empty( $changed_fields ) ) {
						++$rows_replaced;
						$replacements += count( $changed_fields );

						if ( ! $is_preview ) {
							$update_data = array();
							foreach ( $changed_fields as $col => $diff ) {
								$update_data[ $col ] = $diff['new'];

								// Write to rollback journal
								$wpdb->insert(
									$rollback_table,
									array(
										'job_id'         => $job_id,
										'table_name'     => $wpdb->posts,
										'row_id'         => (int) $p['ID'],
										'column_name'    => $col,
										'original_value' => $diff['old'],
										'new_value'      => $diff['new'],
										'created_at'     => gmdate( 'Y-m-d H:i:s' ),
									)
								);
							}
							$wpdb->update( $wpdb->posts, $update_data, array( 'ID' => (int) $p['ID'] ) );
							clean_post_cache( (int) $p['ID'] );
						}
					}
				}
			} else {
				// Transition to Postmeta
				$step = self::STEP_POSTMETA;
				$cursor = 0;
			}
		}

		// =====================================================================
		// STEP: Postmeta (Elementor, Divi, ACF, Product Galleries, etc.)
		// =====================================================================
		elseif ( self::STEP_POSTMETA === $step ) {
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} 
					 WHERE meta_id > %d 
					 AND meta_key NOT LIKE '%%token%%' 
					 AND meta_key NOT LIKE '%%password%%' 
					 AND meta_key NOT LIKE '%%secret%%' 
					 AND meta_key NOT LIKE '%%session%%' 
					 AND meta_key NOT LIKE '%%auth%%' 
					 AND meta_key NOT LIKE '%%license%%' 
					 AND meta_key NOT IN ('_edit_lock', '_edit_last', '_wp_page_template') 
					 AND meta_value != '' 
					 ORDER BY meta_id ASC 
					 LIMIT %d",
					$cursor,
					$batch
				),
				ARRAY_A
			);

			if ( ! empty( $meta_rows ) ) {
				foreach ( $meta_rows as $row ) {
					$cursor = (int) $row['meta_id'];
					++$rows_inspected;

					$val = (string) $row['meta_value'];
					if ( ! self::has_upload_indicators( $val ) ) {
						continue;
					}

					$changed = false;
					$new_val = self::apply_replacements( $map, $val, $changed );

					if ( $changed ) {
						++$rows_replaced;
						++$replacements;

						if ( ! $is_preview ) {
							$wpdb->insert(
								$rollback_table,
								array(
									'job_id'         => $job_id,
									'table_name'     => $wpdb->postmeta,
									'row_id'         => (int) $row['meta_id'],
									'column_name'    => 'meta_value',
									'original_value' => $val,
									'new_value'      => $new_val,
									'created_at'     => gmdate( 'Y-m-d H:i:s' ),
								)
							);
							$wpdb->update(
								$wpdb->postmeta,
								array( 'meta_value' => $new_val ),
								array( 'meta_id' => (int) $row['meta_id'] )
							);
							wp_cache_delete( (int) $row['post_id'], 'post_meta' );
						}
					}
				}
			} else {
				// Transition to Options
				$step = self::STEP_OPTIONS;
				$cursor = 0;
			}
		}

		// =====================================================================
		// STEP: Options (Theme settings, widgets, header/footer logos)
		// =====================================================================
		elseif ( self::STEP_OPTIONS === $step ) {
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} 
					 WHERE option_id > %d 
					 AND option_name NOT LIKE '%%_transient_%%' 
					 AND option_name NOT LIKE '%%token%%' 
					 AND option_name NOT LIKE '%%password%%' 
					 AND option_name NOT LIKE '%%secret%%' 
					 AND option_name NOT LIKE '%%session%%' 
					 AND option_name NOT LIKE '%%auth%%' 
					 AND option_name NOT LIKE '%%license%%' 
					 AND option_name NOT IN ('cron', 'siteurl', 'home', 'active_plugins') 
					 AND option_value != '' 
					 ORDER BY option_id ASC 
					 LIMIT %d",
					$cursor,
					$batch
				),
				ARRAY_A
			);

			if ( ! empty( $options ) ) {
				foreach ( $options as $opt ) {
					$cursor = (int) $opt['option_id'];
					++$rows_inspected;

					$val = (string) $opt['option_value'];
					if ( ! self::has_upload_indicators( $val ) ) {
						continue;
					}

					$changed = false;
					$new_val = self::apply_replacements( $map, $val, $changed );

					if ( $changed ) {
						++$rows_replaced;
						++$replacements;

						if ( ! $is_preview ) {
							$wpdb->insert(
								$rollback_table,
								array(
									'job_id'         => $job_id,
									'table_name'     => $wpdb->options,
									'row_id'         => (int) $opt['option_id'],
									'column_name'    => 'option_value',
									'original_value' => $val,
									'new_value'      => $new_val,
									'created_at'     => gmdate( 'Y-m-d H:i:s' ),
								)
							);
							$wpdb->update(
								$wpdb->options,
								array( 'option_value' => $new_val ),
								array( 'option_id' => (int) $opt['option_id'] )
							);
							wp_cache_delete( (string) $opt['option_name'], 'options' );
							wp_cache_delete( 'alloptions', 'options' );
						}
					}
				}
			} else {
				// Transition to Termmeta
				$step = self::STEP_TERMMETA;
				$cursor = 0;
			}
		}

		// =====================================================================
		// STEP: Termmeta (WooCommerce category thumbnails, brand logos)
		// =====================================================================
		elseif ( self::STEP_TERMMETA === $step ) {
			$term_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} 
					 WHERE meta_id > %d 
					 AND meta_value != '' 
					 ORDER BY meta_id ASC 
					 LIMIT %d",
					$cursor,
					$batch
				),
				ARRAY_A
			);

			if ( ! empty( $term_rows ) ) {
				foreach ( $term_rows as $t_row ) {
					$cursor = (int) $t_row['meta_id'];
					++$rows_inspected;

					$val = (string) $t_row['meta_value'];
					if ( ! self::has_upload_indicators( $val ) ) {
						continue;
					}

					$changed = false;
					$new_val = self::apply_replacements( $map, $val, $changed );

					if ( $changed ) {
						++$rows_replaced;
						++$replacements;

						if ( ! $is_preview ) {
							$wpdb->insert(
								$rollback_table,
								array(
									'job_id'         => $job_id,
									'table_name'     => $wpdb->termmeta,
									'row_id'         => (int) $t_row['meta_id'],
									'column_name'    => 'meta_value',
									'original_value' => $val,
									'new_value'      => $new_val,
									'created_at'     => gmdate( 'Y-m-d H:i:s' ),
								)
							);
							$wpdb->update(
								$wpdb->termmeta,
								array( 'meta_value' => $new_val ),
								array( 'meta_id' => (int) $t_row['meta_id'] )
							);
							wp_cache_delete( (int) $t_row['term_id'], 'term_meta' );
						}
					}
				}
			} else {
				// Transition to Attachments
				$step = self::STEP_ATTACHMENTS;
				$cursor = 0;
			}
		}

		// =====================================================================
		// STEP: Attachments (Canonical metadata & 30-day holding backups)
		// =====================================================================
		elseif ( self::STEP_ATTACHMENTS === $step ) {
			if ( $is_preview ) {
				// In preview mode, do not move physical files
				$step = self::STEP_COMPLETE;
			} else {
				$att_table = Schema::table( 'url_mappings' );
				$att_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT attachment_id FROM {$att_table} 
						 WHERE attachment_id > %d AND status = 'verified' 
						 ORDER BY attachment_id ASC 
						 LIMIT %d",
						$cursor,
						$batch
					)
				);

				if ( ! empty( $att_ids ) ) {
					foreach ( $att_ids as $att_id ) {
						$cursor = (int) $att_id;
						self::update_attachment_canonical_and_backup( (int) $att_id, $format );
					}
				} else {
					$step = self::STEP_COMPLETE;
				}
			}
		}

		$is_done = ( self::STEP_COMPLETE === $step );
		$progress = self::calculate_progress( $step );

		$elapsed_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );
		$step_cursors[ $step ] = $cursor;
		$checkpoint['step_cursors'] = $step_cursors;

		$wpdb->update(
			$jobs_table,
			array(
				'status'             => $is_done ? 'complete' : 'running',
				'current_step'       => $step,
				'cursor_id'          => $cursor,
				'rows_inspected'     => $rows_inspected,
				'rows_replaced'      => $rows_replaced,
				'replacements_count' => $replacements,
				'elapsed_ms'         => ( (int) $job['elapsed_ms'] ) + $elapsed_ms,
				'checkpoint_data'    => wp_json_encode( $checkpoint ),
				'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $job['id'] )
		);

		return array(
			'done'               => $is_done,
			'job_id'             => $job_id,
			'mode'               => $mode,
			'format'             => $format,
			'status'             => $is_done ? 'complete' : 'running',
			'current_step'       => $step,
			'step_label'         => self::get_step_label( $step ),
			'progress'           => $progress,
			'rows_inspected'     => $rows_inspected,
			'rows_replaced'      => $rows_replaced,
			'replacements_count' => $replacements,
			'message'            => $is_done
				? sprintf(
					/* translators: 1: mode, 2: rows updated, 3: replacements */
					__( 'Completed %1$s: %2$d database records updated with %3$d URL replacements.', 'omninox-site-toolkit' ),
					$is_preview ? __( 'preview', 'omninox-site-toolkit' ) : __( 'replacement', 'omninox-site-toolkit' ),
					$rows_replaced,
					$replacements
				)
				: sprintf(
					/* translators: 1: step label, 2: rows inspected */
					__( '%1$s (%2$d rows scanned)…', 'omninox-site-toolkit' ),
					self::get_step_label( $step ),
					$rows_inspected
				),
		);
	}

	/**
	 * Safe multi-pattern replacement for strings and serialized structures.
	 *
	 * @param array $map Search-to-replace mapping array.
	 * @param mixed $value Value to process.
	 * @param bool  $has_changed Output flag indicating if a change occurred.
	 * @return mixed
	 */
	public static function apply_replacements( array $map, $value, bool &$has_changed = false ) {
		if ( empty( $map ) || ! is_string( $value ) ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false !== $data || 'b:0;' === $value ) {
				$serialized_changed = false;
				$new_data = self::recursive_replace( $map, $data, $serialized_changed );
				if ( $serialized_changed ) {
					$has_changed = true;
					return serialize( $new_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
				return $value;
			}
		}

		// Fast C-level multi-pattern replacement via strtr
		$replaced = strtr( $value, $map );
		if ( $replaced !== $value ) {
			$has_changed = true;
			return $replaced;
		}

		return $value;
	}

	/**
	 * Walk arrays recursively and replace matched string values.
	 *
	 * @param array $map Search map.
	 * @param mixed $data Data array.
	 * @param bool  $changed Output flag.
	 * @return mixed
	 */
	private static function recursive_replace( array $map, $data, bool &$changed = false ) {
		if ( is_string( $data ) ) {
			$replaced = strtr( $data, $map );
			if ( $replaced !== $data ) {
				$changed = true;
				return $replaced;
			}
			return $data;
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::recursive_replace( $map, $v, $changed );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Check if string contains upload indicators before doing heavier matching.
	 *
	 * @param string $val Value.
	 * @return bool
	 */
	private static function has_upload_indicators( string $val ): bool {
		return (
			false !== strpos( $val, 'uploads' ) ||
			false !== strpos( $val, '.jpg' ) ||
			false !== strpos( $val, '.jpeg' ) ||
			false !== strpos( $val, '.png' ) ||
			false !== strpos( $val, '.gif' )
		);
	}

	/**
	 * Compute realistic phase-weighted progress percentage.
	 *
	 * @param string $step Current step.
	 * @return int
	 */
	private static function calculate_progress( string $step ): int {
		switch ( $step ) {
			case self::STEP_INIT: return 5;
			case self::STEP_MAPPING: return 12;
			case self::STEP_POSTS: return 35;
			case self::STEP_POSTMETA: return 65;
			case self::STEP_OPTIONS: return 80;
			case self::STEP_TERMMETA: return 88;
			case self::STEP_ATTACHMENTS: return 96;
			case self::STEP_COMPLETE: return 100;
			default: return 50;
		}
	}

	/**
	 * Human-readable step labels.
	 *
	 * @param string $step Step key.
	 * @return string
	 */
	public static function get_step_label( string $step ): string {
		switch ( $step ) {
			case self::STEP_POSTS: return __( 'Replacing in Post & Page Content…', 'omninox-site-toolkit' );
			case self::STEP_POSTMETA: return __( 'Replacing in Elementor, Page Builders & Custom Fields…', 'omninox-site-toolkit' );
			case self::STEP_OPTIONS: return __( 'Replacing in Theme Options & Widgets…', 'omninox-site-toolkit' );
			case self::STEP_TERMMETA: return __( 'Replacing in Taxonomy & Category Metadata…', 'omninox-site-toolkit' );
			case self::STEP_ATTACHMENTS: return __( 'Updating Canonical Attachments & Holding Originals…', 'omninox-site-toolkit' );
			case self::STEP_COMPLETE: return __( 'URL Replacement Complete', 'omninox-site-toolkit' );
			default: return __( 'Processing database replacements…', 'omninox-site-toolkit' );
		}
	}

	/**
	 * Update attachment canonical record and relocate originals to safe holding folder.
	 *
	 * @param int    $att_id Attachment ID.
	 * @param string $format Target format: webp|avif.
	 */
	public static function update_attachment_canonical_and_backup( int $att_id, string $format = 'webp' ): void {
		global $wpdb;

		$table = Schema::table( 'url_mappings' );
		$pairs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT size_key, old_rel, new_rel FROM {$table} 
				 WHERE attachment_id = %d AND status = 'verified' AND new_file_exists = 1",
				$att_id
			),
			ARRAY_A
		);

		if ( empty( $pairs ) ) {
			return;
		}

		$uploads  = wp_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$holding  = trailingslashit( $uploads['basedir'] ) . 'omninox-media-backup';
		$metadata = wp_get_attachment_metadata( $att_id );
		$replaced = (array) get_post_meta( $att_id, '_omninox_replaced', true );

		if ( empty( $replaced['sizes'] ) ) {
			$replaced['sizes'] = array();
		}

		$main_old_rel = '';
		$main_held_at = '';

		foreach ( $pairs as $pair ) {
			$old_rel = $pair['old_rel'];
			$new_rel = $pair['new_rel'];
			$source  = $basedir . $old_rel;
			$target  = trailingslashit( $holding ) . $old_rel;

			if ( file_exists( $source ) ) {
				wp_mkdir_p( dirname( $target ) );
				if ( @rename( $source, $target ) ) {
					if ( '_main' === $pair['size_key'] ) {
						$main_old_rel = $old_rel;
						$main_held_at = ltrim( str_replace( $basedir, '', $target ), '/' );
					} else {
						$replaced['sizes'][ $pair['size_key'] ] = array(
							'old_file' => basename( $old_rel ),
							'held_at'  => ltrim( str_replace( $basedir, '', $target ), '/' ),
						);
					}
				}
			}

			if ( '_main' === $pair['size_key'] ) {
				update_post_meta( $att_id, '_wp_attached_file', $new_rel );
				if ( isset( $metadata['file'] ) ) {
					$metadata['file'] = $new_rel;
				}
				wp_update_post(
					array(
						'ID'             => $att_id,
						'post_mime_type' => 'image/' . $format,
					)
				);
			} elseif ( isset( $metadata['sizes'][ $pair['size_key'] ] ) ) {
				$metadata['sizes'][ $pair['size_key'] ]['file']      = basename( $new_rel );
				$metadata['sizes'][ $pair['size_key'] ]['mime-type'] = 'image/' . $format;
			}
		}

		if ( $metadata ) {
			wp_update_attachment_metadata( $att_id, $metadata );
		}

		if ( $main_old_rel ) {
			$replaced['old_rel']  = $main_old_rel;
			$replaced['held_at']  = $main_held_at;
			$replaced['format']   = $format;
			$replaced['replaced'] = time();
		}

		update_post_meta( $att_id, '_omninox_replaced', $replaced );
	}

	/**
	 * Roll back a replacement job using the protected journal.
	 *
	 * Restores original database strings and original image files from holding.
	 *
	 * @param string $job_id Job ID to revert.
	 * @return array
	 */
	public static function rollback_job( string $job_id ): array {
		global $wpdb;

		$rollback_table = Schema::table( 'url_rollback' );
		$jobs_table     = Schema::table( 'url_jobs' );

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, table_name, row_id, column_name, original_value FROM {$rollback_table} 
				 WHERE job_id = %s 
				 ORDER BY id DESC",
				$job_id
			),
			ARRAY_A
		);

		$rows_reverted = 0;
		if ( ! empty( $entries ) ) {
			foreach ( $entries as $entry ) {
				$tbl     = $entry['table_name'];
				$col     = $entry['column_name'];
				$orig    = $entry['original_value'];
				$row_id  = (int) $entry['row_id'];
				$pk_col  = ( $tbl === $wpdb->posts ) ? 'ID' : ( ( $tbl === $wpdb->options ) ? 'option_id' : 'meta_id' );

				$wpdb->update( $tbl, array( $col => $orig ), array( $pk_col => $row_id ) );

				if ( $tbl === $wpdb->posts ) {
					clean_post_cache( $row_id );
				} elseif ( $tbl === $wpdb->postmeta ) {
					$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_id = %d", $row_id ) );
					if ( $pid ) {
						wp_cache_delete( $pid, 'post_meta' );
					}
				} elseif ( $tbl === $wpdb->options ) {
					$opt_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_id = %d", $row_id ) );
					if ( $opt_name ) {
						wp_cache_delete( $opt_name, 'options' );
					}
					wp_cache_delete( 'alloptions', 'options' );
				} elseif ( $tbl === $wpdb->termmeta ) {
					$tid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_id = %d", $row_id ) );
					if ( $tid ) {
						wp_cache_delete( $tid, 'term_meta' );
					}
				}

				++$rows_reverted;
			}
		}

		// Clean up rollback journal entries for this job
		$wpdb->delete( $rollback_table, array( 'job_id' => $job_id ) );

		// Update job record
		$wpdb->update(
			$jobs_table,
			array(
				'status'       => 'rolled_back',
				'current_step' => 'rolled_back',
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'job_id' => $job_id )
		);

		return array(
			'success'       => true,
			'job_id'        => $job_id,
			'rows_reverted' => $rows_reverted,
			'message'       => sprintf(
				/* translators: %d: rows reverted */
				__( 'Successfully rolled back %d database modifications.', 'omninox-site-toolkit' ),
				$rows_reverted
			),
		);
	}

	/**
	 * Pause the running job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	public static function pause_job( string $job_id ): array {
		global $wpdb;
		$table = Schema::table( 'url_jobs' );
		$wpdb->update( $table, array( 'status' => 'paused', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job_id ) );
		return array( 'status' => 'paused', 'message' => __( 'Job paused.', 'omninox-site-toolkit' ) );
	}

	/**
	 * Resume a paused job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	public static function resume_job( string $job_id ): array {
		global $wpdb;
		$table = Schema::table( 'url_jobs' );
		$wpdb->update( $table, array( 'status' => 'running', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job_id ) );
		return array( 'status' => 'running', 'message' => __( 'Job resumed.', 'omninox-site-toolkit' ) );
	}

	/**
	 * Cancel the running job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	public static function cancel_job( string $job_id ): array {
		global $wpdb;
		$table = Schema::table( 'url_jobs' );
		$wpdb->update( $table, array( 'status' => 'cancelled', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'job_id' => $job_id ) );
		return array( 'status' => 'cancelled', 'message' => __( 'Job cancelled.', 'omninox-site-toolkit' ) );
	}
}
