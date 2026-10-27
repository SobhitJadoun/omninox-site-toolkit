<?php
/**
 * Front-end optimizations driven by the settings toggles.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Performance;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;

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

		if ( $this->on( 'lazy_load' ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_true' );
			add_filter( 'the_content', array( $this, 'add_lazy_attributes' ), 99 );
		}

		if ( $this->on( 'optimize_fonts' ) ) {
			add_filter( 'style_loader_tag', array( $this, 'font_display_swap' ), 20, 4 );
			add_filter( 'wp_resource_hints', array( $this, 'font_resource_hints' ), 10, 2 );
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
	 * Add loading="lazy" to content images and iframes.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function add_lazy_attributes( $content ) {
		if ( is_feed() || is_admin() || '' === $content ) {
			return $content;
		}

		$content = preg_replace_callback(
			'/<(img|iframe)((?:(?!loading=)[^>])*)>/i',
			static function ( $matches ) {
				return '<' . $matches[1] . $matches[2] . ' loading="lazy" decoding="async">';
			},
			$content
		);

		return $content;
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
	 * Flush OmniNox-owned generated cache files and transients safely.
	 *
	 * Only deletes files inside the plugin's dedicated cache directory
	 * (wp-content/uploads/omninox-site-toolkit/cache/) and OmniNox transient
	 * keys. Never touches core WordPress files, user uploads, or caches from
	 * other plugins.
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

			// 2. Clear OmniNox-owned transients.
			$transients = array(
				'omninox_front_html',
				'omninox_asset_inventory',
				'omninox_speed_test',
				'omninox_cached_overview',
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

