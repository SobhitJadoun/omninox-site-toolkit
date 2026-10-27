<?php
/**
 * Module 2: Performance Analyzer.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Performance;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Services\ValueHistory;

defined( 'ABSPATH' ) || exit;

class PerformanceModule extends AbstractModule {

	protected $id   = 'performance';
	protected $icon = 'performance';

	public function get_label() {
		return __( 'Performance', 'omninox-site-toolkit' );
	}

	/**
	 * Register front-end optimizations.
	 */
	public function register() {
		Optimizations::instance();
	}

	/**
	 * Toggles shown in the UI.
	 *
	 * @return array
	 */
	public function get_actions() {
		$current = (array) Settings::instance()->get( 'optimizations', array() );

		$toggles = array(
			'disable_emojis'         => __( 'Disable emoji scripts', 'omninox-site-toolkit'),
			'disable_embeds'         => __( 'Disable oEmbed scripts', 'omninox-site-toolkit'),
			'disable_dashicons'      => __( 'Disable Dashicons on the front end', 'omninox-site-toolkit'),
			'disable_jquery_migrate' => __( 'Disable jQuery Migrate', 'omninox-site-toolkit'),
			'remove_query_strings'   => __( 'Remove version query strings', 'omninox-site-toolkit'),
			'lazy_load'              => __( 'Lazy-load images and iframes', 'omninox-site-toolkit'),
			'minify_css'             => __( 'Minify CSS', 'omninox-site-toolkit'),
			'minify_js'              => __( 'Minify JavaScript', 'omninox-site-toolkit'),
			'optimize_fonts'         => __( 'Preconnect fonts and force display:swap', 'omninox-site-toolkit'),
		);

		$actions = array();

		foreach ( $toggles as $id => $label ) {
			$actions[] = array(
				'id'       => $id,
				'label'    => $label,
				'type'     => 'toggle',
				'current'  => ! empty( $current[ $id ] ) ? 1 : 0,
				'previous' => ValueHistory::previous( 'opt_' . $id ),
				'history'  => ValueHistory::summary( 'opt_' . $id ),
			);
		}

		$actions[] = array(
			'id'    => 'clear_cache',
			'label' => __( 'Clear generated cache', 'omninox-site-toolkit'),
			'type'  => 'button',
		);

		return $actions;
	}

	/**
	 * Analyse the front page.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$page = Utils::timed_get( home_url( '/' ), array( 'timeout' => 30 ) );

		if ( is_wp_error( $page ) ) {
			$this->add(
				array(
					'id'       => 'front_page_unreachable',
					'label'    => __( 'Front page could not be loaded', 'omninox-site-toolkit'),
					'severity' => self::SEV_CRITICAL,
					'value'    => $page->get_error_message(),
					'weight'   => 5,
				)
			);

			return $this->result();
		}

		$html    = $page['body'];
		$headers = array_change_key_case( (array) $page['headers'], CASE_LOWER );

		// TTFB.
		$this->add(
			array(
				'id'       => 'ttfb',
				'label'    => __( 'Server response time', 'omninox-site-toolkit'),
				'value'    => $page['ms'] . ' ms',
				'expected' => '< 600 ms',
				'weight'   => 3,
				'severity' => $page['ms'] < 600 ? self::SEV_PASS : ( $page['ms'] < 1500 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'description' => __( 'Time to first byte. Slow responses usually point at missing page caching or a heavy theme.', 'omninox-site-toolkit'),
			)
		);

		// Page weight.
		$size = strlen( $html );
		$this->add(
			array(
				'id'       => 'page_size',
				'label'    => __( 'HTML document size', 'omninox-site-toolkit'),
				'value'    => Utils::format_bytes( $size ),
				'expected' => '< 100 KB',
				'weight'   => 1.5,
				'severity' => $size < 102400 ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$assets = $this->parse_assets( $html );

		// Request count.
		$requests = count( $assets['css'] ) + count( $assets['js'] ) + count( $assets['img'] );
		$this->add(
			array(
				'id'       => 'http_requests',
				'label'    => __( 'Asset requests on the home page', 'omninox-site-toolkit'),
				'value'    => (string) $requests,
				'expected' => '< 50',
				'weight'   => 2,
				'severity' => $requests < 50 ? self::SEV_PASS : ( $requests < 90 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'context'  => array( 'css' => count( $assets['css'] ), 'js' => count( $assets['js'] ), 'img' => count( $assets['img'] ) ),
			)
		);

		// Render blocking.
		$blocking_css = count( $assets['css'] );
		$blocking_js  = count( $assets['blocking_js'] );

		$this->add(
			array(
				'id'          => 'render_blocking_css',
				'label'       => __( 'Render-blocking stylesheets', 'omninox-site-toolkit'),
				'value'       => (string) $blocking_css,
				'expected'    => '< 5',
				'weight'      => 2.5,
				'severity'    => $blocking_css < 5 ? self::SEV_PASS : self::SEV_WARNING,
				'context'     => array( 'files' => array_slice( $assets['css'], 0, 30 ) ),
				'description' => __( 'Each stylesheet in the head delays the first paint until it downloads.', 'omninox-site-toolkit'),
			)
		);

		$this->add(
			array(
				'id'       => 'render_blocking_js',
				'label'    => __( 'Render-blocking scripts in the head', 'omninox-site-toolkit'),
				'value'    => (string) $blocking_js,
				'expected' => '0',
				'weight'   => 2.5,
				'severity' => 0 === $blocking_js ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'files' => array_slice( $assets['blocking_js'], 0, 30 ) ),
				'fix'      => 'defer_scripts',
			)
		);

		// Duplicates.
		$dupe_css = $this->duplicates( $assets['css'] );
		$dupe_js  = $this->duplicates( $assets['js'] );

		$this->add(
			array(
				'id'       => 'duplicate_css',
				'label'    => __( 'Duplicate stylesheets', 'omninox-site-toolkit'),
				'value'    => (string) count( $dupe_css ),
				'weight'   => 1.5,
				'severity' => empty( $dupe_css ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'files' => $dupe_css ),
			)
		);

		$this->add(
			array(
				'id'       => 'duplicate_js',
				'label'    => __( 'Duplicate scripts', 'omninox-site-toolkit'),
				'value'    => (string) count( $dupe_js ),
				'weight'   => 1.5,
				'severity' => empty( $dupe_js ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'files' => $dupe_js ),
			)
		);

		// Google Fonts.
		$fonts = $this->google_fonts( $html );
		$this->add(
			array(
				'id'          => 'google_fonts',
				'label'       => __( 'Google Fonts requests', 'omninox-site-toolkit'),
				'value'       => (string) count( $fonts ),
				'expected'    => __( 'Self-hosted', 'omninox-site-toolkit'),
				'weight'      => 1.5,
				'severity'    => empty( $fonts ) ? self::SEV_PASS : self::SEV_NOTICE,
				'context'     => array( 'urls' => $fonts ),
				'fix'         => empty( $fonts ) ? '' : 'optimize_fonts',
				'description' => __( 'Third-party font requests add a DNS lookup and a connection before any text can render.', 'omninox-site-toolkit'),
			)
		);

		// Lazy loading.
		$images_without_lazy = $this->images_missing_lazy( $html );
		$this->add(
			array(
				'id'       => 'missing_lazy_load',
				'label'    => __( 'Images without lazy loading', 'omninox-site-toolkit'),
				'value'    => (string) $images_without_lazy,
				'weight'   => 1.5,
				'severity' => $images_without_lazy < 3 ? self::SEV_PASS : self::SEV_WARNING,
				'fix'      => 'lazy_load',
			)
		);

		// Compression.
		$encoding = strtolower( (string) ( $headers['content-encoding'] ?? '' ) );
		$this->add(
			array(
				'id'       => 'compression',
				'label'    => __( 'Text compression', 'omninox-site-toolkit'),
				'value'    => $encoding ? strtoupper( $encoding ) : __( 'None', 'omninox-site-toolkit'),
				'expected' => 'Brotli or GZIP',
				'weight'   => 2.5,
				'severity' => $encoding ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => $encoding ? '' : __( 'Enable Brotli or GZIP on the server. It typically cuts HTML and CSS transfer size by 70%.', 'omninox-site-toolkit'),
			)
		);

		// Page cache.
		$cache_plugin = $this->detected_cache_plugin();
		$cache_header = $this->cache_header( $headers );

		$this->add(
			array(
				'id'       => 'page_cache',
				'label'    => __( 'Page caching', 'omninox-site-toolkit'),
				'value'    => $cache_plugin ?: ( $cache_header ?: __( 'Not detected', 'omninox-site-toolkit') ),
				'weight'   => 3,
				'severity' => ( $cache_plugin || $cache_header ) ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		// CDN.
		$cdn = $this->detected_cdn( $headers, $html );
		$this->add(
			array(
				'id'       => 'cdn',
				'label'    => __( 'Content delivery network', 'omninox-site-toolkit'),
				'value'    => $cdn ?: __( 'Not detected', 'omninox-site-toolkit'),
				'weight'   => 1,
				'severity' => $cdn ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		// Heavy plugins by asset footprint.
		$by_plugin = $this->assets_by_plugin( array_merge( $assets['css'], $assets['js'] ) );
		arsort( $by_plugin );
		$heavy = array_slice( $by_plugin, 0, 8, true );

		$this->add(
			array(
				'id'       => 'plugin_asset_footprint',
				'label'    => __( 'Plugins loading the most assets', 'omninox-site-toolkit'),
				'value'    => (string) count( $by_plugin ),
				'weight'   => 2,
				'severity' => ( reset( $heavy ) > 6 ) ? self::SEV_WARNING : self::SEV_PASS,
				'context'  => array( 'plugins' => $heavy ),
				'description' => __( 'Assets are counted per plugin folder. Use the CSS & JS manager to unload them where they are not needed.', 'omninox-site-toolkit'),
			)
		);

		// Largest assets.
		$largest = $this->measure_largest( array_merge( $assets['css'], $assets['js'] ) );

		$breakdown = array(
			array( 'label' => __( 'Stylesheets', 'omninox-site-toolkit'), 'value' => count( $assets['css'] ) ),
			array( 'label' => __( 'Scripts', 'omninox-site-toolkit'), 'value' => count( $assets['js'] ) ),
			array( 'label' => __( 'Images', 'omninox-site-toolkit'), 'value' => count( $assets['img'] ) ),
			array( 'label' => __( 'Fonts', 'omninox-site-toolkit'), 'value' => count( $fonts ) ),
		);

		$weights = array();

		foreach ( array_slice( $largest, 0, 8 ) as $asset ) {
			$weights[] = array(
				'label' => wp_basename( strtok( $asset['url'], '?' ) ),
				'value' => (int) round( $asset['bytes'] / 1024 ),
				'unit'  => 'KB',
			);
		}

		return $this->result(
			array(
				'largest_assets'  => $largest,
				'ttfb_ms'         => $page['ms'],
				'asset_counts'    => array(
					'css'    => count( $assets['css'] ),
					'js'     => count( $assets['js'] ),
					'images' => count( $assets['img'] ),
				),
				'charts'          => array(
					array(
						'id'    => 'asset_breakdown',
						'type'  => 'bar',
						'title' => __( 'Requests by asset type', 'omninox-site-toolkit'),
						'data'  => $breakdown,
					),
					array(
						'id'    => 'largest_assets',
						'type'  => 'bar',
						'title' => __( 'Heaviest files on the home page', 'omninox-site-toolkit'),
						'data'  => $weights,
					),
					array(
						'id'    => 'plugin_footprint',
						'type'  => 'bar',
						'title' => __( 'Assets loaded per plugin', 'omninox-site-toolkit'),
						'data'  => array_map(
							static function ( $name, $count ) {
								return array( 'label' => $name, 'value' => $count );
							},
							array_keys( $heavy ),
							array_values( $heavy )
						),
					),
					array(
						'id'    => 'ttfb_history',
						'type'  => 'line',
						'title' => __( 'Performance score over time', 'omninox-site-toolkit'),
						'data'  => \OmninoxSiteToolkit\Database\ScanRepository::history( 'performance', 90 ),
					),
				),
				'recommendations' => $this->recommendations( $page, $assets, $encoding, $cache_plugin || $cache_header, $cdn, $fonts, $images_without_lazy ),
			)
		);
	}

	/**
	 * Ranked, concrete suggestions with the estimated gain and the action
	 * that applies each one.
	 *
	 * @param array  $page      Fetched page.
	 * @param array  $assets    Parsed assets.
	 * @param string $encoding  Content encoding.
	 * @param bool   $cached    Whether page caching was detected.
	 * @param string $cdn       CDN name.
	 * @param array  $fonts     Google font URLs.
	 * @param int    $lazy_gaps Images with no lazy loading.
	 * @return array
	 */
	private function recommendations( array $page, array $assets, $encoding, $cached, $cdn, array $fonts, $lazy_gaps ) {
		$out = array();

		if ( ! $encoding ) {
			$out[] = array(
				'title'  => __( 'Turn on text compression', 'omninox-site-toolkit'),
				'gain'   => __( 'Around 70% smaller HTML, CSS and JavaScript', 'omninox-site-toolkit'),
				'effort' => __( 'Server setting', 'omninox-site-toolkit'),
				'how'    => __( 'Enable Brotli or GZIP in the server configuration, or switch it on at your CDN. This is the single highest-value change on this list and it carries no risk.', 'omninox-site-toolkit'),
				'action' => '',
				'score'  => 100,
			);
		}

		if ( ! $cached ) {
			$out[] = array(
				'title'  => __( 'Add page caching', 'omninox-site-toolkit'),
				'gain'   => sprintf(
					/* translators: %d: milliseconds */
					__( 'Cuts most of the current %d ms server response', 'omninox-site-toolkit'),
					$page['ms']
				),
				'effort' => __( 'Install one plugin', 'omninox-site-toolkit'),
				'how'    => __( 'Install a single caching plugin and exclude cart, checkout and account pages. Do not run two cache plugins at once.', 'omninox-site-toolkit'),
				'action' => '',
				'score'  => 95,
			);
		}

		if ( count( $assets['blocking_js'] ) > 0 ) {
			$out[] = array(
				'title'  => sprintf(
					/* translators: %d: number of scripts */
					__( 'Defer %d render-blocking scripts', 'omninox-site-toolkit'),
					count( $assets['blocking_js'] )
				),
				'gain'   => __( 'Faster first paint and a better Largest Contentful Paint', 'omninox-site-toolkit'),
				'effort' => __( 'One toggle per script, test as you go', 'omninox-site-toolkit'),
				'how'    => __( 'Open the CSS & JS manager and set each non-critical script to defer, one at a time, checking sliders, menus and forms after each change.', 'omninox-site-toolkit'),
				'action' => '',
				'score'  => 80,
			);
		}

		if ( $lazy_gaps > 2 ) {
			$out[] = array(
				'title'  => __( 'Lazy-load offscreen images', 'omninox-site-toolkit'),
				'gain'   => sprintf(
					/* translators: %d: number of images */
					__( 'Defers %d images the visitor may never scroll to', 'omninox-site-toolkit'),
					$lazy_gaps
				),
				'effort' => __( 'One toggle', 'omninox-site-toolkit'),
				'how'    => __( 'Enable lazy loading in the actions above. It deliberately skips the first images so the hero is not delayed.', 'omninox-site-toolkit'),
				'action' => 'lazy_load',
				'score'  => 70,
			);
		}

		if ( $fonts ) {
			$out[] = array(
				'title'  => __( 'Self-host your fonts', 'omninox-site-toolkit'),
				'gain'   => __( 'Removes a DNS lookup and a connection before any text renders', 'omninox-site-toolkit'),
				'effort' => __( 'Download and re-reference', 'omninox-site-toolkit'),
				'how'    => __( 'Download the font files, serve them from your own domain, and set font-display to swap so text is visible while they load.', 'omninox-site-toolkit'),
				'action' => 'optimize_fonts',
				'score'  => 55,
			);
		}

		if ( count( $assets['css'] ) > 8 ) {
			$out[] = array(
				'title'  => sprintf(
					/* translators: %d: number of stylesheets */
					__( 'Reduce %d stylesheets', 'omninox-site-toolkit'),
					count( $assets['css'] )
				),
				'gain'   => __( 'Every stylesheet in the head delays first paint', 'omninox-site-toolkit'),
				'effort' => __( 'Per-page unloading', 'omninox-site-toolkit'),
				'how'    => __( 'Most of these come from plugins loading everywhere. Use the CSS & JS manager to restrict each one to the pages that need it.', 'omninox-site-toolkit'),
				'action' => '',
				'score'  => 50,
			);
		}

		if ( ! $cdn ) {
			$out[] = array(
				'title'  => __( 'Consider a CDN', 'omninox-site-toolkit'),
				'gain'   => __( 'Shorter round trips for visitors far from your server', 'omninox-site-toolkit'),
				'effort' => __( 'DNS change', 'omninox-site-toolkit'),
				'how'    => __( 'Worth doing if you have international visitors or heavy media. Not worth it for a local business site with one region of traffic.', 'omninox-site-toolkit'),
				'action' => '',
				'score'  => 30,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $out;
	}

	/**
	 * Handle optimization toggles.
	 *
	 * @param string $action Action id.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		$toggles = array( 'disable_emojis', 'disable_embeds', 'disable_dashicons', 'disable_jquery_migrate', 'remove_query_strings', 'lazy_load', 'minify_css', 'minify_js', 'optimize_fonts' );

		if ( in_array( $action, array( 'minify_css', 'minify_js', 'lazy_load' ), true ) ) {
			if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'performance', $action ) ) {
				return new \WP_Error(
					'omninox_pro_required',
					__( 'This optimization feature requires Omninox Pro.', 'omninox-site-toolkit' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( in_array( $action, $toggles, true ) ) {
			$settings = Settings::instance();
			$before   = ! empty( $settings->get( 'optimizations.' . $action ) );
			$enabled  = ! empty( $params['value'] );

			$current_opts            = (array) $settings->get( 'optimizations', array() );
			$current_opts[ $action ] = $enabled;

			$settings->update( array( 'optimizations' => $current_opts ) );
			Optimizations::flush_cache();

			if ( $before !== $enabled ) {
				ValueHistory::record( 'opt_' . $action, $before, $enabled, $action );
			}

			return array(
				'enabled'  => $enabled,
				'previous' => $before ? __( 'on', 'omninox-site-toolkit') : __( 'off', 'omninox-site-toolkit'),
				'current'  => $enabled ? __( 'on', 'omninox-site-toolkit') : __( 'off', 'omninox-site-toolkit'),
				'message'  => $enabled
					? __( 'Optimization enabled. Clear any external cache to see the change.', 'omninox-site-toolkit')
					: __( 'Optimization disabled.', 'omninox-site-toolkit'),
			);
		}

		if ( 'clear_cache' === $action ) {
			$removed = Optimizations::flush_cache();

			/* translators: %d: number of files removed */
			return array( 'message' => sprintf( _n( 'Removed %d cached file.', 'Removed %d cached files.', $removed, 'omninox-site-toolkit'), $removed ) );
		}

		if ( 'optimize_fonts' === $action ) {
			Settings::instance()->update( array( 'optimizations' => array( 'optimize_fonts' => true ) ) );

			return array( 'message' => __( 'Font requests will now be preconnected and swapped to display:swap.', 'omninox-site-toolkit') );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Pull assets out of the HTML.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function parse_assets( $html ) {
		$out = array( 'css' => array(), 'js' => array(), 'img' => array(), 'blocking_js' => array() );

		if ( preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( preg_match( '/href=["\']([^"\']+)["\']/i', $tag, $m ) ) {
					$out['css'][] = $m[1];
				}
			}
		}

		$head = substr( $html, 0, (int) ( stripos( $html, '</head>' ) ?: strlen( $html ) ) );

		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $match ) {
				$out['js'][] = $match[1];

				$is_deferred = preg_match( '/\b(defer|async|type=["\']module["\'])/i', $match[0] );

				if ( ! $is_deferred && false !== strpos( $head, $match[1] ) ) {
					$out['blocking_js'][] = $match[1];
				}
			}
		}

		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $images ) ) {
			$out['img'] = $images[1];
		}

		return $out;
	}

	/**
	 * Find URLs appearing more than once (ignoring version strings).
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	private function duplicates( array $urls ) {
		$normalised = array_map(
			static function ( $url ) {
				return strtok( $url, '?' );
			},
			$urls
		);

		$counts = array_count_values( $normalised );

		return array_values( array_keys( array_filter( $counts, static function ( $n ) {
			return $n > 1;
		} ) ) );
	}

	/**
	 * Google Fonts URLs found in the markup.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function google_fonts( $html ) {
		preg_match_all( '#https?://fonts\.(googleapis|gstatic)\.com[^"\']+#i', $html, $matches );

		return array_values( array_unique( $matches[0] ?? array() ) );
	}

	/**
	 * Count images with no loading attribute.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	private function images_missing_lazy( $html ) {
		preg_match_all( '/<img[^>]*>/i', $html, $images );

		$missing = 0;

		foreach ( $images[0] ?? array() as $tag ) {
			if ( ! preg_match( '/loading=["\']lazy["\']/i', $tag ) ) {
				++$missing;
			}
		}

		return max( 0, $missing - 2 ); // Above-the-fold images should not be lazy.
	}

	/**
	 * Which caching plugin is active?
	 *
	 * @return string
	 */
	private function detected_cache_plugin() {
		$known = array(
			'wp-rocket/wp-rocket.php'         => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'     => 'WP Super Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php' => 'Cache Enabler',
			'nitropack/main.php'              => 'NitroPack',
		);

		foreach ( $known as $file => $name ) {
			if ( Utils::plugin_active( $file ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Look for cache hit headers.
	 *
	 * @param array $headers Response headers.
	 * @return string
	 */
	private function cache_header( array $headers ) {
		foreach ( array( 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'x-nginx-cache' ) as $key ) {
			if ( ! empty( $headers[ $key ] ) ) {
				return $key . ': ' . ( is_array( $headers[ $key ] ) ? implode( ',', $headers[ $key ] ) : $headers[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Detect a CDN from headers or asset hostnames.
	 *
	 * @param array  $headers Headers.
	 * @param string $html    HTML.
	 * @return string
	 */
	private function detected_cdn( array $headers, $html ) {
		if ( ! empty( $headers['cf-ray'] ) ) {
			return 'Cloudflare';
		}

		if ( ! empty( $headers['x-served-by'] ) ) {
			return 'Fastly';
		}

		$cdn_signatures = array(
			'bunny' . 'cdn'        => 'BunnyCDN',
			'cloudfront' . '.net'  => 'Amazon CloudFront',
			'kinstacdn' . '.com'   => 'Kinsta CDN',
			'stackpathcdn' . '.com' => 'StackPath',
		);

		foreach ( $cdn_signatures as $needle => $name ) {
			if ( false !== stripos( $html, $needle ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Group asset URLs by owning plugin folder.
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	private function assets_by_plugin( array $urls ) {
		$counts = array();

		foreach ( $urls as $url ) {
			if ( preg_match( '#/(?:plugins|mu-plugins)/([^/]+)/#i', $url, $m ) ) {
				$counts[ $m[1] ] = ( $counts[ $m[1] ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Measure the biggest local assets from disk (no extra HTTP requests).
	 *
	 * @param array $urls URLs.
	 * @return array
	 */
	private function measure_largest( array $urls ) {
		$sizes = array();

		foreach ( array_unique( $urls ) as $url ) {
			$path = Utils::url_to_path( $url );

			if ( $path && is_readable( $path ) ) {
				$sizes[] = array(
					'url'   => $url,
					'bytes' => filesize( $path ),
					'size'  => Utils::format_bytes( filesize( $path ) ),
				);
			}
		}

		usort(
			$sizes,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return array_slice( $sizes, 0, 15 );
	}
}
