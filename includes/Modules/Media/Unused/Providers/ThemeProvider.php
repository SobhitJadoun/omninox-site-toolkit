<?php
/**
 * Theme & Site Identity Reference Provider.
 *
 * Scans custom logo, site icon, header image, background image, theme mods,
 * widgets, block theme templates, and global styles.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

class ThemeProvider implements ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'theme';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Theme & Site Identity (Logo, Icon, Header, Widgets & FSE Templates)', 'omninox-site-toolkit' );
	}

	/**
	 * Collect references into the index.
	 *
	 * @param ReferenceIndex $index Reference index instance.
	 * @param array          $options Scan options.
	 */
	public function collect_references( ReferenceIndex $index, array $options = array() ): void {
		global $wpdb;

		// 1. Site Logo
		$custom_logo = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo > 0 ) {
			$index->add_id(
				$custom_logo,
				array(
					'type'     => 'theme',
					'id'       => $custom_logo,
					'title'    => __( 'Site Logo (Customizer)', 'omninox-site-toolkit' ),
					'provider' => 'theme',
					'field'    => 'custom_logo',
					'method'   => 'site_logo',
				)
			);
		}

		// 2. Site Icon (Favicon)
		$site_icon = (int) get_option( 'site_icon' );
		if ( $site_icon > 0 ) {
			$index->add_id(
				$site_icon,
				array(
					'type'     => 'theme',
					'id'       => $site_icon,
					'title'    => __( 'Site Icon / Favicon', 'omninox-site-toolkit' ),
					'provider' => 'theme',
					'field'    => 'site_icon',
					'method'   => 'site_icon',
				)
			);
		}

		// 3. Custom Header Image
		$header_image = get_header_image();
		if ( $header_image ) {
			$ref = array(
				'type'     => 'theme',
				'id'       => 0,
				'title'    => __( 'Header Image (Customizer)', 'omninox-site-toolkit' ),
				'provider' => 'theme',
				'field'    => 'header_image',
				'method'   => 'header_image',
				'url'      => $header_image,
			);
			$index->add_url( $header_image, $ref );
			$index->add_filename( wp_basename( $header_image ), $ref );

			$header_data = get_custom_header();
			if ( ! empty( $header_data->attachment_id ) ) {
				$index->add_id( (int) $header_data->attachment_id, $ref );
			}
		}

		// 4. Background Image
		$bg_image = get_background_image();
		if ( $bg_image ) {
			$ref = array(
				'type'     => 'theme',
				'id'       => 0,
				'title'    => __( 'Background Image (Customizer)', 'omninox-site-toolkit' ),
				'provider' => 'theme',
				'field'    => 'background_image',
				'method'   => 'background_image',
				'url'      => $bg_image,
			);
			$index->add_url( $bg_image, $ref );
			$index->add_filename( wp_basename( $bg_image ), $ref );
		}

		// 5. Theme mods array
		$stylesheet = get_stylesheet();
		$theme_mods = get_option( "theme_mods_{$stylesheet}" );
		if ( is_array( $theme_mods ) ) {
			$this->extract_from_theme_data( $index, $theme_mods, __( 'Theme Mod Setting', 'omninox-site-toolkit' ) );
		}

		// 6. Active Widgets in wp_options
		$widget_options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget_%' AND (option_value LIKE '%%attachment_id%%' OR option_value LIKE '%%uploads/%%' OR option_value LIKE '%%.jpg%%' OR option_value LIKE '%%.png%%' OR option_value LIKE '%%.webp%%')"
		);

		foreach ( $widget_options as $opt ) {
			$val = maybe_unserialize( $opt->option_value );
			if ( is_array( $val ) ) {
				$this->extract_from_theme_data( $index, $val, sprintf( __( 'Widget (%s)', 'omninox-site-toolkit' ), $opt->option_name ) );
			}
		}
		unset( $widget_options );

		// 7. Full Site Editing (FSE) block templates & template parts
		$fse_templates = $wpdb->get_results(
			"SELECT ID, post_title, post_name, post_type, post_content FROM {$wpdb->posts} WHERE post_type IN ('wp_template', 'wp_template_part', 'wp_global_styles')"
		);

		foreach ( $fse_templates as $tpl ) {
			$content = (string) $tpl->post_content;
			if ( '' === $content ) {
				continue;
			}

			$title = $tpl->post_title ?: $tpl->post_name;
			$ref = array(
				'type'     => $tpl->post_type,
				'id'       => (int) $tpl->ID,
				'title'    => sprintf( __( 'Block Template: %s', 'omninox-site-toolkit' ), $title ),
				'provider' => 'theme',
				'field'    => 'post_content',
			);

			// Check block IDs
			if ( preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+-->/s', $content, $matches ) ) {
				foreach ( $matches[1] as $json_str ) {
					$attrs = json_decode( $json_str, true );
					if ( is_array( $attrs ) ) {
						$this->extract_ids_recursive( $index, $attrs, $ref );
					}
				}
			}

			// Check image URLs
			if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $srcs ) ) {
				foreach ( $srcs[1] as $src ) {
					$index->add_url( $src, array_merge( $ref, array( 'method' => 'fse_template_img', 'url' => $src ) ) );
					$index->add_filename( wp_basename( $src ), array_merge( $ref, array( 'method' => 'fse_template_img', 'url' => $src ) ) );
				}
			}
		}
		unset( $fse_templates );
	}

	/**
	 * Recursively extract references from theme options or widgets.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param array          $data Data array.
	 * @param string         $source_label Label.
	 */
	private function extract_from_theme_data( ReferenceIndex $index, array $data, string $source_label ): void {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$this->extract_from_theme_data( $index, $v, $source_label );
				continue;
			}

			$k_lower = strtolower( (string) $k );
			$base_ref = array(
				'type'     => 'theme',
				'id'       => 0,
				'title'    => $source_label,
				'provider' => 'theme',
				'field'    => (string) $k,
			);

			if ( in_array( $k_lower, array( 'attachment_id', 'logo', 'image_id', 'custom_logo', 'site_icon' ), true ) ) {
				if ( is_numeric( $v ) && (int) $v > 0 ) {
					$index->add_id( (int) $v, array_merge( $base_ref, array( 'method' => 'theme_data_id' ) ) );
				}
			}

			if ( is_string( $v ) && ( false !== strpos( $v, '/uploads/' ) || preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $v ) ) ) {
				$ref = array_merge( $base_ref, array( 'method' => 'theme_data_url', 'url' => $v ) );
				$index->add_url( $v, $ref );
				$index->add_filename( wp_basename( $v ), $ref );
			}
		}
	}

	/**
	 * Extract IDs from decoded JSON block attributes.
	 *
	 * @param ReferenceIndex $index Index.
	 * @param array          $data Attrs.
	 * @param array          $ref Ref details.
	 */
	private function extract_ids_recursive( ReferenceIndex $index, array $data, array $ref ): void {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$this->extract_ids_recursive( $index, $v, $ref );
				continue;
			}

			if ( in_array( strtolower( (string) $k ), array( 'id', 'mediaid', 'attachment_id' ), true ) ) {
				if ( is_numeric( $v ) && (int) $v > 0 ) {
					$index->add_id( (int) $v, array_merge( $ref, array( 'method' => 'fse_block_id' ) ) );
				}
			}

			if ( is_string( $v ) && false !== strpos( $v, '/uploads/' ) ) {
				$index->add_url( $v, array_merge( $ref, array( 'method' => 'fse_block_url', 'url' => $v ) ) );
				$index->add_filename( wp_basename( $v ), array_merge( $ref, array( 'method' => 'fse_block_url', 'url' => $v ) ) );
			}
		}
	}
}
