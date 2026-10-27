<?php
/**
 * Taxonomy Reference Provider.
 *
 * Scans term metadata for category/tag thumbnails, WooCommerce product
 * categories, brand logos, and custom taxonomy images.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

class TaxonomyProvider implements ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'taxonomy';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Taxonomy Meta (Categories, Tags & Term Thumbnails)', 'omninox-site-toolkit' );
	}

	/**
	 * Collect references into the index.
	 *
	 * @param ReferenceIndex $index Reference index instance.
	 * @param array          $options Scan options.
	 */
	public function collect_references( ReferenceIndex $index, array $options = array() ): void {
		global $wpdb;

		// 1. Termmeta containing thumbnail IDs or image references
		$rows = $wpdb->get_results(
			"SELECT tm.term_id, tm.meta_key, tm.meta_value, t.name, tt.taxonomy 
			FROM {$wpdb->termmeta} tm 
			LEFT JOIN {$wpdb->terms} t ON (tm.term_id = t.term_id) 
			LEFT JOIN {$wpdb->term_taxonomy} tt ON (tm.term_id = tt.term_id) 
			WHERE tm.meta_key IN ('thumbnail_id', 'image', 'cat_image', 'featured_image', 'brand_image') 
			OR tm.meta_value LIKE '%%uploads/%%' OR tm.meta_value LIKE '%%.jpg%%' OR tm.meta_value LIKE '%%.png%%' OR tm.meta_value LIKE '%%.webp%%'"
		);

		foreach ( $rows as $row ) {
			$val = $row->meta_value;
			if ( '' === $val || null === $val ) {
				continue;
			}

			$title = $row->name ? $row->name : sprintf( __( 'Term #%d', 'omninox-site-toolkit' ), (int) $row->term_id );
			$base_ref = array(
				'type'     => $row->taxonomy ?: 'taxonomy',
				'id'       => (int) $row->term_id,
				'title'    => sprintf( __( 'Category / Term: %s', 'omninox-site-toolkit' ), $title ),
				'provider' => 'taxonomy',
				'field'    => $row->meta_key,
			);

			// Numeric attachment ID
			if ( is_numeric( $val ) && (int) $val > 0 ) {
				$att_id = (int) $val;
				if ( 'attachment' === get_post_type( $att_id ) ) {
					$index->add_id( $att_id, array_merge( $base_ref, array( 'method' => 'term_thumbnail_id' ) ) );
				}
				continue;
			}

			// URL string
			if ( is_string( $val ) && false !== strpos( $val, '/uploads/' ) ) {
				$ref = array_merge( $base_ref, array( 'method' => 'term_img_url', 'url' => $val ) );
				$index->add_url( $val, $ref );
				$index->add_filename( wp_basename( $val ), $ref );
			}
		}
		unset( $rows );
	}
}
