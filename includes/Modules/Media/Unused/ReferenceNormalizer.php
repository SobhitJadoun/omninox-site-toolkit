<?php
/**
 * Fast Reference and Image URL Normalizer.
 *
 * Provides high-speed URL, filename, and variant normalization without
 * expensive filesystem I/O operations.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused;

defined( 'ABSPATH' ) || exit;

class ReferenceNormalizer {

	/**
	 * Cached uploads base directory and URL.
	 *
	 * @var array|null
	 */
	private static ?array $uploads_info = null;

	/**
	 * Get normalized uploads base info.
	 *
	 * @return array
	 */
	private static function get_uploads(): array {
		if ( null === self::$uploads_info ) {
			$u = wp_upload_dir();
			self::$uploads_info = array(
				'basedir' => wp_normalize_path( trailingslashit( $u['basedir'] ) ),
				'baseurl' => trailingslashit( preg_replace( '/^https?:/i', '', $u['baseurl'] ) ),
			);
		}
		return self::$uploads_info;
	}

	/**
	 * Normalize an image URL or upload relative path.
	 *
	 * @param string $url Image URL.
	 * @return string Normalized relative or standardized path.
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Remove query string and hash fragment
		if ( false !== ( $pos = strpos( $url, '?' ) ) ) {
			$url = substr( $url, 0, $pos );
		}
		if ( false !== ( $pos = strpos( $url, '#' ) ) ) {
			$url = substr( $url, 0, $pos );
		}

		// Standardize slashes and URL decoding
		$url = str_replace( '\\', '/', rawurldecode( $url ) );

		// Strip scheme (http: / https:)
		$url = preg_replace( '/^https?:/i', '', $url );

		$uploads = self::get_uploads();

		// If URL matches base upload URL, reduce to relative upload subpath
		if ( '' !== $uploads['baseurl'] && 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$url = substr( $url, strlen( $uploads['baseurl'] ) );
		} elseif ( false !== ( $pos = strpos( $url, '/uploads/' ) ) ) {
			$url = substr( $url, $pos + 9 );
		}

		return strtolower( ltrim( $url, '/' ) );
	}

	/**
	 * Normalize a filename by stripping dimensions and directory paths.
	 *
	 * Example: "wp-content/uploads/2024/05/banner-1024x768.jpg" -> "banner.jpg"
	 *
	 * @param string $filename_or_url Filename or full URL.
	 * @return string Normalized root filename.
	 */
	public static function normalize_filename( string $filename_or_url ): string {
		$basename = strtolower( trim( wp_basename( rawurldecode( $filename_or_url ) ) ) );
		if ( '' === $basename ) {
			return '';
		}

		// Strip dimension suffix: e.g. -300x200, -1024x768, -150x150, -scaled
		$clean = preg_replace( '/(?:-\d+x\d+|-scaled)(?=\.[a-z0-9]+$)/i', '', $basename );

		return $clean ?: $basename;
	}

	/**
	 * Extract all attachment IDs from raw content or JSON block text.
	 *
	 * @param string $content HTML or JSON content.
	 * @return array<int> List of unique attachment IDs.
	 */
	public static function extract_attachment_ids( string $content ): array {
		if ( '' === $content ) {
			return array();
		}

		$ids = array();

		// 1. wp-image-XXXXX CSS classes
		if ( false !== strpos( $content, 'wp-image-' ) ) {
			if ( preg_match_all( '/\bwp-image-(\d+)\b/i', $content, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		// 2. Gutenberg block JSON "id": 1234
		if ( false !== strpos( $content, '"id"' ) || false !== strpos( $content, '"mediaId"' ) ) {
			if ( preg_match_all( '/"(?:id|mediaId|attachment_id|imageId)"\s*:\s*"?(\d+)"?/i', $content, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		// 3. Shortcodes: ids="12,34,56" or image="123"
		if ( false !== strpos( $content, 'ids=' ) || false !== strpos( $content, 'image=' ) ) {
			if ( preg_match_all( '/\b(?:ids|image|include)\s*=\s*["\']([0-9,\s]+)["\']/i', $content, $m ) ) {
				foreach ( $m[1] as $raw_list ) {
					foreach ( explode( ',', $raw_list ) as $piece ) {
						$clean_id = (int) trim( $piece );
						if ( $clean_id > 0 ) {
							$ids[] = $clean_id;
						}
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Extract all image URLs from raw content or JSON text.
	 *
	 * @param string $content HTML, CSS, or JSON content.
	 * @return array<string> List of discovered URLs.
	 */
	public static function extract_image_urls( string $content ): array {
		if ( '' === $content ) {
			return array();
		}

		$urls = array();

		// Match URLs with standard image extensions
		if ( preg_match_all( '/https?:[^\s"\'<>{}|\\^`]+?\.(?:jpe?g|png|gif|webp|avif|svg)(?=[?#\s"\'<>{}|\\^`]|$)/i', $content, $m ) ) {
			foreach ( $m[0] as $u ) {
				$urls[] = $u;
			}
		}

		// Match relative upload URLs: /wp-content/uploads/...
		if ( false !== strpos( $content, '/uploads/' ) ) {
			if ( preg_match_all( '/(?:\/wp-content)?\/uploads\/[^\s"\'<>{}|\\^`]+?\.(?:jpe?g|png|gif|webp|avif|svg)/i', $content, $m ) ) {
				foreach ( $m[0] as $u ) {
					$urls[] = $u;
				}
			}
		}

		// Match escaped JSON URLs: https:\/\/domain.com\/uploads\/photo.jpg
		if ( false !== strpos( $content, '\/' ) ) {
			if ( preg_match_all( '/(?:https?:)?\\\/\\\/[^\s"\'<>{}|\\^`]+?\.(?:jpe?g|png|gif|webp|avif|svg)/i', $content, $m ) ) {
				foreach ( $m[0] as $u ) {
					$urls[] = str_replace( '\/', '/', $u );
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Test if a given filename is an intermediate or format variant of an original filename.
	 *
	 * @param string $variant Candidate variant filename.
	 * @param string $original Master original attachment filename.
	 * @return bool
	 */
	public static function is_variant_of( string $variant, string $original ): bool {
		$norm_var  = self::normalize_filename( $variant );
		$norm_orig = self::normalize_filename( $original );

		if ( $norm_var === $norm_orig ) {
			return true;
		}

		// Check name without extension (e.g. photo.webp variant of photo.jpg)
		$name_var  = pathinfo( $norm_var, PATHINFO_FILENAME );
		$name_orig = pathinfo( $norm_orig, PATHINFO_FILENAME );

		return ( $name_var === $name_orig );
	}
}
