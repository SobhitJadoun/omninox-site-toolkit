<?php
/**
 * Content Reference Provider.
 *
 * Scans published, draft, scheduled, private posts, pages, CPTs, revisions,
 * reusable blocks (wp_block), navigation blocks, and shortcodes.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Media\Unused\Providers;

use OmninoxSiteToolkit\Modules\Media\Unused\ReferenceIndex;

defined( 'ABSPATH' ) || exit;

class ContentProvider implements ReferenceProviderInterface {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'content';
	}

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordPress Content (Posts, Pages, Blocks & Patterns)', 'omninox-site-toolkit' );
	}

	/**
	 * Collect references into the index.
	 *
	 * @param ReferenceIndex $index Reference index instance.
	 * @param array          $options Scan options.
	 */
	public function collect_references( ReferenceIndex $index, array $options = array() ): void {
		global $wpdb;

		$start_time = microtime( true );

		// Query public and content-bearing post types using indexed post_type lookups
		$types = get_post_types( array( 'public' => true ) );
		$types['wp_block']      = 'wp_block';
		$types['wp_navigation'] = 'wp_navigation';
		$type_in = "'" . implode( "','", array_map( 'esc_sql', array_values( $types ) ) ) . "'";

		// Active post statuses
		$statuses = array( 'publish', 'draft', 'future', 'private', 'pending', 'inherit' );
		$status_in = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// Process posts using fast keyset pagination (ID > $last_id)
		$last_id    = 0;
		$chunk_size = 200;
		$max_posts  = 2000;
		$processed  = 0;

		while ( $processed < $max_posts ) {
			if ( ( microtime( true ) - $start_time ) > 2.5 ) {
				break; // Never exceed 2.5 seconds
			}

			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_status, post_content, post_excerpt 
					FROM {$wpdb->posts} 
					WHERE ID > %d 
					AND post_type IN ({$type_in}) 
					AND post_status IN ({$status_in}) 
					AND (post_content LIKE '%%/uploads/%%' OR post_content LIKE '%%wp-image-%%' OR post_content LIKE '%%src=%%') 
					ORDER BY ID ASC 
					LIMIT %d",
					$last_id,
					$chunk_size
				)
			);

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$last_id = (int) $post->ID;
				$this->inspect_post_content( $index, $post );
				$processed++;
			}

			unset( $posts );

			// Check memory safety
			if ( function_exists( 'memory_get_usage' ) ) {
				$mem = memory_get_usage();
				$limit_str = ini_get( 'memory_limit' );
				$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $limit_str ) : 134217728;
				if ( $limit > 0 && $mem > ( $limit * 0.85 ) ) {
					break;
				}
			}
		}
	}

	/**
	 * Inspect individual post content and excerpt for image references.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param object         $post Post row.
	 */
	private function inspect_post_content( ReferenceIndex $index, $post ): void {
		$content = (string) $post->post_content . ' ' . (string) $post->post_excerpt;
		if ( '' === trim( $content ) ) {
			return;
		}

		$post_id    = (int) $post->ID;
		$post_title = $post->post_title ? $post->post_title : sprintf( __( '#%d (%s)', 'omninox-site-toolkit' ), $post_id, $post->post_type );

		$base_ref = array(
			'type'     => $post->post_type,
			'id'       => $post_id,
			'title'    => $post_title,
			'provider' => 'content',
			'field'    => 'post_content',
		);

		// 1. Gutenberg Block Attributes: <!-- wp:image {"id":42,...} -->
		if ( false !== strpos( $content, '<!-- wp:' ) ) {
			if ( preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+(\{.*?\})\s+-->/s', $content, $matches ) ) {
				foreach ( $matches[1] as $json_str ) {
					$attrs = json_decode( $json_str, true );
					if ( is_array( $attrs ) ) {
						$this->extract_ids_from_array( $index, $attrs, $base_ref, 'block_attribute' );
					}
				}
			}
		}

		// 2. CSS class wp-image-123
		if ( preg_match_all( '/wp-image-(\d+)/i', $content, $class_matches ) ) {
			foreach ( $class_matches[1] as $img_id ) {
				$ref = array_merge( $base_ref, array( 'method' => 'wp_image_class' ) );
				$index->add_id( (int) $img_id, $ref );
			}
		}

		// 3. Shortcodes: [gallery ids="1,2,3"], [image id="42"]
		if ( preg_match_all( '/\[(?:gallery|ids|images)[^\]]*\sids=["\']([0-9,\s]+)["\']/i', $content, $gallery_matches ) ) {
			foreach ( $gallery_matches[1] as $ids_str ) {
				$ids = array_map( 'absint', explode( ',', $ids_str ) );
				foreach ( $ids as $id ) {
					if ( $id > 0 ) {
						$ref = array_merge( $base_ref, array( 'method' => 'gallery_shortcode' ) );
						$index->add_id( $id, $ref );
					}
				}
			}
		}

		// 4. HTML Image Tags: <img src="..." srcset="...">
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $src_matches ) ) {
			foreach ( $src_matches[1] as $src ) {
				$ref = array_merge( $base_ref, array( 'method' => 'img_src', 'url' => $src ) );
				$index->add_url( $src, $ref );
				$index->add_filename( wp_basename( $src ), $ref );
			}
		}

		// 5. Srcset variants
		if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/i', $content, $srcset_matches ) ) {
			foreach ( $srcset_matches[1] as $srcset ) {
				$entries = explode( ',', $srcset );
				foreach ( $entries as $entry ) {
					$parts = preg_split( '/\s+/', trim( $entry ) );
					if ( ! empty( $parts[0] ) ) {
						$url = $parts[0];
						$ref = array_merge( $base_ref, array( 'method' => 'srcset', 'url' => $url ) );
						$index->add_url( $url, $ref );
						$index->add_filename( wp_basename( $url ), $ref );
					}
				}
			}
		}

		// 6. Inline background-image: url(...)
		if ( preg_match_all( '/url\(["\']?([^)"\']+\.(?:jpe?g|png|gif|webp|avif))["\']?\)/i', $content, $url_matches ) ) {
			foreach ( $url_matches[1] as $bg_url ) {
				$ref = array_merge( $base_ref, array( 'method' => 'inline_css_url', 'url' => $bg_url ) );
				$index->add_url( $bg_url, $ref );
				$index->add_filename( wp_basename( $bg_url ), $ref );
			}
		}

		// 7. General upload path detection (e.g. uploads/2026/10/file.ext)
		if ( preg_match_all( '#/uploads/(\d{4}/\d{2}/[^"\'\s<>]+)#i', $content, $upload_matches ) ) {
			foreach ( $upload_matches[1] as $rel_path ) {
				$ref = array_merge( $base_ref, array( 'method' => 'upload_path', 'url' => $rel_path ) );
				$index->add_url( $rel_path, $ref );
				$index->add_filename( wp_basename( $rel_path ), $ref );
			}
		}
	}

	/**
	 * Recursively extract numeric attachment IDs and URLs from block attribute arrays.
	 *
	 * @param ReferenceIndex $index Reference index.
	 * @param array          $data Attribute data.
	 * @param array          $base_ref Base reference details.
	 * @param string         $method Detection method.
	 */
	private function extract_ids_from_array( ReferenceIndex $index, array $data, array $base_ref, string $method ): void {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$this->extract_ids_from_array( $index, $v, $base_ref, $method );
				continue;
			}

			// Check ID keys: id, mediaId, attachment_id, image_id
			if ( in_array( strtolower( (string) $k ), array( 'id', 'mediaid', 'attachment_id', 'image_id', 'poster' ), true ) ) {
				if ( is_numeric( $v ) && $v > 0 ) {
					$ref = array_merge( $base_ref, array( 'method' => $method ) );
					$index->add_id( (int) $v, $ref );
				}
			}

			// Check list of IDs (e.g. ids: [1, 2, 3])
			if ( 'ids' === strtolower( (string) $k ) && is_array( $v ) ) {
				foreach ( $v as $sub_id ) {
					if ( is_numeric( $sub_id ) && $sub_id > 0 ) {
						$ref = array_merge( $base_ref, array( 'method' => $method ) );
						$index->add_id( (int) $sub_id, $ref );
					}
				}
			}

			// Check URL strings
			if ( is_string( $v ) && ( false !== strpos( $v, '/uploads/' ) || preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $v ) ) ) {
				$ref = array_merge( $base_ref, array( 'method' => $method . '_url', 'url' => $v ) );
				$index->add_url( $v, $ref );
				$index->add_filename( wp_basename( $v ), $ref );
			}
		}
	}
}
