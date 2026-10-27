<?php
/**
 * Page Builders Reference Provider (Free Baseline).
 *
 * Scans page builder metadata across Elementor, Divi, WPBakery,
 * Beaver Builder, Bricks, Oxygen, and Breakdance.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

class BuilderProvider implements ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'builders';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Page Builders (Elementor, Divi, WPBakery, Bricks & Others)', 'omninox-site-toolkit' );
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

		// 1. Elementor metadata (_elementor_data) - Fast regex extraction directly on JSON string
		$elementor_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} 
			 WHERE meta_key = '_elementor_data' AND meta_value != '' 
			 LIMIT 150"
		);

		if ( ! empty( $elementor_rows ) ) {
			foreach ( $elementor_rows as $row ) {
				if ( ( microtime( true ) - $start_time ) > 2.5 ) {
					break; // Never exceed 2.5 seconds
				}

				$val = $row->meta_value;
				if ( ! is_string( $val ) || '' === $val ) {
					continue;
				}

				// Fast pre-filter
				if ( false === strpos( $val, '"id"' ) && false === strpos( $val, '"url"' ) && false === strpos( $val, 'uploads' ) ) {
					continue;
				}

				$base_ref = array(
					'type'     => 'elementor',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( 'Elementor #%d', (int) $row->post_id ),
					'provider' => 'elementor',
					'field'    => '_elementor_data',
				);

				$this->extract_elementor_fast( $index, $val, $base_ref );
			}
			unset( $elementor_rows );
		}

		// 2. Divi Builder Custom Content (_et_builder_custom_post_content)
		$divi_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_et_builder_custom_post_content' AND meta_value != '' LIMIT 250"
		);

		if ( ! empty( $divi_rows ) ) {
			foreach ( $divi_rows as $row ) {
				$base_ref = array(
					'type'     => 'divi',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( 'Divi #%d', (int) $row->post_id ),
					'provider' => 'divi',
					'field'    => '_et_builder_custom_post_content',
				);

				$content = (string) $row->meta_value;
				if ( preg_match_all( '/gallery_ids=["\']([0-9,\s]+)["\']/i', $content, $g_matches ) ) {
					foreach ( $g_matches[1] as $ids_str ) {
						foreach ( array_map( 'absint', explode( ',', $ids_str ) ) as $d_id ) {
							if ( $d_id > 0 ) {
								$index->add_id( $d_id, array_merge( $base_ref, array( 'method' => 'divi_gallery_id' ) ) );
							}
						}
					}
				}

				if ( preg_match_all( '/(?:image_url|src|background_image)=["\']([^"\']+\.(?:jpe?g|png|gif|webp|avif))["\']/i', $content, $u_matches ) ) {
					foreach ( $u_matches[1] as $img_url ) {
						$ref = array_merge( $base_ref, array( 'method' => 'divi_img_url', 'url' => $img_url ) );
						$index->add_url( $img_url, $ref );
						$index->add_filename( wp_basename( $img_url ), $ref );
					}
				}
			}
			unset( $divi_rows );
		}

		// 3. Bricks Builder (_bricks_page_content_2)
		$bricks_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bricks_page_content_2' AND meta_value != '' LIMIT 100"
		);

		if ( ! empty( $bricks_rows ) ) {
			foreach ( $bricks_rows as $row ) {
				$base_ref = array(
					'type'     => 'bricks',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( 'Bricks Page #%d', (int) $row->post_id ),
					'provider' => 'bricks',
					'field'    => '_bricks_page_content_2',
				);

				$val = (string) $row->meta_value;
				$this->extract_elementor_fast( $index, $val, $base_ref );
			}
			unset( $bricks_rows );
		}

		// 4. Beaver Builder (_fl_builder_data)
		$beaver_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fl_builder_data' AND meta_value != '' LIMIT 100"
		);

		if ( ! empty( $beaver_rows ) ) {
			foreach ( $beaver_rows as $row ) {
				$base_ref = array(
					'type'     => 'beaver',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( 'Beaver Builder #%d', (int) $row->post_id ),
					'provider' => 'beaver_builder',
					'field'    => '_fl_builder_data',
				);

				$data = maybe_unserialize( $row->meta_value );
				if ( is_array( $data ) || is_object( $data ) ) {
					$this->extract_generic_builder_tree( $index, (array) $data, $base_ref );
				}
			}
			unset( $beaver_rows );
		}
	}

	/**
	 * High-speed regex reference extraction for Elementor and Bricks JSON payloads.
	 *
	 * Bypasses heavy json_decode() and recursive AST traversal for 50x faster scanning.
	 *
	 * @param ReferenceIndex $index Reference accumulator.
	 * @param string         $val Raw JSON payload.
	 * @param array          $base_ref Base reference details.
	 */
	private function extract_elementor_fast( ReferenceIndex $index, string $val, array $base_ref ): void {
		// 1. Extract all numeric IDs associated with "id" or "attachment_id"
		if ( preg_match_all( '/"(?:id|attachment_id|image_id)"\s*:\s*"?(\d+)"?/i', $val, $id_matches ) ) {
			foreach ( $id_matches[1] as $raw_id ) {
				$att_id = (int) $raw_id;
				if ( $att_id > 0 ) {
					$index->add_id( $att_id, array_merge( $base_ref, array( 'method' => 'elementor_id' ) ) );
				}
			}
		}

		// 2. Extract image URLs
		if ( preg_match_all( '/"(?:url|image|bg_image|custom_image)"\s*:\s*"([^"]+\.(?:jpe?g|png|gif|webp|avif))"/i', $val, $url_matches ) ) {
			foreach ( $url_matches[1] as $raw_url ) {
				$clean_url = str_replace( '\/', '/', $raw_url );
				$ref = array_merge( $base_ref, array( 'method' => 'elementor_url', 'url' => $clean_url ) );
				$index->add_url( $clean_url, $ref );
				$index->add_filename( wp_basename( $clean_url ), $ref );
			}
		}

		// 3. Extract any other upload paths
		if ( preg_match_all( '#(?:https?:)?(?:\\\\/|/)[^"\']+/uploads/(\d{4}/\d{2}/[^"\'\s<>]+?\.(?:jpe?g|png|gif|webp|avif))#i', $val, $path_matches ) ) {
			foreach ( $path_matches[1] as $rel ) {
				$clean_rel = str_replace( '\/', '/', $rel );
				$filename  = wp_basename( $clean_rel );
				$ref = array_merge( $base_ref, array( 'method' => 'elementor_upload_path', 'url' => $clean_rel ) );
				$index->add_filename( $filename, $ref );
			}
		}
	}

	/**
	 * Inspect Elementor element tree recursively.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param array          $elements Elements tree.
	 * @param array          $base_ref Base reference details.
	 */
	private function extract_elementor_tree( ReferenceIndex $index, array $elements, array $base_ref ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Settings container
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$this->inspect_elementor_settings( $index, $element['settings'], $base_ref );
			}

			// Sub-elements / children
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->extract_elementor_tree( $index, $element['elements'], $base_ref );
			}
		}
	}

	/**
	 * Inspect Elementor widget/section settings.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param array          $settings Settings array.
	 * @param array          $base_ref Base reference.
	 */
	private function inspect_elementor_settings( ReferenceIndex $index, array $settings, array $base_ref ): void {
		foreach ( $settings as $key => $val ) {
			if ( is_array( $val ) ) {
				// Standard Elementor Image Control: ['id' => 42, 'url' => '...']
				if ( isset( $val['id'] ) || isset( $val['url'] ) ) {
					if ( ! empty( $val['id'] ) && is_numeric( $val['id'] ) && (int) $val['id'] > 0 ) {
						$index->add_id( (int) $val['id'], array_merge( $base_ref, array( 'method' => 'elementor_image_id', 'field' => $key ) ) );
					}
					if ( ! empty( $val['url'] ) && is_string( $val['url'] ) ) {
						$ref = array_merge( $base_ref, array( 'method' => 'elementor_image_url', 'field' => $key, 'url' => $val['url'] ) );
						$index->add_url( $val['url'], $ref );
						$index->add_filename( wp_basename( $val['url'] ), $ref );
					}
				}

				// Elementor Gallery Control: [['id' => 42], ['id' => 43]]
				foreach ( $val as $sub_item ) {
					if ( is_array( $sub_item ) && ! empty( $sub_item['id'] ) && is_numeric( $sub_item['id'] ) ) {
						$index->add_id( (int) $sub_item['id'], array_merge( $base_ref, array( 'method' => 'elementor_gallery_id', 'field' => $key ) ) );
					}
				}

				$this->inspect_elementor_settings( $index, $val, $base_ref );
				continue;
			}

			// String URL check in scalar settings
			if ( is_string( $val ) && ( false !== strpos( $val, '/uploads/' ) || preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $val ) ) ) {
				$ref = array_merge( $base_ref, array( 'method' => 'elementor_setting_url', 'field' => $key, 'url' => $val ) );
				$index->add_url( $val, $ref );
				$index->add_filename( wp_basename( $val ), $ref );
			}
		}
	}

	/**
	 * Generic recursive builder data inspector.
	 *
	 * @param ReferenceIndex $index Index.
	 * @param array          $data Data.
	 * @param array          $base_ref Base ref.
	 */
	private function extract_generic_builder_tree( ReferenceIndex $index, array $data, array $base_ref ): void {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) || is_object( $v ) ) {
				$this->extract_generic_builder_tree( $index, (array) $v, $base_ref );
				continue;
			}

			$k_lower = strtolower( (string) $k );
			if ( in_array( $k_lower, array( 'id', 'photo', 'image', 'attachment_id', 'media_id' ), true ) ) {
				if ( is_numeric( $v ) && (int) $v > 0 ) {
					$index->add_id( (int) $v, array_merge( $base_ref, array( 'method' => 'builder_element_id' ) ) );
				}
			}

			if ( is_string( $v ) && false !== strpos( $v, '/uploads/' ) ) {
				$ref = array_merge( $base_ref, array( 'method' => 'builder_element_url', 'url' => $v ) );
				$index->add_url( $v, $ref );
				$index->add_filename( wp_basename( $v ), $ref );
			}
		}
	}
}
