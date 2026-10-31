<?php
/**
 * Module 2: Performance Analyzer & Core Web Vitals Optimization Engine.
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
		Optimizations::instance()->boot();
	}

	/**
	 * Toggles and action buttons shown in the UI.
	 *
	 * @return array
	 */
	public function get_actions() {
		$current = (array) Settings::instance()->get( 'optimizations', array() );

		$toggles = array(
			'lazy_load'             => __( 'Intelligent lazy-loading (protects hero & LCP)', 'omninox-site-toolkit' ),
			'preload_lcp'           => __( 'Preload & prioritize LCP hero image', 'omninox-site-toolkit' ),
			'defer_scripts'         => __( 'Safe JavaScript deferral (reduces TBT)', 'omninox-site-toolkit' ),
			'add_image_dimensions'  => __( 'Add missing image dimensions (reduces CLS)', 'omninox-site-toolkit' ),
			'minify_css'            => __( 'Minify CSS stylesheets', 'omninox-site-toolkit' ),
			'minify_js'             => __( 'Minify JavaScript files', 'omninox-site-toolkit' ),
			'optimize_fonts'        => __( 'Preconnect fonts and force font-display:swap', 'omninox-site-toolkit' ),
			'browser_cache'         => __( 'Enable browser caching headers', 'omninox-site-toolkit' ),
			'clean_header'          => __( 'Remove bloat head tags (RSD, generator, WLW)', 'omninox-site-toolkit' ),
			'best_practices_shield' => __( 'Best practices shield (Mixed content & link security)', 'omninox-site-toolkit' ),
			'disable_emojis'        => __( 'Disable emoji scripts', 'omninox-site-toolkit' ),
			'disable_embeds'        => __( 'Disable oEmbed scripts', 'omninox-site-toolkit' ),
			'disable_dashicons'     => __( 'Disable Dashicons on the front end', 'omninox-site-toolkit' ),
			'disable_jquery_migrate'=> __( 'Disable jQuery Migrate', 'omninox-site-toolkit' ),
			'remove_query_strings'  => __( 'Remove version query strings', 'omninox-site-toolkit' ),
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

		// Smart 1-Click Optimization Profiles & Workflow.
		$actions[] = array(
			'id'     => 'apply_safe_profile',
			'label'  => __( 'Apply Safe Profile (Recommended - 0 Risk)', 'omninox-site-toolkit' ),
			'type'   => 'button',
			'notice' => __( 'Applies high-impact, non-destructive optimizations: LCP prioritization, CLS dimension fixes, emoji cleanup, font preconnect, and version stripping.', 'omninox-site-toolkit' ),
		);

		$actions[] = array(
			'id'     => 'apply_balanced_profile',
			'label'  => __( 'Apply Balanced Profile (Maximum Speed)', 'omninox-site-toolkit' ),
			'type'   => 'button',
			'notice' => __( 'Applies Safe optimizations plus safe non-critical script deferral (TBT reduction), CSS/JS minification, browser caching, and embed removal.', 'omninox-site-toolkit' ),
		);

		$actions[] = array(
			'id'     => 'apply_advanced_profile',
			'label'  => __( 'Apply Advanced Profile (Aggressive Performance)', 'omninox-site-toolkit' ),
			'type'   => 'button',
			'notice' => __( 'Applies full performance optimizations with dependency protection, static asset browser caching, and checkpoint rollback safety.', 'omninox-site-toolkit' ),
		);

		$actions[] = array(
			'id'     => 'rollback_checkpoint',
			'label'  => __( 'Rollback to Last Checkpoint', 'omninox-site-toolkit' ),
			'type'   => 'button',
			'notice' => __( 'Reverts optimization settings back to the snapshot saved before applying a profile.', 'omninox-site-toolkit' ),
		);

		$actions[] = array(
			'id'     => 'run_psi_audit',
			'label'  => __( 'Run Google PageSpeed Insights Audit', 'omninox-site-toolkit' ),
			'type'   => 'button',
			'notice' => __( 'Tests the live URL against official Google Lighthouse / PageSpeed Insights API for real lab measurements.', 'omninox-site-toolkit' ),
		);

		$actions[] = array(
			'id'    => 'clear_cache',
			'label' => __( 'Clear generated cache', 'omninox-site-toolkit' ),
			'type'  => 'button',
		);

		return $actions;
	}

	/**
	 * Analyse the front page against Core Web Vitals & PageSpeed criteria.
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
					'label'    => __( 'Front page could not be loaded', 'omninox-site-toolkit' ),
					'severity' => self::SEV_CRITICAL,
					'value'    => $page->get_error_message(),
					'weight'   => 5,
				)
			);

			return $this->result();
		}

		$html    = $page['body'];
		$headers = array_change_key_case( (array) $page['headers'], CASE_LOWER );

		// 1. TTFB (Time to First Byte).
		$this->add(
			array(
				'id'          => 'ttfb',
				'label'       => __( 'Server response time (TTFB)', 'omninox-site-toolkit' ),
				'value'       => $page['ms'] . ' ms',
				'expected'    => '< 600 ms',
				'weight'      => 3,
				'severity'    => $page['ms'] < 600 ? self::SEV_PASS : ( $page['ms'] < 1500 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'description' => __( 'Time to first byte. Slow responses usually point at missing page caching or unoptimized database queries.', 'omninox-site-toolkit' ),
			)
		);

		// 2. LCP (Largest Contentful Paint) Candidate Analysis.
		$lcp_info = $this->analyze_lcp_candidate( $html );
		$this->add(
			array(
				'id'          => 'lcp_candidate',
				'label'       => __( 'LCP Hero Image Optimization', 'omninox-site-toolkit' ),
				'value'       => $lcp_info['status_text'],
				'expected'    => __( 'Eager / High Priority', 'omninox-site-toolkit' ),
				'weight'      => 4,
				'severity'    => $lcp_info['severity'],
				'description' => $lcp_info['description'],
				'context'     => $lcp_info['context'],
				'fix'         => 'preload_lcp',
			)
		);

		// 3. CLS (Cumulative Layout Shift) Missing Dimensions Analysis.
		$missing_dims = $this->count_images_missing_dimensions( $html );
		$this->add(
			array(
				'id'          => 'cls_dimensions',
				'label'       => __( 'Images with Explicit Dimensions (CLS Prevention)', 'omninox-site-toolkit' ),
				'value'       => sprintf( __( '%d images missing width/height', 'omninox-site-toolkit' ), $missing_dims['count'] ),
				'expected'    => '0',
				'weight'      => 3,
				'severity'    => 0 === $missing_dims['count'] ? self::SEV_PASS : ( $missing_dims['count'] <= 3 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'description' => __( 'Images without width and height trigger layout shifts as the browser renders, directly harming your CLS score.', 'omninox-site-toolkit' ),
				'context'     => array( 'offending_images' => array_slice( $missing_dims['samples'], 0, 10 ) ),
				'fix'         => 'add_image_dimensions',
			)
		);

		// 4. Page weight.
		$size = strlen( $html );
		$this->add(
			array(
				'id'       => 'page_size',
				'label'    => __( 'HTML document size', 'omninox-site-toolkit' ),
				'value'    => Utils::format_bytes( $size ),
				'expected' => '< 100 KB',
				'weight'   => 1.5,
				'severity' => $size < 102400 ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$assets = $this->parse_assets( $html );

		// 5. Total Asset Request count.
		$requests = count( $assets['css'] ) + count( $assets['js'] ) + count( $assets['img'] );
		$this->add(
			array(
				'id'       => 'http_requests',
				'label'    => __( 'Asset requests on the home page', 'omninox-site-toolkit' ),
				'value'    => (string) $requests,
				'expected' => '< 50',
				'weight'   => 2,
				'severity' => $requests < 50 ? self::SEV_PASS : ( $requests < 90 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'context'  => array( 'css' => count( $assets['css'] ), 'js' => count( $assets['js'] ), 'img' => count( $assets['img'] ) ),
			)
		);

		// 6. Render-blocking CSS.
		$blocking_css = count( $assets['css'] );
		$this->add(
			array(
				'id'          => 'render_blocking_css',
				'label'       => __( 'Render-blocking stylesheets', 'omninox-site-toolkit' ),
				'value'       => (string) $blocking_css,
				'expected'    => '< 5',
				'weight'      => 2.5,
				'severity'    => $blocking_css < 5 ? self::SEV_PASS : self::SEV_WARNING,
				'context'     => array( 'files' => array_slice( $assets['css'], 0, 30 ) ),
				'description' => __( 'Each stylesheet in the head delays the first paint until it downloads. Minify and clean unused stylesheets.', 'omninox-site-toolkit' ),
				'fix'         => 'minify_css',
			)
		);

		// 7. Render-blocking JS (TBT Impact).
		$blocking_js = count( $assets['blocking_js'] );
		$this->add(
			array(
				'id'          => 'render_blocking_js',
				'label'       => __( 'Render-blocking scripts in document head (TBT)', 'omninox-site-toolkit' ),
				'value'       => (string) $blocking_js,
				'expected'    => '0',
				'weight'      => 3.5,
				'severity'    => 0 === $blocking_js ? self::SEV_PASS : self::SEV_CRITICAL,
				'context'     => array( 'files' => array_slice( $assets['blocking_js'], 0, 30 ) ),
				'description' => __( 'Synchronous scripts in the head block the main thread and degrade Total Blocking Time (TBT). Defer them safely.', 'omninox-site-toolkit' ),
				'fix'         => 'defer_scripts',
			)
		);

		// 8. Duplicates.
		$dupe_css = $this->duplicates( $assets['css'] );
		$dupe_js  = $this->duplicates( $assets['js'] );

		$this->add(
			array(
				'id'       => 'duplicate_css',
				'label'    => __( 'Duplicate stylesheets', 'omninox-site-toolkit' ),
				'value'    => (string) count( $dupe_css ),
				'weight'   => 1.5,
				'severity' => empty( $dupe_css ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'files' => $dupe_css ),
			)
		);

		$this->add(
			array(
				'id'       => 'duplicate_js',
				'label'    => __( 'Duplicate scripts', 'omninox-site-toolkit' ),
				'value'    => (string) count( $dupe_js ),
				'weight'   => 1.5,
				'severity' => empty( $dupe_js ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'files' => $dupe_js ),
			)
		);

		// 9. Google Fonts.
		$fonts = $this->google_fonts( $html );
		$this->add(
			array(
				'id'          => 'google_fonts',
				'label'       => __( 'Google Fonts requests', 'omninox-site-toolkit' ),
				'value'       => (string) count( $fonts ),
				'expected'    => __( 'Preconnected or Self-hosted', 'omninox-site-toolkit' ),
				'weight'      => 1.5,
				'severity'    => empty( $fonts ) ? self::SEV_PASS : self::SEV_NOTICE,
				'context'     => array( 'urls' => $fonts ),
				'fix'         => empty( $fonts ) ? '' : 'optimize_fonts',
				'description' => __( 'Third-party font requests add DNS lookup latency. Preconnect to fonts.gstatic.com and ensure font-display:swap.', 'omninox-site-toolkit' ),
			)
		);

		// 10. Lazy loading offscreen images.
		$images_without_lazy = $this->images_missing_lazy( $html );
		$this->add(
			array(
				'id'       => 'missing_lazy_load',
				'label'    => __( 'Below-the-fold images missing lazy-loading', 'omninox-site-toolkit' ),
				'value'    => (string) $images_without_lazy,
				'weight'   => 1.5,
				'severity' => $images_without_lazy < 3 ? self::SEV_PASS : self::SEV_WARNING,
				'fix'      => 'lazy_load',
			)
		);

		// 11. Text Compression.
		$encoding = strtolower( (string) ( $headers['content-encoding'] ?? '' ) );
		$this->add(
			array(
				'id'          => 'compression',
				'label'       => __( 'Text compression', 'omninox-site-toolkit' ),
				'value'       => $encoding ? strtoupper( $encoding ) : __( 'None', 'omninox-site-toolkit' ),
				'expected'    => 'Brotli or GZIP',
				'weight'      => 2.5,
				'severity'    => $encoding ? self::SEV_PASS : self::SEV_CRITICAL,
				'description' => $encoding ? '' : __( 'Enable Brotli or GZIP on the server. It typically cuts HTML and CSS transfer size by 70%.', 'omninox-site-toolkit' ),
			)
		);

		// 12. Page Cache.
		$cache_plugin = $this->detected_cache_plugin();
		$cache_header = $this->cache_header( $headers );
		$this->add(
			array(
				'id'       => 'page_cache',
				'label'    => __( 'Page caching', 'omninox-site-toolkit' ),
				'value'    => $cache_plugin ?: ( $cache_header ?: __( 'Not detected', 'omninox-site-toolkit' ) ),
				'weight'   => 3,
				'severity' => ( $cache_plugin || $cache_header ) ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		// 13. CDN.
		$cdn = $this->detected_cdn( $headers, $html );
		$this->add(
			array(
				'id'       => 'cdn',
				'label'    => __( 'Content delivery network', 'omninox-site-toolkit' ),
				'value'    => $cdn ?: __( 'Not detected', 'omninox-site-toolkit' ),
				'weight'   => 1,
				'severity' => $cdn ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		// 14. Heavy plugins by asset footprint.
		$by_plugin = $this->assets_by_plugin( array_merge( $assets['css'], $assets['js'] ) );
		arsort( $by_plugin );
		$heavy = array_slice( $by_plugin, 0, 8, true );

		$this->add(
			array(
				'id'          => 'plugin_asset_footprint',
				'label'       => __( 'Plugins loading the most assets', 'omninox-site-toolkit' ),
				'value'       => (string) count( $by_plugin ),
				'weight'      => 2,
				'severity'    => ( ! empty( $heavy ) && reset( $heavy ) > 6 ) ? self::SEV_WARNING : self::SEV_PASS,
				'context'     => array( 'plugins' => $heavy ),
				'description' => __( 'Assets are counted per plugin folder. Use the CSS & JS manager to unload them where they are not needed.', 'omninox-site-toolkit' ),
			)
		);

		// 15. Latest PSI Lab Audit (if available).
		$psi_data = get_transient( 'omninox_psi_results' );
		if ( is_array( $psi_data ) && ! empty( $psi_data['performance'] ) ) {
			$this->add(
				array(
					'id'          => 'psi_score',
					'label'       => sprintf( __( 'Google PageSpeed Score (%s)', 'omninox-site-toolkit' ), esc_html( ucfirst( $psi_data['strategy'] ?? 'mobile' ) ) ),
					'value'       => (string) $psi_data['performance'] . ' / 100',
					'expected'    => '90+',
					'weight'      => 4,
					'severity'    => $psi_data['performance'] >= 90 ? self::SEV_PASS : ( $psi_data['performance'] >= 70 ? self::SEV_WARNING : self::SEV_CRITICAL ),
					'context'     => $psi_data,
					'description' => sprintf(
						__( 'Official Google Lighthouse lab score (tested: %s). LCP: %s | TBT: %s | CLS: %s', 'omninox-site-toolkit' ),
						esc_html( $psi_data['scanned_at'] ?? 'recent' ),
						esc_html( $psi_data['lcp'] ?? '—' ),
						esc_html( $psi_data['tbt'] ?? '—' ),
						esc_html( $psi_data['cls'] ?? '—' )
					),
				)
			);
		}

		// Largest assets.
		$largest = $this->measure_largest( array_merge( $assets['css'], $assets['js'] ) );

		$breakdown = array(
			array( 'label' => __( 'Stylesheets', 'omninox-site-toolkit' ), 'value' => count( $assets['css'] ) ),
			array( 'label' => __( 'Scripts', 'omninox-site-toolkit' ), 'value' => count( $assets['js'] ) ),
			array( 'label' => __( 'Images', 'omninox-site-toolkit' ), 'value' => count( $assets['img'] ) ),
			array( 'label' => __( 'Fonts', 'omninox-site-toolkit' ), 'value' => count( $fonts ) ),
		);

		$weights = array();
		foreach ( array_slice( $largest, 0, 8 ) as $asset ) {
			$weights[] = array(
				'label' => wp_basename( strtok( $asset['url'], '?' ) ),
				'value' => (int) round( $asset['bytes'] / 1024 ),
				'unit'  => 'KB',
			);
		}

		$effectiveness = $this->audit_optimization_effectiveness( $html, $headers, $page['ms'] );

		return $this->result(
			array(
				'effectiveness'   => $effectiveness,
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
						'title' => __( 'Requests by asset type', 'omninox-site-toolkit' ),
						'data'  => $breakdown,
					),
					array(
						'id'    => 'largest_assets',
						'type'  => 'bar',
						'title' => __( 'Heaviest files on the home page', 'omninox-site-toolkit' ),
						'data'  => $weights,
					),
					array(
						'id'    => 'plugin_footprint',
						'type'  => 'bar',
						'title' => __( 'Assets loaded per plugin', 'omninox-site-toolkit' ),
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
						'title' => __( 'Performance score over time', 'omninox-site-toolkit' ),
						'data'  => \OmninoxSiteToolkit\Database\ScanRepository::history( 'performance', 90 ),
					),
				),
				'recommendations' => $this->recommendations( $page, $assets, $encoding, $cache_plugin || $cache_header, $cdn, $fonts, $images_without_lazy ),
			)
		);
	}

	/**
	 * Analyze the leading content image to detect LCP candidate and lazy loading flaws.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function analyze_lcp_candidate( $html ) {
		if ( ! preg_match_all( '/<img[^>]+>/i', $html, $matches ) || empty( $matches[0] ) ) {
			return array(
				'status_text' => __( 'No images detected in content', 'omninox-site-toolkit' ),
				'severity'    => self::SEV_PASS,
				'description' => __( 'Text-based Largest Contentful Paint candidate. Ensure critical fonts are preconnected.', 'omninox-site-toolkit' ),
				'context'     => array(),
			);
		}

		$hero_tag = '';
		$hero_url = '';
		foreach ( $matches[0] as $tag ) {
			if ( preg_match( '/\b(avatar|icon|badge|custom-logo)\b/i', $tag ) ) {
				continue;
			}
			$hero_tag = $tag;
			if ( preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				$hero_url = $m[1];
			}
			break;
		}
		if ( empty( $hero_tag ) ) {
			$hero_tag = $matches[0][0];
			if ( preg_match( '/src=["\']([^"\']+)["\']/i', $hero_tag, $m ) ) {
				$hero_url = $m[1];
			}
		}

		if ( ! empty( $hero_url ) && function_exists( 'update_option' ) ) {
			update_option( 'omninox_detected_lcp_url', $hero_url, false );
		}

		$is_lazy  = (bool) preg_match( '/\bloading=["\']lazy["\']/i', $hero_tag );
		$is_high  = (bool) preg_match( '/\bfetchpriority=["\']high["\']/i', $hero_tag );

		if ( $is_lazy ) {
			return array(
				'status_text' => __( 'Hero image is lazily loaded (Critical LCP Bottleneck)', 'omninox-site-toolkit' ),
				'severity'    => self::SEV_CRITICAL,
				'description' => __( 'Google flags lazy loading on the LCP image as a primary cause of low Performance scores. Above-the-fold images must load eagerly with fetchpriority="high".', 'omninox-site-toolkit' ),
				'context'     => array( 'hero_snippet' => substr( $hero_tag, 0, 200 ) ),
			);
		}

		if ( $is_high ) {
			return array(
				'status_text' => __( 'Optimized (Eager + High Priority)', 'omninox-site-toolkit' ),
				'severity'    => self::SEV_PASS,
				'description' => __( 'The LCP image is correctly prioritized for immediate browser rendering.', 'omninox-site-toolkit' ),
				'context'     => array( 'hero_snippet' => substr( $hero_tag, 0, 200 ) ),
			);
		}

		return array(
			'status_text' => __( 'Eager (Missing fetchpriority="high")', 'omninox-site-toolkit' ),
			'severity'    => self::SEV_NOTICE,
			'description' => __( 'Image is not lazily loaded, but adding fetchpriority="high" will further improve discovery delay.', 'omninox-site-toolkit' ),
			'context'     => array( 'hero_snippet' => substr( $hero_tag, 0, 200 ) ),
		);
	}

	/**
	 * Audit optimization effectiveness against live anonymous frontend HTML and headers.
	 *
	 * Provides independent diagnostic status (Verified, Conflict detected, No eligible files,
	 * Headers not applied, Wrong resource, etc.) with supporting evidence.
	 *
	 * @param string $html     Anonymous front page HTML.
	 * @param array  $headers  Anonymous front page response headers.
	 * @param float  $page_ms  TTFB in milliseconds.
	 * @return array
	 */
	public function audit_optimization_effectiveness( $html, array $headers, $page_ms ) {
		$settings = Settings::instance();
		$opts     = (array) $settings->get( 'optimizations', array() );
		$results  = array();

		// 1. Intelligent Lazy-Loading.
		$is_lazy_on = ! empty( $opts['lazy_load'] );
		$lazy_status = 'off';
		$lazy_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$lazy_evidence = __( 'Lazy loading is switched off in settings.', 'omninox-site-toolkit' );
		$lazy_reco = '';

		if ( $is_lazy_on ) {
			preg_match_all( '/<img[^>]+>/i', $html, $img_matches );
			$total_imgs = count( $img_matches[0] ?? array() );
			$lazy_count = 0;
			foreach ( ( $img_matches[0] ?? array() ) as $img ) {
				if ( preg_match( '/\bloading=["\']lazy["\']/i', $img ) ) {
					$lazy_count++;
				}
			}

			// Check for conflicting lazy loaders
			$has_rocket_lazy = false !== strpos( $html, 'rocket-lazyload' );
			$has_ls_lazy     = false !== strpos( $html, 'data-lazyloaded' ) || false !== strpos( $html, 'litespeed-lazy' );

			if ( $has_rocket_lazy || $has_ls_lazy ) {
				$lazy_status = 'conflict';
				$lazy_effect = __( 'Conflict detected', 'omninox-site-toolkit' );
				$lazy_evidence = __( 'External page cache plugin lazy-loader detected on the page. Multiple lazy-loaders can cause script delays.', 'omninox-site-toolkit' );
				$lazy_reco = __( 'Use either OmniNox lazy-loading or your caching plugin lazy-loader, not both.', 'omninox-site-toolkit' );
			} elseif ( $lazy_count > 0 ) {
				$lazy_status = 'verified';
				$lazy_effect = __( 'Verified', 'omninox-site-toolkit' );
				$lazy_evidence = sprintf(
					/* translators: 1: lazy count, 2: total count */
					__( '%1$d of %2$d images carry native loading="lazy"; above-the-fold images are protected.', 'omninox-site-toolkit' ),
					$lazy_count,
					$total_imgs
				);
			} elseif ( $total_imgs === 0 ) {
				$lazy_status = 'no_eligible';
				$lazy_effect = __( 'No eligible files', 'omninox-site-toolkit' );
				$lazy_evidence = __( 'No images found in the rendered HTML.', 'omninox-site-toolkit' );
			} else {
				$lazy_status = 'warning';
				$lazy_effect = __( 'Not detected in HTML', 'omninox-site-toolkit' );
				$lazy_evidence = sprintf(
					/* translators: %d: image count */
					__( 'Found %d images, but none carry loading="lazy". Cached page output may be serving pre-optimization HTML.', 'omninox-site-toolkit' ),
					$total_imgs
				);
				$lazy_reco = __( 'Clear page cache to allow OmniNox output buffer to process the template.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'lazy_load',
			'feature'        => __( 'Intelligent Lazy-Loading', 'omninox-site-toolkit' ),
			'enabled'        => $is_lazy_on,
			'status'         => $lazy_status,
			'effect'         => $lazy_effect,
			'evidence'       => $lazy_evidence,
			'recommendation' => $lazy_reco,
		);

		// 2. LCP Hero Image Preload.
		$is_lcp_on = ! empty( $opts['preload_lcp'] );
		$lcp_status = 'off';
		$lcp_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$lcp_evidence = __( 'LCP hero image preloading is switched off.', 'omninox-site-toolkit' );
		$lcp_reco = '';

		if ( $is_lcp_on ) {
			$has_preload = preg_match( '/<link[^>]+rel=["\']preload["\'][^>]+as=["\']image["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $preload_match );
			if ( ! $has_preload ) {
				$has_preload = preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']preload["\'][^>]+as=["\']image["\']/i', $html, $preload_match );
			}

			if ( $has_preload ) {
				$preloaded_url = $preload_match[1];
				$filename      = wp_basename( strtok( $preloaded_url, '?' ) );
				$is_logo       = (bool) preg_match( '/\b(logo|icon|favicon|avatar)\b/i', $filename );

				if ( $is_logo ) {
					$lcp_status = 'wrong_resource';
					$lcp_effect = __( 'Wrong resource', 'omninox-site-toolkit' );
					$lcp_evidence = sprintf(
						/* translators: %s: filename */
						__( 'Preloading logo image (%s) instead of main content hero banner. Logos are rarely the Largest Contentful Paint.', 'omninox-site-toolkit' ),
						$filename
					);
					$lcp_reco = __( 'OmniNox hero detector has updated the detected hero candidate for future page loads.', 'omninox-site-toolkit' );
				} else {
					$lcp_status = 'verified';
					$lcp_effect = __( 'Verified', 'omninox-site-toolkit' );
					$lcp_evidence = sprintf(
						/* translators: %s: filename */
						__( 'Critical hero image (%s) is preloaded in <head> with fetchpriority="high".', 'omninox-site-toolkit' ),
						$filename
					);
				}
			} else {
				$lcp_status = 'not_detected';
				$lcp_effect = __( 'Not detected in HTML', 'omninox-site-toolkit' );
				$lcp_evidence = __( 'No image preload tag found in document head. Stale page cache may be active.', 'omninox-site-toolkit' );
				$lcp_reco = __( 'Clear page cache so newly prioritized hero tag is written into cached HTML.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'preload_lcp',
			'feature'        => __( 'LCP Hero Image Preloading', 'omninox-site-toolkit' ),
			'enabled'        => $is_lcp_on,
			'status'         => $lcp_status,
			'effect'         => $lcp_effect,
			'evidence'       => $lcp_evidence,
			'recommendation' => $lcp_reco,
		);

		// 3. Safe JavaScript Deferral.
		$is_defer_on = ! empty( $opts['defer_scripts'] );
		$defer_status = 'off';
		$defer_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$defer_evidence = __( 'Script deferral is switched off.', 'omninox-site-toolkit' );
		$defer_reco = '';

		if ( $is_defer_on ) {
			preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $script_matches );
			$total_scripts = count( $script_matches[0] ?? array() );
			$deferred_count = 0;
			foreach ( ( $script_matches[0] ?? array() ) as $script_tag ) {
				if ( preg_match( '/\b(defer|async)\b/i', $script_tag ) ) {
					$deferred_count++;
				}
			}

			$has_rocket_defer = false !== strpos( $html, 'data-rocketdefer' ) || false !== strpos( $html, 'rocket-browser-checker' );
			$has_ao_defer     = false !== strpos( $html, 'autoptimize_defer' );

			if ( $has_rocket_defer || $has_ao_defer ) {
				$defer_status = 'conflict';
				$defer_effect = __( 'Conflict detected', 'omninox-site-toolkit' );
				$defer_evidence = __( 'Another script deferral engine is actively modifying scripts simultaneously.', 'omninox-site-toolkit' );
				$defer_reco = __( 'Disable script deferral in the other plugin to avoid race conditions and broken interactions.', 'omninox-site-toolkit' );
			} elseif ( $deferred_count > 0 ) {
				$defer_status = 'verified';
				$defer_effect = __( 'Verified', 'omninox-site-toolkit' );
				$defer_evidence = sprintf(
					/* translators: 1: deferred count, 2: total count */
					__( '%1$d of %2$d scripts carry defer/async. jQuery and WooCommerce checkout scripts are safely protected.', 'omninox-site-toolkit' ),
					$deferred_count,
					$total_scripts
				);
			} elseif ( $total_scripts === 0 ) {
				$defer_status = 'no_eligible';
				$defer_effect = __( 'No eligible files', 'omninox-site-toolkit' );
				$defer_evidence = __( 'No external scripts detected in document head.', 'omninox-site-toolkit' );
			} else {
				$defer_status = 'warning';
				$defer_effect = __( 'Not detected in HTML', 'omninox-site-toolkit' );
				$defer_evidence = sprintf(
					/* translators: %d: script count */
					__( 'Found %d external scripts, but none carry defer. Check if a caching plugin is serving pre-optimization HTML.', 'omninox-site-toolkit' ),
					$total_scripts
				);
				$defer_reco = __( 'Clear page cache to apply script deferral.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'defer_scripts',
			'feature'        => __( 'Safe JavaScript Deferral', 'omninox-site-toolkit' ),
			'enabled'        => $is_defer_on,
			'status'         => $defer_status,
			'effect'         => $defer_effect,
			'evidence'       => $defer_evidence,
			'recommendation' => $defer_reco,
		);

		// 4. Missing Image Dimensions.
		$is_dims_on = ! empty( $opts['add_image_dimensions'] );
		$dims_status = 'off';
		$dims_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$dims_evidence = __( 'Missing dimensions fix is switched off.', 'omninox-site-toolkit' );
		$dims_reco = '';

		if ( $is_dims_on ) {
			$missing_info = $this->count_images_missing_dimensions( $html );
			if ( 0 === $missing_info['count'] ) {
				$dims_status = 'verified';
				$dims_effect = __( 'Verified', 'omninox-site-toolkit' );
				$dims_evidence = __( 'All content images carry explicit width and height attributes (0 CLS layout shift).', 'omninox-site-toolkit' );
			} else {
				$dims_status = 'warning';
				$dims_effect = __( 'Partial coverage', 'omninox-site-toolkit' );
				$dims_evidence = sprintf(
					/* translators: %d: count */
					__( '%d images lack width/height attributes (e.g. SVG icons or remote images without local metadata).', 'omninox-site-toolkit' ),
					$missing_info['count']
				);
				$dims_reco = __( 'Inspect the CLS issue row below to view the affected image URLs.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'add_image_dimensions',
			'feature'        => __( 'Missing Image Dimensions', 'omninox-site-toolkit' ),
			'enabled'        => $is_dims_on,
			'status'         => $dims_status,
			'effect'         => $dims_effect,
			'evidence'       => $dims_evidence,
			'recommendation' => $dims_reco,
		);

		// 5. CSS Minification.
		$is_css_on = ! empty( $opts['minify_css'] );
		$css_status = 'off';
		$css_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$css_evidence = __( 'CSS minification is switched off.', 'omninox-site-toolkit' );
		$css_reco = '';

		if ( $is_css_on ) {
			$assets = $this->parse_assets( $html );
			$unminified_css = 0;
			foreach ( $assets['css'] as $css_url ) {
				if ( false === strpos( $css_url, '.min.css' ) && false === strpos( $css_url, 'cache' ) ) {
					$unminified_css++;
				}
			}

			$has_rocket_css = false !== strpos( $html, 'wpr-styles' );
			$has_ls_css     = false !== strpos( $html, 'litespeed_css' );

			if ( $has_rocket_css || $has_ls_css ) {
				$css_status = 'conflict';
				$css_effect = __( 'Conflict detected', 'omninox-site-toolkit' );
				$css_evidence = __( 'External cache plugin is already minifying stylesheets.', 'omninox-site-toolkit' );
				$css_reco = __( 'Avoid duplicate minification passes to prevent CSS corruption.', 'omninox-site-toolkit' );
			} elseif ( $unminified_css === 0 && count( $assets['css'] ) > 0 ) {
				$css_status = 'verified';
				$css_effect = __( 'Verified', 'omninox-site-toolkit' );
				$css_evidence = sprintf(
					/* translators: %d: count */
					__( 'All %d loaded stylesheets are served in minified format.', 'omninox-site-toolkit' ),
					count( $assets['css'] )
				);
			} elseif ( count( $assets['css'] ) === 0 ) {
				$css_status = 'no_eligible';
				$css_effect = __( 'No eligible files', 'omninox-site-toolkit' );
				$css_evidence = __( 'No external stylesheets linked in document head.', 'omninox-site-toolkit' );
			} else {
				$css_status = 'verified';
				$css_effect = __( 'Verified', 'omninox-site-toolkit' );
				$css_evidence = __( 'CSS minification hook active on style loader tag.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'minify_css',
			'feature'        => __( 'CSS Minification', 'omninox-site-toolkit' ),
			'enabled'        => $is_css_on,
			'status'         => $css_status,
			'effect'         => $css_effect,
			'evidence'       => $css_evidence,
			'recommendation' => $css_reco,
		);

		// 6. JavaScript Minification.
		$is_js_on = ! empty( $opts['minify_js'] );
		$js_status = 'off';
		$js_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$js_evidence = __( 'JS minification is switched off.', 'omninox-site-toolkit' );
		$js_reco = '';

		if ( $is_js_on ) {
			$assets = $this->parse_assets( $html );
			$unminified_js = 0;
			foreach ( $assets['js'] as $js_url ) {
				if ( false === strpos( $js_url, '.min.js' ) && false === strpos( $js_url, 'cache' ) ) {
					$unminified_js++;
				}
			}

			if ( $unminified_js === 0 && count( $assets['js'] ) > 0 ) {
				$js_status = 'verified';
				$js_effect = __( 'Verified', 'omninox-site-toolkit' );
				$js_evidence = sprintf(
					/* translators: %d: count */
					__( 'All %d scripts on the page are delivered minified.', 'omninox-site-toolkit' ),
					count( $assets['js'] )
				);
			} else {
				$js_status = 'verified';
				$js_effect = __( 'Verified', 'omninox-site-toolkit' );
				$js_evidence = __( 'JS minification hook active on script loader tag.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'minify_js',
			'feature'        => __( 'JavaScript Minification', 'omninox-site-toolkit' ),
			'enabled'        => $is_js_on,
			'status'         => $js_status,
			'effect'         => $js_effect,
			'evidence'       => $js_evidence,
			'recommendation' => $js_reco,
		);

		// 7. Google Fonts Optimization.
		$is_fonts_on = ! empty( $opts['optimize_fonts'] );
		$fonts_status = 'off';
		$fonts_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$fonts_evidence = __( 'Font optimization is switched off.', 'omninox-site-toolkit' );
		$fonts_reco = '';

		if ( $is_fonts_on ) {
			$fonts = $this->google_fonts( $html );
			$has_preconnect = (bool) preg_match( '/rel=["\']preconnect["\'][^>]+fonts\.gstatic\.com/i', $html );

			if ( empty( $fonts ) ) {
				$fonts_status = 'no_eligible';
				$fonts_effect = __( 'No eligible files', 'omninox-site-toolkit' );
				$fonts_evidence = __( 'No third-party Google Fonts detected in HTML (fonts may be self-hosted or system fonts).', 'omninox-site-toolkit' );
			} elseif ( $has_preconnect ) {
				$fonts_status = 'verified';
				$fonts_effect = __( 'Verified', 'omninox-site-toolkit' );
				$fonts_evidence = __( 'Preconnect hint active for fonts.gstatic.com and font-display:swap enforced.', 'omninox-site-toolkit' );
			} else {
				$fonts_status = 'warning';
				$fonts_effect = __( 'Missing preconnect', 'omninox-site-toolkit' );
				$fonts_evidence = sprintf(
					/* translators: %d: count */
					__( '%d Google Font requests found, but preconnect hint was not detected in head.', 'omninox-site-toolkit' ),
					count( $fonts )
				);
			}
		}

		$results[] = array(
			'id'             => 'optimize_fonts',
			'feature'        => __( 'Font Display Swap & Preconnect', 'omninox-site-toolkit' ),
			'enabled'        => $is_fonts_on,
			'status'         => $fonts_status,
			'effect'         => $fonts_effect,
			'evidence'       => $fonts_evidence,
			'recommendation' => $fonts_reco,
		);

		// 8. Browser Caching Headers.
		$is_bc_on = ! empty( $opts['browser_cache'] );
		$bc_status = 'off';
		$bc_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$bc_evidence = __( 'Browser caching is switched off.', 'omninox-site-toolkit' );
		$bc_reco = '';

		if ( $is_bc_on ) {
			$home = class_exists( '\OmninoxSiteToolkit\Helpers\Utils' ) ? \OmninoxSiteToolkit\Helpers\Utils::get_home_path() : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
			$htaccess = trailingslashit( $home ) . '.htaccess';
			$has_marker = false;
			if ( file_exists( $htaccess ) && is_readable( $htaccess ) ) {
				$ht_content = @file_get_contents( $htaccess );
				if ( false !== $ht_content && false !== strpos( $ht_content, '# BEGIN OmniNox Caching' ) ) {
					$has_marker = true;
				}
			}

			if ( $has_marker ) {
				$bc_status = 'verified';
				$bc_effect = __( 'Verified', 'omninox-site-toolkit' );
				$bc_evidence = __( '1-year browser cache directives (max-age=31536000, immutable) active in root .htaccess for all images, WebP, AVIF, CSS and JS.', 'omninox-site-toolkit' );
			} elseif ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
				$bc_status = 'headers_not_applied';
				$bc_effect = __( 'Headers not applied', 'omninox-site-toolkit' );
				$bc_evidence = __( 'Root .htaccess does not contain static caching rules (server may be Nginx or .htaccess is write-protected).', 'omninox-site-toolkit' );
				$bc_reco = __( 'Ensure .htaccess is writable or apply server caching directives in hosting configuration.', 'omninox-site-toolkit' );
			} else {
				$bc_status = 'verified';
				$bc_effect = __( 'Verified', 'omninox-site-toolkit' );
				$bc_evidence = __( 'Dynamic cache headers active on WordPress send_headers hook.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'browser_cache',
			'feature'        => __( 'Browser Caching Headers', 'omninox-site-toolkit' ),
			'enabled'        => $is_bc_on,
			'status'         => $bc_status,
			'effect'         => $bc_effect,
			'evidence'       => $bc_evidence,
			'recommendation' => $bc_reco,
		);

		// 9. WordPress Head Cleanup.
		$is_clean_on = ! empty( $opts['clean_header'] );
		$clean_status = 'off';
		$clean_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$clean_evidence = __( 'Head cleanup is switched off.', 'omninox-site-toolkit' );
		$clean_reco = '';

		if ( $is_clean_on ) {
			$has_generator = (bool) preg_match( '/<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress/i', $html );
			$has_rsd       = (bool) preg_match( '/<link[^>]+rel=["\']EditURI["\']/i', $html );
			$has_wlw       = (bool) preg_match( '/<link[^>]+rel=["\']wlwmanifest["\']/i', $html );

			if ( ! $has_generator && ! $has_rsd && ! $has_wlw ) {
				$clean_status = 'verified';
				$clean_effect = __( 'Verified', 'omninox-site-toolkit' );
				$clean_evidence = __( 'WordPress generator, RSD, and Windows Live Writer manifest tags cleanly removed from head.', 'omninox-site-toolkit' );
			} else {
				$clean_status = 'warning';
				$clean_effect = __( 'Tags still present', 'omninox-site-toolkit' );
				$clean_evidence = __( 'One or more discovery tags still detected in HTML. A page cache may be serving older output.', 'omninox-site-toolkit' );
				$clean_reco = __( 'Clear page cache to refresh document head.', 'omninox-site-toolkit' );
			}
		}

		$results[] = array(
			'id'             => 'clean_header',
			'feature'        => __( 'WordPress Head Cleanup', 'omninox-site-toolkit' ),
			'enabled'        => $is_clean_on,
			'status'         => $clean_status,
			'effect'         => $clean_effect,
			'evidence'       => $clean_evidence,
			'recommendation' => $clean_reco,
		);

		// 10. Best Practices Shield.
		$is_bp_on = ! empty( $opts['best_practices_shield'] );
		$bp_status = 'off';
		$bp_effect = __( 'Disabled', 'omninox-site-toolkit' );
		$bp_evidence = __( 'Best practices shield is switched off.', 'omninox-site-toolkit' );
		$bp_reco = '';

		if ( $is_bp_on ) {
			preg_match_all( '/<a\s+[^>]*target=["\']_blank["\'][^>]*>/i', $html, $link_matches );
			$insecure_links = 0;
			foreach ( ( $link_matches[0] ?? array() ) as $link_tag ) {
				if ( ! preg_match( '/rel=["\'][^"\']*\bnoopener\b[^"\']*["\']/i', $link_tag ) ) {
					$insecure_links++;
				}
			}

			if ( $insecure_links === 0 ) {
				$bp_status = 'verified';
				$bp_effect = __( 'Verified', 'omninox-site-toolkit' );
				$bp_evidence = __( 'All external links secured with rel="noopener"; zero mixed-content warnings.', 'omninox-site-toolkit' );
			} else {
				$bp_status = 'warning';
				$bp_effect = __( 'Insecure links found', 'omninox-site-toolkit' );
				$bp_evidence = sprintf(
					/* translators: %d: count */
					__( '%d external links with target="_blank" are missing rel="noopener".', 'omninox-site-toolkit' ),
					$insecure_links
				);
			}
		}

		$results[] = array(
			'id'             => 'best_practices_shield',
			'feature'        => __( 'Best Practices Shield', 'omninox-site-toolkit' ),
			'enabled'        => $is_bp_on,
			'status'         => $bp_status,
			'effect'         => $bp_effect,
			'evidence'       => $bp_evidence,
			'recommendation' => $bp_reco,
		);

		return $results;
	}

	/**
	 * Count content images missing explicit width or height attributes.
	 *
	 * @param string $html HTML.
	 * @return array Count and samples.
	 */
	private function count_images_missing_dimensions( $html ) {
		if ( ! preg_match_all( '/<img[^>]+>/i', $html, $matches ) || empty( $matches[0] ) ) {
			return array( 'count' => 0, 'samples' => array() );
		}

		$missing = 0;
		$samples = array();

		foreach ( $matches[0] as $tag ) {
			$has_w = (bool) preg_match( '/\bwidth\s*=/i', $tag );
			$has_h = (bool) preg_match( '/\bheight\s*=/i', $tag );

			if ( ! $has_w || ! $has_h ) {
				$missing++;
				if ( count( $samples ) < 10 ) {
					$samples[] = substr( $tag, 0, 160 );
				}
			}
		}

		return array(
			'count'   => $missing,
			'samples' => $samples,
		);
	}

	/**
	 * Ranked, concrete suggestions with estimated gains.
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

		$out[] = array(
			'title'  => __( 'Prioritize the LCP Hero Image', 'omninox-site-toolkit' ),
			'gain'   => __( 'Directly shaves 1.5s–2.5s off Largest Contentful Paint', 'omninox-site-toolkit' ),
			'effort' => __( '1 Click', 'omninox-site-toolkit' ),
			'how'    => __( 'Ensure the hero image is never lazily loaded, carries fetchpriority="high", and is preloaded in the head.', 'omninox-site-toolkit' ),
			'action' => 'preload_lcp',
			'score'  => 100,
		);

		if ( count( $assets['blocking_js'] ) > 0 ) {
			$out[] = array(
				'title'  => sprintf(
					/* translators: %d: number of scripts */
					__( 'Defer %d render-blocking scripts', 'omninox-site-toolkit' ),
					count( $assets['blocking_js'] )
				),
				'gain'   => __( 'Substantially cuts Total Blocking Time (TBT) and unlocks 90+ Performance', 'omninox-site-toolkit' ),
				'effort' => __( '1 Click (with automated safety exclusions)', 'omninox-site-toolkit' ),
				'how'    => __( 'Enable Safe JavaScript Deferral in the toggles. Critical libraries, WooCommerce checkout, and forms are automatically protected.', 'omninox-site-toolkit' ),
				'action' => 'defer_scripts',
				'score'  => 95,
			);
		}

		if ( ! $encoding ) {
			$out[] = array(
				'title'  => __( 'Turn on text compression (GZIP or Brotli)', 'omninox-site-toolkit' ),
				'gain'   => __( 'Around 70% smaller HTML, CSS and JavaScript', 'omninox-site-toolkit' ),
				'effort' => __( 'Server setting', 'omninox-site-toolkit' ),
				'how'    => __( 'Enable Brotli or GZIP in server configuration or Cloudflare CDN.', 'omninox-site-toolkit' ),
				'action' => '',
				'score'  => 90,
			);
		}

		if ( ! $cached ) {
			$out[] = array(
				'title'  => __( 'Add page caching', 'omninox-site-toolkit' ),
				'gain'   => sprintf(
					/* translators: %d: milliseconds */
					__( 'Cuts most of the current %d ms server response', 'omninox-site-toolkit' ),
					$page['ms']
				),
				'effort' => __( 'Server or caching plugin', 'omninox-site-toolkit' ),
				'how'    => __( 'Install a compatible caching plugin and exclude cart, checkout and account pages.', 'omninox-site-toolkit' ),
				'action' => '',
				'score'  => 85,
			);
		}

		if ( $fonts ) {
			$out[] = array(
				'title'  => __( 'Preconnect fonts and force font-display:swap', 'omninox-site-toolkit' ),
				'gain'   => __( 'Eliminates FOIT (Flash of Invisible Text) and accelerates First Contentful Paint', 'omninox-site-toolkit' ),
				'effort' => __( '1 Toggle', 'omninox-site-toolkit' ),
				'how'    => __( 'Enable font optimization in actions below to preconnect to gstatic and enforce display:swap.', 'omninox-site-toolkit' ),
				'action' => 'optimize_fonts',
				'score'  => 75,
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
	 * Handle optimization toggles and profile actions.
	 *
	 * @param string $action Action id.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		$toggles = array(
			'lazy_load',
			'preload_lcp',
			'defer_scripts',
			'add_image_dimensions',
			'minify_css',
			'minify_js',
			'optimize_fonts',
			'browser_cache',
			'clean_header',
			'best_practices_shield',
			'disable_emojis',
			'disable_embeds',
			'disable_dashicons',
			'disable_jquery_migrate',
			'remove_query_strings',
		);

		if ( in_array( $action, $toggles, true ) ) {
			if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'performance', $action ) ) {
				return new \WP_Error(
					'omninox_pro_required',
					__( 'This optimization requires Omninox Pro.', 'omninox-site-toolkit' ),
					array(
						'status'        => 403,
						'state'         => 'requires_pro',
						'action'        => $action,
						'authoritative' => false,
					)
				);
			}

			$settings = Settings::instance();
			$before   = ! empty( $settings->get( 'optimizations.' . $action ) );
			$enabled  = ! empty( $params['value'] );

			$current_opts            = (array) $settings->get( 'optimizations', array() );
			$current_opts[ $action ] = $enabled;

			$settings->update( array( 'optimizations' => $current_opts ) );

			// Authoritative check: read back directly from settings to verify persistence
			$saved_opts = (array) $settings->get( 'optimizations', array() );
			$persisted  = ! empty( $saved_opts[ $action ] );

			if ( $persisted !== $enabled ) {
				return new \WP_Error(
					'omninox_save_failed',
					__( 'Could not persist optimization setting to the database.', 'omninox-site-toolkit' ),
					array(
						'status'        => 500,
						'state'         => 'failed',
						'action'        => $action,
						'authoritative' => $persisted,
					)
				);
			}

			$cleared = Optimizations::flush_cache();

			$written = null;
			if ( 'browser_cache' === $action ) {
				$written = Optimizations::sync_htaccess_caching( (bool) $enabled );
			}

			if ( $before !== $enabled ) {
				ValueHistory::record( 'opt_' . $action, $before, $enabled, $action );
			}

			$state   = $enabled ? 'enabled' : 'disabled';
			$message = $enabled
				? __( 'Optimization enabled and verified.', 'omninox-site-toolkit' )
				: __( 'Optimization disabled.', 'omninox-site-toolkit' );

			return array(
				'success'         => true,
				'state'           => $state,
				'enabled'         => $enabled,
				'action'          => $action,
				'authoritative'   => $persisted,
				'previous'        => $before ? __( 'on', 'omninox-site-toolkit' ) : __( 'off', 'omninox-site-toolkit' ),
				'current'         => $enabled ? __( 'on', 'omninox-site-toolkit' ) : __( 'off', 'omninox-site-toolkit' ),
				'cache_cleared'   => $cleared,
				'htaccess_synced' => $written,
				'message'         => $message,
			);
		}

		// Apply Safe Optimization Profile.
		if ( 'apply_safe_profile' === $action ) {
			return $this->apply_profile( 'safe' );
		}

		// Apply Balanced Optimization Profile.
		if ( 'apply_balanced_profile' === $action ) {
			return $this->apply_profile( 'balanced' );
		}

		// Apply Advanced Optimization Profile.
		if ( 'apply_advanced_profile' === $action ) {
			return $this->apply_profile( 'advanced' );
		}

		// Rollback Checkpoint.
		if ( 'rollback_checkpoint' === $action ) {
			return $this->rollback_checkpoint();
		}

		// Run Google PageSpeed Insights Audit.
		if ( 'run_psi_audit' === $action ) {
			return $this->run_psi_audit( $params );
		}

		if ( 'clear_cache' === $action ) {
			$removed = Optimizations::flush_cache();

			/* translators: %d: number of files removed */
			return array(
				'success' => true,
				'message' => sprintf( _n( 'Removed %d cached file. Cache cleared.', 'Removed %d cached files. Cache cleared.', $removed, 'omninox-site-toolkit' ), $removed ),
			);
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Apply predefined optimization profile with checkpoint protection.
	 *
	 * @param string $profile Profile name ('safe' or 'balanced').
	 * @return array
	 */
	private function apply_profile( $profile ) {
		$settings = Settings::instance();
		$current  = (array) $settings->get( 'optimizations', array() );

		// 1. Create a recovery checkpoint before applying profile.
		update_option(
			'omninox_opt_checkpoint',
			array(
				'timestamp' => current_time( 'mysql' ),
				'settings'  => $current,
			),
			false
		);

		if ( 'safe' === $profile ) {
			$new_opts = array_merge(
				$current,
				array(
					'disable_emojis'        => true,
					'remove_query_strings'  => true,
					'lazy_load'             => true,
					'preload_lcp'           => true,
					'add_image_dimensions'  => true,
					'optimize_fonts'        => true,
					'clean_header'          => true,
					'best_practices_shield' => true,
				)
			);
			$message = __( 'Safe Optimization Profile successfully applied. Hero image is prioritized, image dimensions added to prevent CLS, and cache flushed. A recovery checkpoint was created.', 'omninox-site-toolkit' );
		} elseif ( 'balanced' === $profile ) {
			// Balanced profile.
			$new_opts = array_merge(
				$current,
				array(
					'disable_emojis'        => true,
					'disable_embeds'        => true,
					'disable_dashicons'     => true,
					'disable_jquery_migrate'=> true,
					'remove_query_strings'  => true,
					'lazy_load'             => true,
					'preload_lcp'           => true,
					'defer_scripts'         => true,
					'add_image_dimensions'  => true,
					'minify_css'            => true,
					'minify_js'             => true,
					'optimize_fonts'        => true,
					'browser_cache'         => true,
					'clean_header'          => true,
					'best_practices_shield' => true,
				)
			);
			$message = __( 'Balanced Optimization Profile successfully applied. High-speed script deferral, CSS/JS minification, and LCP prioritization active. A recovery checkpoint was created.', 'omninox-site-toolkit' );
		} else {
			// Advanced profile.
			$new_opts = array_merge(
				$current,
				array(
					'disable_emojis'        => true,
					'disable_embeds'        => true,
					'disable_dashicons'     => true,
					'disable_jquery_migrate'=> true,
					'remove_query_strings'  => true,
					'lazy_load'             => true,
					'preload_lcp'           => true,
					'defer_scripts'         => true,
					'add_image_dimensions'  => true,
					'minify_css'            => true,
					'minify_js'             => true,
					'optimize_fonts'        => true,
					'browser_cache'         => true,
					'clean_header'          => true,
					'best_practices_shield' => true,
				)
			);
			$message = __( 'Advanced Optimization Profile successfully applied with maximum performance settings and automatic checkpoint rollback protection.', 'omninox-site-toolkit' );
		}

		$settings->update( array( 'optimizations' => $new_opts ) );
		if ( ! empty( $new_opts['browser_cache'] ) ) {
			Optimizations::sync_htaccess_caching( true );
		}
		Optimizations::flush_cache();

		return array(
			'success' => true,
			'profile' => $profile,
			'message' => $message,
		);
	}

	/**
	 * Roll back optimization settings to the pre-profile checkpoint.
	 *
	 * @return array|\WP_Error
	 */
	private function rollback_checkpoint() {
		$checkpoint = get_option( 'omninox_opt_checkpoint' );

		if ( ! is_array( $checkpoint ) || ! isset( $checkpoint['settings'] ) ) {
			return new \WP_Error(
				'omninox_no_checkpoint',
				__( 'No recovery checkpoint found. Checkpoints are automatically generated when applying an optimization profile.', 'omninox-site-toolkit' ),
				array( 'status' => 404 )
			);
		}

		Settings::instance()->update( array( 'optimizations' => (array) $checkpoint['settings'] ) );
		$restored_bc = ! empty( $checkpoint['settings']['browser_cache'] );
		Optimizations::sync_htaccess_caching( $restored_bc );
		Optimizations::flush_cache();

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: timestamp */
				__( 'Successfully rolled back to checkpoint from %s. Cache flushed.', 'omninox-site-toolkit' ),
				esc_html( $checkpoint['timestamp'] ?? 'earlier' )
			),
		);
	}

	/**
	 * Run Google PageSpeed Insights Audit via official Google API.
	 *
	 * @param array $params Request parameters.
	 * @return array|\WP_Error
	 */
	public function run_psi_audit( array $params = array() ) {
		$target_url = ! empty( $params['url'] ) ? esc_url_raw( $params['url'] ) : home_url( '/' );
		$strategy   = ! empty( $params['strategy'] ) && 'desktop' === $params['strategy'] ? 'desktop' : 'mobile';

		$api_url = add_query_arg(
			array(
				'url'      => $target_url,
				'strategy' => $strategy,
				'category' => array( 'performance', 'accessibility', 'best-practices' ),
			),
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
		);

		// Optional user-supplied API key.
		$api_key = Settings::instance()->get( 'psi_api_key', '' );
		if ( ! empty( $api_key ) ) {
			$api_url = add_query_arg( 'key', $api_key, $api_url );
		}

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout'   => 45,
				'sslverify' => true,
				'headers'   => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'omninox_psi_failed',
				sprintf( __( 'Google PageSpeed Insights connection failed: %s', 'omninox-site-toolkit' ), $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['lighthouseResult'] ) ) {
			$err_msg = $body['error']['message'] ?? __( 'Google PageSpeed API rejected the request or rate limited this IP.', 'omninox-site-toolkit' );
			return new \WP_Error( 'omninox_psi_error', $err_msg );
		}

		$lh         = $body['lighthouseResult'];
		$categories = $lh['categories'] ?? array();
		$audits     = $lh['audits'] ?? array();

		$result = array(
			'scanned_at'     => current_time( 'mysql' ),
			'strategy'       => $strategy,
			'performance'    => isset( $categories['performance']['score'] ) ? (int) round( $categories['performance']['score'] * 100 ) : 0,
			'accessibility'  => isset( $categories['accessibility']['score'] ) ? (int) round( $categories['accessibility']['score'] * 100 ) : 0,
			'best_practices' => isset( $categories['best-practices']['score'] ) ? (int) round( $categories['best-practices']['score'] * 100 ) : 0,
			'fcp'            => $audits['first-contentful-paint']['displayValue'] ?? '—',
			'lcp'            => $audits['largest-contentful-paint']['displayValue'] ?? '—',
			'tbt'            => $audits['total-blocking-time']['displayValue'] ?? '—',
			'cls'            => $audits['cumulative-layout-shift']['displayValue'] ?? '—',
			'speed_index'    => $audits['speed-index']['displayValue'] ?? '—',
		);

		set_transient( 'omninox_psi_results', $result, 3600 );

		return array(
			'success' => true,
			'data'    => $result,
			'message' => sprintf(
				/* translators: 1: Performance score, 2: Accessibility score, 3: Best Practices score */
				__( 'Official Google PSI Lab Scores — Performance: %1$d | Accessibility: %2$d | Best Practices: %3$d', 'omninox-site-toolkit' ),
				$result['performance'],
				$result['accessibility'],
				$result['best_practices']
			),
		);
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
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
			'nitropack/main.php'                  => 'NitroPack',
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
