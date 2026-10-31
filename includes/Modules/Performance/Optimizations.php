<?php
/**
 * Front-end optimizations driven by the settings toggles.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Performance;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class Optimizations {

	use Singleton;

	/**
	 * Active flags.
	 *
	 * @var array
	 */
	private $flags = array();

	/**
	 * Cache for resolved image dimensions.
	 *
	 * @var array
	 */
	private $dimension_cache = array();

	/**
	 * Content image sequence tracker to protect LCP hero image.
	 *
	 * @var int
	 */
	private $content_image_count = 0;

	/**
	 * Wire up whichever optimizations are switched on.
	 */
	public function boot() {
		$this->flags = (array) Settings::instance()->get( 'optimizations', array() );

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( $this->on( 'disable_emojis' ) ) {
			$this->disable_emojis();
		}

		if ( $this->on( 'disable_embeds' ) ) {
			$this->disable_embeds();
		}

		if ( $this->on( 'disable_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_dashicons' ), 100 );
		}

		if ( $this->on( 'disable_jquery_migrate' ) ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}

		if ( $this->on( 'remove_query_strings' ) ) {
			add_filter( 'script_loader_src', array( $this, 'strip_version' ), 15 );
			add_filter( 'style_loader_src', array( $this, 'strip_version' ), 15 );
		}

		if ( $this->on( 'clean_header' ) ) {
			$this->clean_header();
		}

		// Intelligent image loading & LCP prioritization.
		if ( $this->on( 'lazy_load' ) || $this->on( 'preload_lcp' ) || $this->on( 'add_image_dimensions' ) || $this->on( 'best_practices_shield' ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_true' );
			add_filter( 'the_content', array( $this, 'add_lazy_attributes' ), 99 );
			add_filter( 'post_thumbnail_html', array( $this, 'optimize_post_thumbnail' ), 99, 5 );
			add_action( 'template_redirect', array( $this, 'buffer_start' ), 1 );
		}

		if ( $this->on( 'preload_lcp' ) ) {
			add_action( 'wp_head', array( $this, 'preload_lcp_image' ), 1 );
		}

		if ( $this->on( 'defer_scripts' ) ) {
			add_filter( 'script_loader_tag', array( $this, 'defer_non_critical_scripts' ), 20, 3 );
		}

		if ( $this->on( 'optimize_fonts' ) ) {
			add_filter( 'style_loader_tag', array( $this, 'font_display_swap' ), 20, 4 );
			add_filter( 'wp_resource_hints', array( $this, 'font_resource_hints' ), 10, 2 );
		}

		if ( $this->on( 'browser_cache' ) ) {
			add_action( 'send_headers', array( $this, 'send_browser_cache_headers' ) );
			if ( is_admin() ) {
				self::maybe_sync_htaccess();
			}
		}

		if ( $this->on( 'minify_css' ) ) {
			add_filter( 'style_loader_tag', array( $this, 'minify_style_tag' ), 20, 4 );
		}

		if ( $this->on( 'minify_js' ) ) {
			add_filter( 'script_loader_tag', array( $this, 'minify_script_tag' ), 20, 3 );
		}

		if ( $this->on( 'best_practices_shield' ) ) {
			add_filter( 'the_content', array( $this, 'best_practices_shield' ), 100 );
		}
	}

	/**
	 * Is a flag on?
	 *
	 * @param string $flag Flag name.
	 * @return bool
	 */
	private function on( $flag ) {
		if ( empty( $this->flags[ $flag ] ) ) {
			return false;
		}

		// Pro optimizations only run with an active licence, even if a stale
		// settings flag is still switched on from a lapsed subscription.
		if ( ! \OmninoxSiteToolkit\License\LicenseManager::can_access( 'performance', $flag ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Strip emoji support.
	 */
	private function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( $this, 'remove_tinymce_emoji' ) );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Remove the emoji TinyMCE plugin.
	 *
	 * @param array $plugins Plugins.
	 * @return array
	 */
	public function remove_tinymce_emoji( $plugins ) {
		return array_diff( (array) $plugins, array( 'wpemoji' ) );
	}

	/**
	 * Strip oEmbed discovery and scripts.
	 */
	private function disable_embeds() {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		add_filter(
			'rewrite_rules_array',
			static function ( $rules ) {
				foreach ( (array) $rules as $rule => $rewrite ) {
					if ( false !== strpos( $rewrite, 'embed=true' ) ) {
						unset( $rules[ $rule ] );
					}
				}

				return $rules;
			}
		);
	}

	/**
	 * Remove Dashicons for logged-out visitors.
	 */
	public function dequeue_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
			wp_deregister_style( 'dashicons' );
		}
	}

	/**
	 * Drop jQuery Migrate from the jQuery bundle.
	 *
	 * @param \WP_Scripts $scripts Scripts registry.
	 */
	public function remove_jquery_migrate( $scripts ) {
		if ( is_admin() || empty( $scripts->registered['jquery'] ) ) {
			return;
		}

		$scripts->registered['jquery']->deps = array_diff(
			$scripts->registered['jquery']->deps,
			array( 'jquery-migrate' )
		);
	}

	/**
	 * Remove ver query args from local assets.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function strip_version( $src ) {
		if ( $src && false !== strpos( $src, 'ver=' ) && false !== strpos( $src, home_url() ) ) {
			$src = remove_query_arg( 'ver', $src );
		}

		return $src;
	}

	/**
	 * Remove bloat tags from head.
	 */
	private function clean_header() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
	}

	/**
	 * Preload LCP hero image in document head.
	 */
	public function preload_lcp_image() {
		if ( is_feed() || is_admin() || is_embed() ) {
			return;
		}

		$preload_url = '';

		// 1. Single post or page with a featured image.
		if ( is_singular() && has_post_thumbnail() ) {
			$thumb_id   = get_post_thumbnail_id();
			$image_data = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( ! empty( $image_data[0] ) ) {
				$preload_url = $image_data[0];
			}
		}

		// 2. Elementor / builder content inspection: find the leading content image.
		if ( empty( $preload_url ) && is_singular() ) {
			$post = get_post();
			if ( $post && ! empty( $post->post_content ) ) {
				if ( preg_match( '/<img[^>]+src=["\']([^"\']+\.(?:webp|avif|jpe?g|png))["\'][^>]*>/i', $post->post_content, $m ) ) {
					$preload_url = $m[1];
				}
			}
		}

		// 3. Front page / homepage fallback: use detected LCP hero image from live diagnostic scan.
		if ( empty( $preload_url ) && is_front_page() ) {
			$detected = get_option( 'omninox_detected_lcp_url' );
			if ( ! empty( $detected ) ) {
				$preload_url = $detected;
			}
		}

		// 4. Fallback to custom logo only if no hero content image exists.
		if ( empty( $preload_url ) && is_front_page() ) {
			$custom_logo_id = get_theme_mod( 'custom_logo' );
			if ( $custom_logo_id ) {
				$image_data = wp_get_attachment_image_src( $custom_logo_id, 'full' );
				if ( ! empty( $image_data[0] ) ) {
					$preload_url = $image_data[0];
				}
			} elseif ( get_header_image() ) {
				$preload_url = get_header_image();
			}
		}

		/**
		 * Filter the LCP preload image URL.
		 *
		 * @param string $preload_url URL to preload.
		 */
		$preload_url = apply_filters( 'omninox_lcp_preload_url', $preload_url );

		if ( ! empty( $preload_url ) ) {
			printf(
				'<link rel="preload" as="image" href="%s" fetchpriority="high">' . "\n",
				esc_url( $preload_url )
			);
		}
	}

	/**
	 * Start full page output buffer on frontend dynamic requests.
	 * Optimizes Elementor, theme headers, footers, and page builders.
	 */
	public function buffer_start() {
		if ( is_admin() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		if ( isset( $_GET['elementor-preview'] ) || is_customize_preview() ) {
			return;
		}

		ob_start( array( $this, 'filter_page_html' ) );
	}

	/**
	 * Filter full page HTML to apply LCP prioritization, missing dimensions, and intelligent lazy loading.
	 *
	 * @param string $html Full HTML document.
	 * @return string Optimized HTML.
	 */
	public function filter_page_html( $html ) {
		if ( empty( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		if ( $this->on( 'lazy_load' ) || $this->on( 'preload_lcp' ) || $this->on( 'add_image_dimensions' ) ) {
			$this->content_image_count = 0;
			$html = $this->add_lazy_attributes( $html );
		}

		if ( $this->on( 'best_practices_shield' ) ) {
			$html = $this->best_practices_shield( $html );
		}

		return $html;
	}

	/**
	 * Optimize post thumbnail output: set fetchpriority="high", avoid lazy-load on singular hero.
	 *
	 * @param string       $html              The post thumbnail HTML.
	 * @param int          $post_id           The post ID.
	 * @param int          $post_thumbnail_id The post thumbnail ID.
	 * @param string|array $size              Requested size.
	 * @param string|array $attr              Query string or array of attributes.
	 * @return string
	 */
	public function optimize_post_thumbnail( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		if ( empty( $html ) || is_feed() || is_admin() ) {
			return $html;
		}

		if ( is_singular() && (int) get_the_ID() === (int) $post_id ) {
			// Hero / featured image above the fold: remove lazy loading, attach high priority.
			$html = preg_replace( '/\bloading=["\']lazy["\']/i', 'loading="eager"', $html );
			if ( false === stripos( $html, 'fetchpriority=' ) ) {
				$html = preg_replace( '/<img\b/i', '<img fetchpriority="high"', $html );
			}
			if ( false === stripos( $html, 'decoding=' ) ) {
				$html = preg_replace( '/<img\b/i', '<img decoding="async"', $html );
			}
		}

		return $html;
	}

	/**
	 * Intelligently add loading="lazy", fetchpriority="high", and missing dimensions.
	 * Never lazy-loads the leading above-the-fold / hero image!
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function add_lazy_attributes( $content ) {
		if ( is_feed() || is_admin() || '' === $content ) {
			return $content;
		}

		$add_dimensions = $this->on( 'add_image_dimensions' );
		$is_lazy_on     = $this->on( 'lazy_load' );

		$content = preg_replace_callback(
			'/<(img|iframe)([^>]*)>/i',
			function ( $matches ) use ( $add_dimensions, $is_lazy_on ) {
				$tag_name = strtolower( $matches[1] );
				$attrs    = $matches[2];

				if ( 'iframe' === $tag_name ) {
					if ( $is_lazy_on && false === stripos( $attrs, 'loading=' ) ) {
						return '<iframe' . $attrs . ' loading="lazy">';
					}
					return '<iframe' . $attrs . '>';
				}

				// Processing <img> tag.
				$this->content_image_count++;
				$image_idx = $this->content_image_count;

				$has_loading       = (bool) preg_match( '/\bloading\s*=/i', $attrs );
				$has_fetchpriority = (bool) preg_match( '/\bfetchpriority\s*=/i', $attrs );
				$has_decoding      = (bool) preg_match( '/\bdecoding\s*=/i', $attrs );
				$has_width         = (bool) preg_match( '/\bwidth\s*=/i', $attrs );
				$has_height        = (bool) preg_match( '/\bheight\s*=/i', $attrs );

				// Check classes for skip-lazy, hero, or banner indicators.
				$is_hero_or_skip = (bool) preg_match( '/class=["\'][^"\']*\b(skip-lazy|no-lazy|hero|banner|custom-logo|wp-post-image|slider)\b/i', $attrs );

				// The first 1-2 images on the page or marked heroes are above-the-fold LCP candidates!
				$is_above_fold = ( $image_idx <= 1 || $is_hero_or_skip );

				// Add missing dimensions if enabled to eliminate Cumulative Layout Shift (CLS).
				if ( $add_dimensions && ( ! $has_width || ! $has_height ) ) {
					if ( preg_match( '/src=["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
						$src_url = $src_match[1];
						$dims    = $this->resolve_image_dimensions( $src_url );
						if ( $dims ) {
							if ( ! $has_width && ! empty( $dims['width'] ) ) {
								$attrs .= ' width="' . (int) $dims['width'] . '"';
							}
							if ( ! $has_height && ! empty( $dims['height'] ) ) {
								$attrs .= ' height="' . (int) $dims['height'] . '"';
							}
						}
					}
				}

				if ( $is_above_fold ) {
					// Above the fold: prioritize rendering and NEVER lazy load.
					if ( ! $has_fetchpriority ) {
						$attrs .= ' fetchpriority="high"';
					}
					if ( ! $has_decoding ) {
						$attrs .= ' decoding="async"';
					}
					// If loading="lazy" was injected by themes or builders, neutralize to eager.
					$attrs = preg_replace( '/\bloading=["\']lazy["\']/i', 'loading="eager"', $attrs );
				} else {
					// Below the fold: lazy load safely.
					if ( $is_lazy_on && ! $has_loading ) {
						$attrs .= ' loading="lazy"';
					}
					if ( ! $has_decoding ) {
						$attrs .= ' decoding="async"';
					}
				}

				return '<img' . $attrs . '>';
			},
			$content
		);

		return $content;
	}

	/**
	 * Resolve image dimensions for local images.
	 *
	 * @param string $url Image URL.
	 * @return array|null Array with width and height or null.
	 */
	public function resolve_image_dimensions( $url ) {
		if ( empty( $url ) ) {
			return null;
		}

		$clean_url = strtok( $url, '?' );

		if ( isset( $this->dimension_cache[ $clean_url ] ) ) {
			return $this->dimension_cache[ $clean_url ];
		}

		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		if ( 0 === strpos( $clean_url, $base_url ) ) {
			$rel_path  = ltrim( substr( $clean_url, strlen( $base_url ) ), '/\\' );
			$file_path = path_join( $base_dir, $rel_path );

			if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
				$size = @getimagesize( $file_path );
				if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
					$result = array(
						'width'  => (int) $size[0],
						'height' => (int) $size[1],
					);
					$this->dimension_cache[ $clean_url ] = $result;
					return $result;
				}
			}
		}

		$this->dimension_cache[ $clean_url ] = null;
		return null;
	}

	/**
	 * Safe JavaScript deferral to reduce Total Blocking Time (TBT).
	 * Strictly protects critical WooCommerce checkout, payment gateways, and core libraries.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public function defer_non_critical_scripts( $tag, $handle, $src ) {
		if ( is_admin() || empty( $src ) ) {
			return $tag;
		}

		// Don't modify if already async, deferred, or an ES module.
		if ( preg_match( '/\b(defer|async|type=["\']module["\'])/i', $tag ) ) {
			return $tag;
		}

		// Strictly protect jQuery, WordPress core hooks, WooCommerce cart/checkout, payment gateways, and builders.
		$critical_handles = array(
			'jquery',
			'jquery-core',
			'jquery-migrate',
			'wp-polyfill',
			'wp-hooks',
			'wp-i18n',
			'elementor-frontend',
			'elementor-pro-frontend',
			'woocommerce',
			'wc-cart',
			'wc-checkout',
			'wc-country-select',
			'wc-address-i18n',
			'wc-credit-card-form',
			'stripe',
			'paypal',
			'recaptcha',
			'grecaptcha',
			'google-recaptcha',
			'cf-turnstile',
			'omninox-delay-runtime',
		);

		if ( in_array( $handle, $critical_handles, true ) ) {
			return $tag;
		}

		// Never defer on active cart or checkout pages to protect transactions.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return $tag;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return $tag;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return $tag;
		}

		// Exclude builders in active edit mode.
		if ( isset( $_GET['elementor-preview'] ) || is_customize_preview() ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	/**
	 * Add font-display:swap to Google Font links.
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 * @param string $href   URL.
	 * @param string $media  Media.
	 * @return string
	 */
	public function font_display_swap( $tag, $handle, $href, $media ) {
		if ( false === strpos( $href, 'fonts.googleapis.com' ) || false !== strpos( $href, 'display=' ) ) {
			return $tag;
		}

		return str_replace( $href, add_query_arg( 'display', 'swap', $href ), $tag );
	}

	/**
	 * Preconnect to font origins using standard WordPress resource hints.
	 *
	 * @param array  $urls          URLs to print for resource hints.
	 * @param string $relation_type The relation type the URLs are printed for.
	 * @return array Modified URLs array.
	 */
	public function font_resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' === $relation_type ) {
			$urls[] = array(
				'href'        => 'https://fonts.gstatic.com',
				'crossorigin' => 'anonymous',
			);
		}

		return $urls;
	}

	/**
	 * Send browser cache headers for static responses.
	 */
	public function send_browser_cache_headers() {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		if ( ! headers_sent() ) {
			// Ensure HTML responses carry a balanced cache policy if not cart/checkout.
			$is_wc = ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() );
			if ( ! $is_wc ) {
				header( 'Cache-Control: public, max-age=3600, stale-while-revalidate=86400' );
			}
		}
	}

	/**
	 * Best Practices Shield: Fix mixed content and add security attributes to external links.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function best_practices_shield( $content ) {
		if ( empty( $content ) || is_feed() || is_admin() ) {
			return $content;
		}

		// Ensure external links with target="_blank" carry rel="noopener noreferrer".
		$content = preg_replace_callback(
			'/<a\s+([^>]*target=["\']_blank["\'][^>]*)>/i',
			static function ( $m ) {
				$tag = $m[0];
				if ( preg_match( '/rel=["\']([^"\']*)["\']/i', $tag, $rel_match ) ) {
					$rel   = $rel_match[1];
					$parts = array_filter( explode( ' ', $rel ) );
					if ( ! in_array( 'noopener', $parts, true ) ) {
						$parts[] = 'noopener';
					}
					if ( ! in_array( 'noreferrer', $parts, true ) ) {
						$parts[] = 'noreferrer';
					}
					$new_rel = implode( ' ', $parts );
					return preg_replace( '/rel=["\'][^"\']*["\']/i', 'rel="' . esc_attr( $new_rel ) . '"', $tag );
				}
				return str_replace( '>', ' rel="noopener noreferrer">', $tag );
			},
			$content
		);

		return $content;
	}

	/**
	 * Minify enqueued local CSS stylesheet and cache minified file.
	 *
	 * @param string $tag    HTML style tag.
	 * @param string $handle Script handle.
	 * @param string $href   Stylesheet URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public function minify_style_tag( $tag, $handle, $href, $media ) {
		if ( is_admin() || empty( $href ) || false !== strpos( $href, '.min.css' ) ) {
			return $tag;
		}

		$clean_href = strtok( $href, '?' );
		$file_path  = Utils::url_to_path( $clean_href );

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return $tag;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
			return $tag;
		}

		$cache_dir = trailingslashit( wp_normalize_path( (string) $upload_dir['basedir'] ) ) . 'omninox-site-toolkit/cache';
		$cache_url = trailingslashit( (string) $upload_dir['baseurl'] ) . 'omninox-site-toolkit/cache';

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
			@file_put_contents( $cache_dir . '/index.html', '' );
		}

		$mtime      = (int) filemtime( $file_path );
		$cache_file = $cache_dir . '/min-' . md5( $file_path . $mtime ) . '.css';
		$min_url    = $cache_url . '/min-' . md5( $file_path . $mtime ) . '.css';

		if ( ! file_exists( $cache_file ) ) {
			$raw_css = @file_get_contents( $file_path );
			if ( false === $raw_css || '' === trim( $raw_css ) ) {
				return $tag;
			}
			$minified = self::minify_css( $raw_css );
			if ( false === @file_put_contents( $cache_file, $minified ) ) {
				return $tag;
			}
		}

		return str_replace( $href, $min_url, $tag );
	}

	/**
	 * Minify enqueued local JavaScript and cache minified file.
	 *
	 * @param string $tag    HTML script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public function minify_script_tag( $tag, $handle, $src ) {
		if ( is_admin() || empty( $src ) || false !== strpos( $src, '.min.js' ) ) {
			return $tag;
		}

		$protected = array(
			'jquery',
			'jquery-core',
			'jquery-migrate',
			'wp-polyfill',
			'wp-hooks',
			'wp-i18n',
			'elementor-frontend',
			'elementor-pro-frontend',
			'woocommerce',
			'wc-cart',
			'wc-checkout',
			'stripe',
			'paypal',
			'recaptcha',
			'grecaptcha',
			'google-recaptcha',
			'cf-turnstile',
			'omninox-delay-runtime',
		);
		if ( in_array( $handle, $protected, true ) ) {
			return $tag;
		}

		$clean_src  = strtok( $src, '?' );
		$file_path  = Utils::url_to_path( $clean_src );

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return $tag;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
			return $tag;
		}

		$cache_dir = trailingslashit( wp_normalize_path( (string) $upload_dir['basedir'] ) ) . 'omninox-site-toolkit/cache';
		$cache_url = trailingslashit( (string) $upload_dir['baseurl'] ) . 'omninox-site-toolkit/cache';

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
			@file_put_contents( $cache_dir . '/index.html', '' );
		}

		$mtime      = (int) filemtime( $file_path );
		$cache_file = $cache_dir . '/min-' . md5( $file_path . $mtime ) . '.js';
		$min_url    = $cache_url . '/min-' . md5( $file_path . $mtime ) . '.js';

		if ( ! file_exists( $cache_file ) ) {
			$raw_js = @file_get_contents( $file_path );
			if ( false === $raw_js || '' === trim( $raw_js ) ) {
				return $tag;
			}
			$minified = self::minify_js( $raw_js );
			if ( false === @file_put_contents( $cache_file, $minified ) ) {
				return $tag;
			}
		}

		return str_replace( $src, $min_url, $tag );
	}

	/**
	 * Minify CSS helper.
	 *
	 * @param string $css Raw CSS.
	 * @return string Minified CSS.
	 */
	public static function minify_css( $css ) {
		if ( empty( $css ) ) {
			return '';
		}

		// Remove comments.
		$css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
		// Strip newlines and tabs.
		$css = str_replace( array( "\r\n", "\r", "\n", "\t" ), '', $css );
		// Reduce whitespace.
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([:;{}])\s*/', '$1', $css );
		$css = str_replace( ';}', '}', $css );

		return trim( $css );
	}

	/**
	 * Minify JS helper.
	 *
	 * @param string $js Raw JS.
	 * @return string Minified JS.
	 */
	public static function minify_js( $js ) {
		if ( empty( $js ) ) {
			return '';
		}

		// Remove multi-line comments.
		$js = preg_replace( '!/\*.*?\*/!s', '', $js );
		// Remove single-line comments on their own line.
		$js = preg_replace( '/^[ \t]*\/\/[^\r\n]*/m', '', $js );
		// Normalize whitespace.
		$js = preg_replace( '/[ \t]+/', ' ', $js );
		$js = preg_replace( '/^\s*[\r\n]+/m', '', $js );

		return trim( $js );
	}

	/**
	 * Automatically ensure .htaccess has our caching rules if the feature is on.
	 */
	public static function maybe_sync_htaccess() {
		$home = class_exists( '\OmninoxSiteToolkit\Helpers\Utils' ) ? \OmninoxSiteToolkit\Helpers\Utils::get_home_path() : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
		if ( empty( $home ) ) {
			return;
		}

		$htaccess = trailingslashit( $home ) . '.htaccess';
		if ( file_exists( $htaccess ) && is_readable( $htaccess ) ) {
			$contents = @file_get_contents( $htaccess );
			if ( false !== $contents && false === strpos( $contents, '# BEGIN OmniNox Caching' ) ) {
				self::sync_htaccess_caching( true );
			}
		} elseif ( ! file_exists( $htaccess ) && is_writable( $home ) ) {
			self::sync_htaccess_caching( true );
		}
	}

	/**
	 * Sync browser caching directives to root .htaccess for Apache and LiteSpeed servers.
	 *
	 * Direct static file requests (e.g. .webp, .png, .jpg, .css, .js) bypass WordPress PHP entirely.
	 * Writing mod_expires and mod_headers into .htaccess ensures the web server sends long-lived
	 * cache headers (1 year / 31536000s) directly to browsers and Lighthouse.
	 *
	 * @param bool $enable Whether to insert or remove the caching directives.
	 * @return bool True if successfully written or removed, false if not writable.
	 */
	public static function sync_htaccess_caching( $enable = true ) {
		$home = class_exists( '\OmninoxSiteToolkit\Helpers\Utils' ) ? \OmninoxSiteToolkit\Helpers\Utils::get_home_path() : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
		if ( empty( $home ) ) {
			return false;
		}

		$htaccess = trailingslashit( $home ) . '.htaccess';

		if ( ! function_exists( 'insert_with_markers' ) ) {
			if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/misc.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			return false;
		}

		$lines = array();
		if ( $enable ) {
			$lines = array(
				'<IfModule mod_expires.c>',
				'  ExpiresActive On',
				'  ExpiresDefault "access plus 1 month"',
				'  ExpiresByType image/jpeg "access plus 1 year"',
				'  ExpiresByType image/jpg "access plus 1 year"',
				'  ExpiresByType image/png "access plus 1 year"',
				'  ExpiresByType image/gif "access plus 1 year"',
				'  ExpiresByType image/webp "access plus 1 year"',
				'  ExpiresByType image/avif "access plus 1 year"',
				'  ExpiresByType image/svg+xml "access plus 1 year"',
				'  ExpiresByType image/x-icon "access plus 1 year"',
				'  ExpiresByType video/mp4 "access plus 1 year"',
				'  ExpiresByType video/webm "access plus 1 year"',
				'  ExpiresByType text/css "access plus 1 year"',
				'  ExpiresByType text/javascript "access plus 1 year"',
				'  ExpiresByType application/javascript "access plus 1 year"',
				'  ExpiresByType application/x-javascript "access plus 1 year"',
				'  ExpiresByType font/ttf "access plus 1 year"',
				'  ExpiresByType font/otf "access plus 1 year"',
				'  ExpiresByType font/woff "access plus 1 year"',
				'  ExpiresByType font/woff2 "access plus 1 year"',
				'  ExpiresByType application/font-woff "access plus 1 year"',
				'  ExpiresByType application/font-woff2 "access plus 1 year"',
				'</IfModule>',
				'<IfModule mod_headers.c>',
				'  <FilesMatch "\\.(ico|jpe?g|png|gif|webp|avif|svg|css|js|woff2?|ttf|otf|eot)$">',
				'    Header set Cache-Control "public, max-age=31536000, immutable"',
				'  </FilesMatch>',
				'</IfModule>',
			);
		}

		// Only attempt if htaccess exists and is writable, or directory is writable to create it.
		$can_write = file_exists( $htaccess ) ? is_writable( $htaccess ) : is_writable( $home );
		if ( ! $can_write ) {
			return false;
		}

		return insert_with_markers( $htaccess, 'OmniNox Caching', $lines );
	}

	/**
	 * Flush generated caches, transients and third-party page caches safely.
	 *
	 * Deletes files inside the plugin's dedicated cache directory
	 * (wp-content/uploads/omninox-site-toolkit/cache/), OmniNox transients,
	 * object cache, and triggers third-party cache purge hooks.
	 *
	 * @return int Number of cached files and transient items removed.
	 */
	public static function flush_cache() {
		$removed = 0;

		try {
			// 1. Delete OmniNox generated cache files if the folder exists.
			if ( function_exists( 'wp_upload_dir' ) ) {
				$uploads = wp_upload_dir();
				if ( ! empty( $uploads['basedir'] ) ) {
					$cache_dir = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . 'omninox-site-toolkit/cache';
					if ( is_dir( $cache_dir ) ) {
						try {
							$iterator = new \RecursiveIteratorIterator(
								new \RecursiveDirectoryIterator( $cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
								\RecursiveIteratorIterator::CHILD_FIRST
							);
							foreach ( $iterator as $item ) {
								if ( $item->isFile() && basename( $item->getPathname() ) !== 'index.html' ) {
									if ( @unlink( $item->getPathname() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
										$removed++;
									}
								}
							}
						} catch ( \Throwable $fe ) {
							$files = glob( $cache_dir . '/*' );
							if ( is_array( $files ) ) {
								foreach ( $files as $file ) {
									if ( is_file( $file ) && basename( $file ) !== 'index.html' ) {
										if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
											$removed++;
										}
									}
								}
							}
						}
					}
				}
			}

			// 2. Clear OmniNox-owned transients.
			$transients = array(
				'omninox_front_html',
				'omninox_asset_inventory',
				'omninox_speed_test',
				'omninox_cached_overview',
				'omninox_psi_results',
				'omninox_health_snapshot',
				'omninox_db_stats',
			);

			foreach ( $transients as $transient_key ) {
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( $transient_key );
				}
			}

			// 3. Clear in-memory / persistent object cache safely if available.
			if ( function_exists( 'wp_cache_flush' ) ) {
				@wp_cache_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}

			// 4. Trigger popular third-party page caching and asset flushes if active.
			// LiteSpeed Cache
			if ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
				\LiteSpeed\Purge::purge_all();
			} elseif ( function_exists( 'do_action' ) ) {
				do_action( 'litespeed_purge_all' );
			}

			// WP Rocket
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			if ( function_exists( 'rocket_clean_minify' ) ) {
				rocket_clean_minify();
			}

			// W3 Total Cache
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}

			// WP Super Cache
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}

			// Breeze (Cloudways)
			if ( class_exists( '\Breeze_Admin' ) && method_exists( '\Breeze_Admin', 'breeze_clear_all_cache' ) ) {
				\Breeze_Admin::breeze_clear_all_cache();
			} elseif ( function_exists( 'do_action' ) ) {
				do_action( 'breeze_clear_all_cache' );
			}

			// SiteGround Optimizer (SG CachePress)
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}

			// Autoptimize
			if ( class_exists( '\autoptimizeCache' ) && method_exists( '\autoptimizeCache', 'clearall' ) ) {
				\autoptimizeCache::clearall();
			}

			// Elementor CSS & Assets
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}

			// WP Engine / Kinsta
			if ( class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
				\WpeCommon::purge_varnish_cache();
			}

			/**
			 * Fires after OmniNox generated caches and transients are flushed.
			 *
			 * @param int $removed Count of removed cache files.
			 */
			if ( function_exists( 'do_action' ) ) {
				do_action( 'omninox_cache_flushed', $removed );
			}
		} catch ( \Throwable $e ) {
			// Never fatal on cache invalidation failure.
			if ( class_exists( '\OmninoxSiteToolkit\Database\LogRepository' ) ) {
				\OmninoxSiteToolkit\Database\LogRepository::record(
					array(
						'type'    => 'performance',
						'level'   => 'warning',
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Cache flush encountered a non-fatal warning: %s', 'omninox-site-toolkit' ),
							$e->getMessage()
						),
						'source'  => 'performance/flush_cache',
					)
				);
			}
		}

		return $removed;
	}
}
