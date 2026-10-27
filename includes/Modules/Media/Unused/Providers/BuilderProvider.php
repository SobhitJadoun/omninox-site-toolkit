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

		// 1. Elementor metadata (_elementor_data)
		$chunk_size = 100;
		$page       = 0;

		while ( true ) {
			$offset = $page * $chunk_size;
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value != '' LIMIT %d OFFSET %d",
					$chunk_size,
					$offset
				)
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$val = $row->meta_value;
				if ( ! is_string( $val ) || '' === $val ) {
					continue;
				}

				$elements = json_decode( $val, true );
				if ( is_array( $elements ) ) {
					$base_ref = array(
						'type'     => get_post_type( $row->post_id ) ?: 'elementor',
						'id'       => (int) $row->post_id,
						'title'    => sprintf( __( 'Elementor Page: %s', 'omninox-site-toolkit' ), get_the_title( $row->post_id ) ),
						'provider' => 'elementor',
						'field'    => '_elementor_data',
					);

					$this->extract_elementor_tree( $index, $elements, $base_ref );
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

		// 2. Divi Builder Custom Content (_et_builder_custom_post_content)
		$divi_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_et_builder_custom_post_content' AND meta_value != ''"
		);

		foreach ( $divi_rows as $row ) {
			$base_ref = array(
				'type'     => 'divi',
				'id'       => (int) $row->post_id,
				'title'    => sprintf( __( 'Divi Layout: %s', 'omninox-site-toolkit' ), get_the_title( $row->post_id ) ),
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

		// 3. Bricks Builder (_bricks_page_content_2)
		$bricks_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bricks_page_content_2' AND meta_value != ''"
		);

		foreach ( $bricks_rows as $row ) {
			$data = json_decode( (string) $row->meta_value, true );
			if ( is_array( $data ) ) {
				$base_ref = array(
					'type'     => 'bricks',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( __( 'Bricks Page: %s', 'omninox-site-toolkit' ), get_the_title( $row->post_id ) ),
					'provider' => 'bricks',
					'field'    => '_bricks_page_content_2',
				);

				$this->extract_generic_builder_tree( $index, $data, $base_ref );
			}
		}
		unset( $bricks_rows );

		// 4. Beaver Builder (_fl_builder_data)
		$beaver_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_fl_builder_data' AND meta_value != ''"
		);

		foreach ( $beaver_rows as $row ) {
			$data = maybe_unserialize( $row->meta_value );
			if ( is_array( $data ) || is_object( $data ) ) {
				$base_ref = array(
					'type'     => 'beaver',
					'id'       => (int) $row->post_id,
					'title'    => sprintf( __( 'Beaver Builder: %s', 'omninox-site-toolkit' ), get_the_title( $row->post_id ) ),
					'provider' => 'beaver_builder',
					'field'    => '_fl_builder_data',
				);

				$this->extract_generic_builder_tree( $index, (array) $data, $base_ref );
			}
		}
		unset( $beaver_rows );
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
