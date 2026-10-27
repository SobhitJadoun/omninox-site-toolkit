<?php
/**
 * Module 3: Plugin Conflict Detector.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Performance;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class ConflictModule extends AbstractModule {

	protected $id   = 'conflicts';
	protected $icon = 'warning';

	public function get_label() {
		return __( 'Conflict Detector', 'omninox-site-toolkit' );
	}

	/**
	 * Plugin families where running two at once causes trouble.
	 *
	 * @return array
	 */
	private function families() {
		return array(
			'seo'      => array(
				'label'   => __( 'SEO plugins', 'omninox-site-toolkit'),
				'plugins' => array(
					'wordpress-seo/wp-seo.php'       => 'Yoast SEO',
					'seo-by-rank-math/rank-math.php' => 'Rank Math',
					'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
					'autodescription/autodescription.php' => 'The SEO Framework',
				),
				'reason'  => __( 'Two SEO plugins write competing title tags, canonicals and schema. Search engines see contradictory signals.', 'omninox-site-toolkit'),
			),
			'cache'    => array(
				'label'   => __( 'Caching plugins', 'omninox-site-toolkit'),
				'plugins' => array(
					'wp-rocket/wp-rocket.php'             => 'WP Rocket',
					'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
					'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
					'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
					'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
				),
				'reason'  => __( 'Two cache layers overwrite each other\'s rules and frequently serve stale or broken pages.', 'omninox-site-toolkit'),
			),
			'security' => array(
				'label'   => __( 'Firewall plugins', 'omninox-site-toolkit'),
				'plugins' => array(
					'wordfence/wordfence.php'   => 'Wordfence',
					'sucuri-scanner/sucuri.php' => 'Sucuri',
					'better-wp-security/better-wp-security.php' => 'Solid Security',
					'all-in-one-wp-security-and-firewall/wp-security.php' => 'AIOS',
				),
				'reason'  => __( 'Overlapping firewalls duplicate login throttling and can lock out legitimate administrators.', 'omninox-site-toolkit'),
			),
			'optimise' => array(
				'label'   => __( 'Asset optimizers', 'omninox-site-toolkit'),
				'plugins' => array(
					'autoptimize/autoptimize.php'   => 'Autoptimize',
					'perfmatters/perfmatters.php'   => 'Perfmatters',
					'wp-asset-clean-up/wpacu.php'   => 'Asset CleanUp',
				),
				'reason'  => __( 'Two optimizers minifying the same files usually produces broken JavaScript.', 'omninox-site-toolkit'),
			),
		);
	}

	/**
	 * Run the conflict scan.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$recommendations = array();

		// Same-family duplicates.
		foreach ( $this->families() as $key => $family ) {
			$active = array();

			foreach ( $family['plugins'] as $file => $name ) {
				if ( Utils::plugin_active( $file ) ) {
					$active[] = $name;
				}
			}

			$this->add(
				array(
					'id'          => 'family_' . $key,
					'label'       => $family['label'],
					'value'       => $active ? implode( ', ', $active ) : __( 'None active', 'omninox-site-toolkit'),
					'weight'      => 3,
					'severity'    => count( $active ) > 1 ? self::SEV_CRITICAL : self::SEV_PASS,
					'description' => count( $active ) > 1 ? $family['reason'] : '',
					'context'     => array( 'active' => $active ),
				)
			);

			if ( count( $active ) > 1 ) {
				$recommendations[] = array(
					'problem' => implode( ' + ', $active ),
					'reason'  => $family['reason'],
					'fix'     => sprintf(
						/* translators: %s: plugin name */
						__( 'Keep one and deactivate the others. If you are migrating, export settings from %s first.', 'omninox-site-toolkit'),
						$active[0]
					),
				);
			}
		}

		// Known pairwise conflicts.
		foreach ( $this->known_pairs() as $pair ) {
			if ( Utils::plugin_active( $pair['a'] ) && Utils::plugin_active( $pair['b'] ) ) {
				$this->add(
					array(
						'id'          => 'pair_' . md5( $pair['a'] . $pair['b'] ),
						'label'       => $pair['title'],
						'severity'    => self::SEV_WARNING,
						'weight'      => 2,
						'description' => $pair['reason'],
						'context'     => array( 'fix' => $pair['fix'] ),
					)
				);

				$recommendations[] = array(
					'problem' => $pair['title'],
					'reason'  => $pair['reason'],
					'fix'     => $pair['fix'],
				);
			}
		}

		// Deprecated function usage in active plugins.
		$deprecated = $this->scan_for_deprecated();

		$this->add(
			array(
				'id'       => 'deprecated_functions',
				'label'    => __( 'Deprecated function calls in active plugins', 'omninox-site-toolkit'),
				'value'    => (string) count( $deprecated ),
				'weight'   => 1.5,
				'severity' => empty( $deprecated ) ? self::SEV_PASS : self::SEV_NOTICE,
				'context'  => array( 'matches' => array_slice( $deprecated, 0, 40 ) ),
				'description' => __( 'These calls still work today but break on future PHP or WordPress releases.', 'omninox-site-toolkit'),
			)
		);

		// Abandoned plugins.
		$stale = $this->stale_plugins();

		$this->add(
			array(
				'id'       => 'stale_plugins',
				'label'    => __( 'Plugins with pending updates', 'omninox-site-toolkit'),
				'value'    => (string) count( $stale ),
				'weight'   => 2,
				'severity' => empty( $stale ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'plugins' => $stale ),
			)
		);

		return $this->result( array( 'recommendations' => $recommendations ) );
	}

	/**
	 * Documented conflicting combinations.
	 *
	 * @return array
	 */
	private function known_pairs() {
		return array(
			array(
				'a'      => 'elementor/elementor.php',
				'b'      => 'litespeed-cache/litespeed-cache.php',
				'title'  => __( 'Elementor with LiteSpeed JS delay', 'omninox-site-toolkit'),
				'reason' => __( 'LiteSpeed\'s delayed JavaScript runs Elementor\'s frontend bundle after the DOM is painted, which breaks sliders, tabs and popups.', 'omninox-site-toolkit'),
				'fix'    => __( 'In LiteSpeed → Page Optimization → Tuning, exclude elementor-frontend, elementor-frontend-modules and jquery from JS delay.', 'omninox-site-toolkit'),
			),
			array(
				'a'      => 'woocommerce/woocommerce.php',
				'b'      => 'autoptimize/autoptimize.php',
				'title'  => __( 'WooCommerce with aggregated JavaScript', 'omninox-site-toolkit'),
				'reason' => __( 'Aggregating cart and checkout scripts commonly breaks AJAX add-to-cart and the checkout fragment refresh.', 'omninox-site-toolkit'),
				'fix'    => __( 'Exclude wc-cart-fragments, wc-checkout and wc-add-to-cart from JavaScript aggregation.', 'omninox-site-toolkit'),
			),
			array(
				'a'      => 'elementor/elementor.php',
				'b'      => 'wp-rocket/wp-rocket.php',
				'title'  => __( 'Elementor with Remove Unused CSS', 'omninox-site-toolkit'),
				'reason' => __( 'Removing unused CSS strips Elementor\'s conditionally loaded widget styles, so popups and off-canvas menus lose their styling.', 'omninox-site-toolkit'),
				'fix'    => __( 'Add elementor-* handles to the Remove Unused CSS safelist in WP Rocket.', 'omninox-site-toolkit'),
			),
		);
	}

	/**
	 * Grep active plugin files for deprecated calls.
	 *
	 * @return array
	 */
	private function scan_for_deprecated() {
		$needles = array(
			'create_function(',
			'mysql_query(',
			'wp_get_http(',
			'get_currentuserinfo(',
			'screen_icon(',
			'wp_make_content_images_responsive(',
			'each(',
		);

		$found  = array();
		$active = (array) get_option( 'active_plugins', array() );

		$plugins_dir = \OmninoxSiteToolkit\Helpers\Utils::get_plugins_dir();

		foreach ( $active as $plugin_file ) {
			$dir = trailingslashit( $plugins_dir ) . dirname( $plugin_file );

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = (array) glob( $dir . '/*.php' );

			foreach ( array_slice( $files, 0, 25 ) as $file ) {
				$code = @file_get_contents( $file, false, null, 0, 300000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

				if ( ! $code ) {
					continue;
				}

				foreach ( $needles as $needle ) {
					if ( false !== strpos( $code, $needle ) ) {
						$found[] = array(
							'plugin'   => dirname( $plugin_file ),
							'file'     => basename( $file ),
							'function' => rtrim( $needle, '(' ),
						);
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Plugins with an available update.
	 *
	 * @return array
	 */
	private function stale_plugins() {
		$updates = get_site_transient( 'update_plugins' );

		if ( empty( $updates->response ) ) {
			return array();
		}

		$out = array();

		foreach ( $updates->response as $file => $data ) {
			$out[] = array(
				'plugin'  => dirname( $file ),
				'current' => $updates->checked[ $file ] ?? '',
				'latest'  => $data->new_version ?? '',
			);
		}

		return $out;
	}
}
