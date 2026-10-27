<?php
/**
 * Shared helpers.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Helpers;

defined( 'ABSPATH' ) || exit;

class Utils {

	/**
	 * Convert a php.ini shorthand size to bytes.
	 *
	 * @param string $value e.g. "256M".
	 * @return int
	 */
	public static function to_bytes( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// no break.
			case 'm':
				$number *= 1024;
				// no break.
			case 'k':
				$number *= 1024;
		}

		return (int) $number;
	}

	/**
	 * Human readable byte size.
	 *
	 * @param int $bytes    Bytes.
	 * @param int $decimals Decimals.
	 * @return string
	 */
	public static function format_bytes( $bytes, $decimals = 1 ) {
		return size_format( max( 0, (int) $bytes ), $decimals ) ?: '0 B';
	}

	/**
	 * Recursively measure a directory.
	 *
	 * @param string $dir      Directory path.
	 * @param int    $max_files Safety cap.
	 * @return array {size, files}
	 */
	public static function dir_size( $dir, $max_files = 50000 ) {
		$size  = 0;
		$files = 0;

		if ( ! is_dir( $dir ) ) {
			return compact( 'size', 'files' );
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
					++$files;
				}

				if ( $files >= $max_files ) {
					break;
				}
			}
		} catch ( \Exception $e ) {
			return compact( 'size', 'files' );
		}

		return compact( 'size', 'files' );
	}

	/**
	 * Fetch a URL and return timing plus body.
	 *
	 * @param string $url  URL.
	 * @param array  $args Request args.
	 * @return array|\WP_Error
	 */
	public static function timed_get( $url, array $args = array() ) {
		$start = microtime( true );

		$response = wp_remote_get(
			$url,
			wp_parse_args(
				$args,
				array(
					'timeout'    => 20,
					'sslverify'  => true,
					'user-agent' => 'OmninoxSiteToolkit/' . OMNINOX_SITE_TOOLKIT_VERSION . '; ' . home_url(),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code'    => wp_remote_retrieve_response_code( $response ),
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
			'body'    => wp_remote_retrieve_body( $response ),
			'ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/**
	 * Whether a plugin file is active (network-aware).
	 *
	 * @param string $plugin_file e.g. "woocommerce/woocommerce.php".
	 * @return bool
	 */
	public static function plugin_active( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}

	/**
	 * Get the raw front-end HTML of the home page, cached briefly.
	 *
	 * @param bool $refresh Force refresh.
	 * @return string
	 */
	public static function front_page_html( $refresh = false ) {
		$key  = 'omninox_front_html';
		$html = $refresh ? false : get_transient( $key );

		if ( false === $html ) {
			$result = self::timed_get( home_url( '/' ), array( 'timeout' => 25 ) );
			$html   = is_wp_error( $result ) ? '' : $result['body'];
			set_transient( $key, $html, 10 * MINUTE_IN_SECONDS );
		}

		return (string) $html;
	}

	/**
	 * Get the absolute filesystem path to the root of the WordPress installation.
	 *
	 * Uses WordPress core get_home_path() if available, with robust fallback.
	 *
	 * @return string
	 */
	public static function get_home_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
		}

		if ( function_exists( 'get_home_path' ) ) {
			return wp_normalize_path( get_home_path() );
		}

		return defined( 'ABSPATH' ) ? wp_normalize_path( ABSPATH ) : '';
	}

	/**
	 * Get the absolute filesystem path to wp-config.php.
	 *
	 * @return string
	 */
	public static function get_wp_config_path() {
		$home = self::get_home_path();

		if ( file_exists( trailingslashit( $home ) . 'wp-config.php' ) ) {
			return wp_normalize_path( trailingslashit( $home ) . 'wp-config.php' );
		}

		if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-config.php' ) ) {
			return wp_normalize_path( ABSPATH . 'wp-config.php' );
		}

		$parent = dirname( untrailingslashit( $home ) );
		if ( file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
			return wp_normalize_path( $parent . '/wp-config.php' );
		}

		return '';
	}

	/**
	 * Get the absolute filesystem path to the WordPress plugins directory.
	 *
	 * Determined dynamically from the plugin's own bootstrap location.
	 *
	 * @return string
	 */
	public static function get_plugins_dir() {
		if ( defined( 'OMNINOX_PLUGIN_DIR' ) ) {
			return wp_normalize_path( dirname( untrailingslashit( OMNINOX_PLUGIN_DIR ) ) );
		}
		if ( defined( 'OMNINOX_SITE_TOOLKIT_PATH' ) ) {
			return wp_normalize_path( dirname( untrailingslashit( OMNINOX_SITE_TOOLKIT_PATH ) ) );
		}
		if ( function_exists( 'plugin_dir_path' ) ) {
			return wp_normalize_path( dirname( plugin_dir_path( __DIR__ ) ) );
		}
		return wp_normalize_path( dirname( dirname( __DIR__ ) ) );
	}

	/**
	 * Get the absolute filesystem path to the WordPress content directory.
	 *
	 * Determined dynamically using WordPress get_theme_root() or plugin parent directory.
	 *
	 * @return string
	 */
	public static function get_content_dir() {
		if ( function_exists( 'get_theme_root' ) ) {
			$theme_root = get_theme_root();
			if ( ! empty( $theme_root ) ) {
				return wp_normalize_path( dirname( $theme_root ) );
			}
		}
		return wp_normalize_path( dirname( self::get_plugins_dir() ) );
	}

	/**
	 * Safely match a URL against a base URL and convert to filesystem path with boundary checking.
	 *
	 * @param string $url URL to test.
	 * @param string $base_url Base URL.
	 * @param string $base_dir Corresponding base filesystem directory.
	 * @return string|false Resolved path or false if not matched.
	 */
	private static function match_url_to_dir( $url, $base_url, $base_dir ) {
		if ( empty( $base_url ) || empty( $base_dir ) ) {
			return false;
		}

		$clean_base_url = untrailingslashit( $base_url );

		if ( $url === $clean_base_url || 0 === strpos( $url, $clean_base_url . '/' ) ) {
			$rel = ltrim( substr( $url, strlen( $clean_base_url ) ), '/' );
			return wp_normalize_path( trailingslashit( $base_dir ) . $rel );
		}

		return false;
	}

	/**
	 * Map a site URL to local filesystem path if it is hosted locally.
	 *
	 * Uses WordPress core APIs (wp_upload_dir, get_stylesheet_directory,
	 * get_template_directory, get_theme_root, plugins_url, content_url,
	 * home_url, and get_home_path) rather than hardcoded paths or constants.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_to_path( $url ) {
		$url = strtok( (string) $url, '?' );
		if ( ! $url ) {
			return '';
		}

		// 1. Uploads directory (wp_upload_dir)
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$path = self::match_url_to_dir( $url, $uploads['baseurl'], $uploads['basedir'] );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 2. Active child/stylesheet theme directory
		if ( function_exists( 'get_stylesheet_directory_uri' ) && function_exists( 'get_stylesheet_directory' ) ) {
			$path = self::match_url_to_dir( $url, get_stylesheet_directory_uri(), get_stylesheet_directory() );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 3. Parent template theme directory
		if ( function_exists( 'get_template_directory_uri' ) && function_exists( 'get_template_directory' ) ) {
			$path = self::match_url_to_dir( $url, get_template_directory_uri(), get_template_directory() );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 4. Themes directory
		if ( function_exists( 'get_theme_root_uri' ) && function_exists( 'get_theme_root' ) ) {
			$path = self::match_url_to_dir( $url, get_theme_root_uri(), get_theme_root() );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 5. OmniNox Plugin itself
		if ( defined( 'OMNINOX_PLUGIN_URL' ) && defined( 'OMNINOX_PLUGIN_DIR' ) ) {
			$path = self::match_url_to_dir( $url, OMNINOX_PLUGIN_URL, OMNINOX_PLUGIN_DIR );
			if ( false !== $path ) {
				return $path;
			}
		} elseif ( defined( 'OMNINOX_SITE_TOOLKIT_URL' ) && defined( 'OMNINOX_SITE_TOOLKIT_PATH' ) ) {
			$path = self::match_url_to_dir( $url, OMNINOX_SITE_TOOLKIT_URL, OMNINOX_SITE_TOOLKIT_PATH );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 6. Plugins directory (plugins_url)
		$plugins_url = plugins_url();
		if ( ! empty( $plugins_url ) ) {
			$path = self::match_url_to_dir( $url, $plugins_url, self::get_plugins_dir() );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 7. Content directory (content_url)
		$content_url = content_url();
		if ( ! empty( $content_url ) ) {
			$path = self::match_url_to_dir( $url, $content_url, self::get_content_dir() );
			if ( false !== $path ) {
				return $path;
			}
		}

		// 8. Site URL / Home URL (home_url / site_url -> get_home_path)
		$home_url = home_url();
		if ( ! empty( $home_url ) ) {
			$path = self::match_url_to_dir( $url, $home_url, self::get_home_path() );
			if ( false !== $path ) {
				return $path;
			}
		}

		$site_url = site_url();
		if ( ! empty( $site_url ) ) {
			$path = self::match_url_to_dir( $url, $site_url, self::get_home_path() );
			if ( false !== $path ) {
				return $path;
			}
		}

		return '';
	}
}
