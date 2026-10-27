<?php
/**
 * Styles & Custom CSS Reference Provider.
 *
 * Scans WordPress Additional CSS (custom_css post type), theme options,
 * and inline stylesheets for background images and CSS url(...) references.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

class StylesProvider implements ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'styles';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Styles & CSS (Additional CSS & Custom Stylesheets)', 'omninox-site-toolkit' );
	}

	/**
	 * Collect references into the index.
	 *
	 * @param ReferenceIndex $index Reference index instance.
	 * @param array          $options Scan options.
	 */
	public function collect_references( ReferenceIndex $index, array $options = array() ): void {
		global $wpdb;

		// 1. WordPress Additional CSS (custom_css posts)
		$custom_css_posts = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'custom_css'"
		);

		foreach ( $custom_css_posts as $row ) {
			$css = (string) $row->post_content;
			if ( '' === $css ) {
				continue;
			}

			$base_ref = array(
				'type'     => 'custom_css',
				'id'       => (int) $row->ID,
				'title'    => sprintf( __( 'Additional CSS: %s', 'omninox-site-toolkit' ), $row->post_title ?: __( 'Custom CSS', 'omninox-site-toolkit' ) ),
				'provider' => 'styles',
				'field'    => 'post_content',
			);

			$this->extract_urls_from_css( $index, $css, $base_ref );
		}
		unset( $custom_css_posts );

		// 2. Custom theme CSS in wp_options
		$css_options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE (option_name LIKE '%%custom_css%%' OR option_name LIKE '%%custom_style%%') AND option_value LIKE '%%url(%%'"
		);

		foreach ( $css_options as $opt ) {
			$base_ref = array(
				'type'     => 'option',
				'id'       => 0,
				'title'    => sprintf( __( 'Customizer CSS (%s)', 'omninox-site-toolkit' ), $opt->option_name ),
				'provider' => 'styles',
				'field'    => $opt->option_name,
			);

			$this->extract_urls_from_css( $index, (string) $opt->option_value, $base_ref );
		}
		unset( $css_options );
	}

	/**
	 * Extract image URLs from CSS content.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param string         $css CSS content string.
	 * @param array          $base_ref Base reference details.
	 */
	private function extract_urls_from_css( ReferenceIndex $index, string $css, array $base_ref ): void {
		if ( false === strpos( $css, 'url(' ) ) {
			return;
		}

		if ( preg_match_all( '/url\(["\']?([^)"\']+\.(?:jpe?g|png|gif|webp|avif))["\']?\)/i', $css, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$ref = array_merge( $base_ref, array( 'method' => 'css_url', 'url' => $url ) );
				$index->add_url( $url, $ref );
				$index->add_filename( wp_basename( $url ), $ref );
			}
		}
	}
}
