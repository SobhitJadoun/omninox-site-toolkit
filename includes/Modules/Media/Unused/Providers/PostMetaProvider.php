<?php
/**
 * PostMeta Reference Provider.
 *
 * Scans postmeta for featured images, WooCommerce product galleries, ACF,
 * Meta Box, Pods, JetEngine, and serialized custom fields.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

class PostMetaProvider implements ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'postmeta';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Post Meta (Featured Images, WooCommerce & Custom Fields)', 'omninox-site-toolkit' );
	}

	/**
	 * Collect references into the index.
	 *
	 * @param ReferenceIndex $index Reference index instance.
	 * @param array          $options Scan options.
	 */
	public function collect_references( ReferenceIndex $index, array $options = array() ): void {
		global $wpdb;

		// 1. Featured images across all post types (_thumbnail_id)
		$thumbs = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value > 0"
		);

		foreach ( $thumbs as $row ) {
			$att_id = (int) $row->meta_value;
			if ( $att_id > 0 ) {
				$index->add_id(
					$att_id,
					array(
						'type'     => 'postmeta',
						'id'       => (int) $row->post_id,
						'title'    => get_the_title( $row->post_id ),
						'provider' => 'postmeta',
						'field'    => '_thumbnail_id',
						'method'   => 'featured_image',
					)
				);
			}
		}
		unset( $thumbs );

		// 2. WooCommerce product image galleries (_product_image_gallery)
		$galleries = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value != ''"
		);

		foreach ( $galleries as $row ) {
			$ids = array_map( 'absint', explode( ',', (string) $row->meta_value ) );
			foreach ( $ids as $att_id ) {
				if ( $att_id > 0 ) {
					$index->add_id(
						$att_id,
						array(
							'type'     => 'product',
							'id'       => (int) $row->post_id,
							'title'    => get_the_title( $row->post_id ),
							'provider' => 'woocommerce',
							'field'    => '_product_image_gallery',
							'method'   => 'product_gallery',
						)
					);
				}
			}
		}
		unset( $galleries );

		// 3. Scan ACF, Meta Box, Pods, and general custom fields with image references
		$chunk_size = 500;
		$page       = 0;

		while ( true ) {
			$offset = $page * $chunk_size;
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value 
					FROM {$wpdb->postmeta} 
					WHERE meta_key NOT IN ('_thumbnail_id', '_product_image_gallery', '_wp_attachment_metadata', '_wp_attached_file', '_omninox_webp', '_omninox_avif', '_omninox_replaced') 
					AND (meta_value LIKE '%%uploads/%%' OR meta_value LIKE '%%.jpg%%' OR meta_value LIKE '%%.png%%' OR meta_value LIKE '%%.webp%%' OR meta_value LIKE '%%.avif%%' OR meta_key LIKE '%%image%%' OR meta_key LIKE '%%gallery%%' OR meta_key LIKE '%%photo%%' OR meta_key LIKE '%%logo%%') 
					ORDER BY meta_id ASC 
					LIMIT %d OFFSET %d",
					$chunk_size,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$val = $row->meta_value;
				if ( '' === $val || null === $val ) {
					continue;
				}

				$base_ref = array(
					'type'     => 'postmeta',
					'id'       => (int) $row->post_id,
					'title'    => get_the_title( $row->post_id ),
					'provider' => 'postmeta',
					'field'    => $row->meta_key,
				);

				// Numeric attachment ID in custom field (e.g. ACF image ID)
				if ( is_numeric( $val ) && (int) $val > 0 ) {
					$att_id = (int) $val;
					// Validate it's an attachment
					if ( 'attachment' === get_post_type( $att_id ) ) {
						$index->add_id( $att_id, array_merge( $base_ref, array( 'method' => 'meta_id' ) ) );
					}
					continue;
				}

				// Serialized metadata (ACF galleries, repeaters, sliders, widgets)
				if ( is_serialized( $val ) ) {
					$data = @unserialize( $val, array( 'allowed_classes' => false ) );
					if ( is_array( $data ) ) {
						$this->extract_references_from_data( $index, $data, $base_ref );
					}
					continue;
				}

				// JSON metadata (e.g. block attributes or builder models)
				if ( ( '{' === $val[0] || '[' === $val[0] ) && false !== strpos( $val, '"' ) ) {
					$json = json_decode( $val, true );
					if ( is_array( $json ) ) {
						$this->extract_references_from_data( $index, $json, $base_ref );
						continue;
					}
				}

				// String URLs in meta_value
				if ( false !== strpos( $val, '/uploads/' ) || preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $val ) ) {
					$ref = array_merge( $base_ref, array( 'method' => 'meta_url', 'url' => $val ) );
					$index->add_url( $val, $ref );
					$index->add_filename( wp_basename( $val ), $ref );
				}
			}

			unset( $rows );
			++$page;

			// Memory guard
			if ( function_exists( 'memory_get_usage' ) ) {
				$mem = memory_get_usage();
				$limit_str = ini_get( 'memory_limit' );
				$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $limit_str ) : 134217728;
				if ( $limit > 0 && $mem > ( $limit * 0.85 ) ) {
					break;
				}
			}
		}
	}

	/**
	 * Recursively extract attachment references from parsed arrays/objects.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param array          $data Array data.
	 * @param array          $base_ref Base reference details.
	 */
	private function extract_references_from_data( ReferenceIndex $index, array $data, array $base_ref ): void {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$this->extract_references_from_data( $index, $v, $base_ref );
				continue;
			}

			$key_lower = strtolower( (string) $k );

			// Check ID keys
			if ( in_array( $key_lower, array( 'id', 'attachment_id', 'image_id', 'thumbnail_id', 'media_id' ), true ) ) {
				if ( is_numeric( $v ) && (int) $v > 0 ) {
					$index->add_id( (int) $v, array_merge( $base_ref, array( 'method' => 'meta_data_id' ) ) );
				}
			}

			// Check URL strings
			if ( is_string( $v ) && ( false !== strpos( $v, '/uploads/' ) || preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $v ) ) ) {
				$ref = array_merge( $base_ref, array( 'method' => 'meta_data_url', 'url' => $v ) );
				$index->add_url( $v, $ref );
				$index->add_filename( wp_basename( $v ), $ref );
			}
		}
	}
}
