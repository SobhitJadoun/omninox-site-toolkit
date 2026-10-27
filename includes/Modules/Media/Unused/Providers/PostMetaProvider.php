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

		$start_time = microtime( true );

		// 1. Featured images across all post types (_thumbnail_id) - indexed query
		$thumbs = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value > 0 LIMIT 5000"
		);

		if ( ! empty( $thumbs ) ) {
			foreach ( $thumbs as $row ) {
				$att_id = (int) $row->meta_value;
				if ( $att_id > 0 ) {
					$index->add_id(
						$att_id,
						array(
							'type'     => 'postmeta',
							'id'       => (int) $row->post_id,
							'title'    => sprintf( 'Post #%d', (int) $row->post_id ),
							'provider' => 'postmeta',
							'field'    => '_thumbnail_id',
							'method'   => 'featured_image',
						)
					);
				}
			}
			unset( $thumbs );
		}

		// 2. WooCommerce product image galleries (_product_image_gallery) - indexed query
		$galleries = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value != '' LIMIT 5000"
		);

		if ( ! empty( $galleries ) ) {
			foreach ( $galleries as $row ) {
				$ids = array_map( 'absint', explode( ',', (string) $row->meta_value ) );
				foreach ( $ids as $att_id ) {
					if ( $att_id > 0 ) {
						$index->add_id(
							$att_id,
							array(
								'type'     => 'product',
								'id'       => (int) $row->post_id,
								'title'    => sprintf( 'Product #%d', (int) $row->post_id ),
								'provider' => 'woocommerce',
								'field'    => '_product_image_gallery',
								'method'   => 'product_gallery',
							)
						);
					}
				}
			}
			unset( $galleries );
		}

		// 3. Scan ACF, Meta Box, Pods, and general custom fields with image references
		// Uses indexed meta_key lookups (exact match + prefix wildcards) - NEVER leading wildcards or meta_value LIKE
		$all_rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value 
			 FROM {$wpdb->postmeta} 
			 WHERE (
				meta_key IN ('image', '_image', 'gallery', '_gallery', 'photo', '_photo', 'logo', '_logo', 'banner', '_banner', 'icon', '_icon', 'featured_image', 'background_image', 'header_image', 'brand_logo', 'hero_image', 'avatar', 'thumbnail', 'cover', 'poster')
				OR meta_key LIKE 'image_%'
				OR meta_key LIKE 'gallery_%'
				OR meta_key LIKE 'photo_%'
				OR meta_key LIKE 'banner_%'
				OR meta_key LIKE 'logo_%'
				OR meta_key LIKE 'icon_%'
				OR meta_key LIKE '_image_%'
				OR meta_key LIKE '_gallery_%'
				OR meta_key LIKE 'acf_%'
			 )
			 AND meta_key NOT IN ('_thumbnail_id', '_product_image_gallery', '_wp_attachment_metadata', '_wp_attached_file', '_omninox_webp', '_omninox_avif')
			 LIMIT 2000"
		);

		if ( ! empty( $all_rows ) ) {
			foreach ( $all_rows as $row ) {
				if ( ( microtime( true ) - $start_time ) > 2.5 ) {
					break; // Safe cutoff
				}

				$val = $row->meta_value;
				if ( '' === $val || null === $val ) {
					continue;
				}

				$base_ref = array(
					'type'     => 'postmeta',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( 'Post #%d', (int) $row->post_id ),
					'provider' => 'postmeta',
					'field'    => $row->meta_key,
				);

				// Numeric attachment ID in custom field (e.g. ACF image ID)
				if ( is_numeric( $val ) && (int) $val > 0 ) {
					$att_id = (int) $val;
					$index->add_id( $att_id, array_merge( $base_ref, array( 'method' => 'meta_id' ) ) );
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

			unset( $all_rows );
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
