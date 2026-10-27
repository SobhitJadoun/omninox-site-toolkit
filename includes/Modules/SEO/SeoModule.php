<?php
/**
 * Module 7: SEO Auditor.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\SEO;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class SeoModule extends AbstractModule {

	protected $id   = 'seo';
	protected $icon = 'search';

	public function get_label() {
		return __( 'SEO', 'omninox-site-toolkit' );
	}

	/**
	 * Audit published content and the home page markup.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		global $wpdb;

		$limit = absint( $args['limit'] ?? 300 );

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
			)
		);

		$titles          = array();
		$descriptions    = array();
		$missing_meta    = array();
		$title_length    = array();
		$meta_length     = array();
		$duplicate_meta  = array();
		$duplicate_title = array();
		$thin_content    = array();
		$no_h1           = array();
		$multi_h1        = array();
		$missing_alt     = 0;
		$images_total    = 0;
		$internal_links  = 0;

		foreach ( $posts as $post_id ) {
			$title = get_the_title( $post_id );
			$key   = strtolower( trim( $title ) );
			$title_length_value = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );

			if ( $title_length_value < 20 || $title_length_value > 60 ) {
				$title_length[] = array( 'id' => $post_id, 'title' => $title, 'characters' => $title_length_value );
			}

			if ( isset( $titles[ $key ] ) ) {
				$duplicate_title[] = array( 'id' => $post_id, 'title' => $title );
			}

			$titles[ $key ] = true;

			$description = $this->meta_description( $post_id );
			$description_key = strtolower( trim( $description ) );
			$description_length = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );

			if ( '' === $description ) {
				$missing_meta[] = array( 'id' => $post_id, 'title' => $title, 'edit' => get_edit_post_link( $post_id, 'raw' ) );
			} elseif ( $description_length < 70 || $description_length > 160 ) {
				$meta_length[] = array( 'id' => $post_id, 'title' => $title, 'characters' => $description_length );
			}

			if ( '' !== $description && isset( $descriptions[ $description_key ] ) ) {
				$duplicate_meta[] = array( 'id' => $post_id, 'title' => $title );
			}

			$descriptions[ $description_key ] = true;

			$content = (string) get_post_field( 'post_content', $post_id );
			$words   = str_word_count( wp_strip_all_tags( $content ) );

			if ( $words < 300 ) {
				$thin_content[] = array( 'id' => $post_id, 'title' => $title, 'words' => $words );
			}

			$h1_count = preg_match_all( '/<h1[\s>]/i', $content );

			if ( 0 === $h1_count ) {
				$no_h1[] = array( 'id' => $post_id, 'title' => $title );
			} elseif ( $h1_count > 1 ) {
				$multi_h1[] = array( 'id' => $post_id, 'title' => $title, 'count' => $h1_count );
			}

			preg_match_all( '/<img[^>]*>/i', $content, $images );

			foreach ( $images[0] as $tag ) {
				++$images_total;

				if ( ! preg_match( '/alt=["\'][^"\']+["\']/i', $tag ) ) {
					++$missing_alt;
				}
			}

			$internal_links += preg_match_all( '#<a[^>]+href=["\']' . preg_quote( home_url(), '#' ) . '#i', $content );
		}

		$total = max( 1, count( $posts ) );

		$this->add(
			array(
				'id'          => 'title_length',
				'label'       => __( 'Titles outside the recommended range', 'omninox-site-toolkit'),
				'value'       => (string) count( $title_length ),
				'expected'    => '0',
				'weight'      => 1.5,
				'severity'    => empty( $title_length ) ? self::SEV_PASS : self::SEV_NOTICE,
				'description' => __( 'Google may truncate long titles. This is a review prompt, not a ranking penalty; write for people first.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => array_slice( $title_length, 0, 50 ) ),
			)
		);

		$this->add(
			array(
				'id'       => 'missing_meta_description',
				'label'    => __( 'Published items without a meta description', 'omninox-site-toolkit'),
				'value'    => (string) count( $missing_meta ),
				'expected' => '0',
				'weight'   => 2.5,
				'severity' => count( $missing_meta ) === 0 ? self::SEV_PASS : ( count( $missing_meta ) > $total * 0.3 ? self::SEV_WARNING : self::SEV_NOTICE ),
				'context'  => array( 'items' => array_slice( $missing_meta, 0, 50 ) ),
			)
		);

		$this->add(
			array(
				'id'          => 'meta_description_length',
				'label'       => __( 'Meta descriptions outside the recommended range', 'omninox-site-toolkit'),
				'value'       => (string) count( $meta_length ),
				'expected'    => '0',
				'weight'      => 1,
				'severity'    => empty( $meta_length ) ? self::SEV_PASS : self::SEV_NOTICE,
				'description' => __( 'Descriptions are snippets, not a direct ranking factor. Keep them useful and specific; Google may rewrite them.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => array_slice( $meta_length, 0, 50 ) ),
			)
		);

		$this->add(
			array(
				'id'          => 'duplicate_meta_descriptions',
				'label'       => __( 'Duplicate meta descriptions', 'omninox-site-toolkit'),
				'value'       => (string) count( $duplicate_meta ),
				'expected'    => '0',
				'weight'      => 1,
				'severity'    => empty( $duplicate_meta ) ? self::SEV_PASS : self::SEV_NOTICE,
				'description' => __( 'Unique descriptions help searchers distinguish similar pages. They are not a substitute for useful page content.', 'omninox-site-toolkit'),
				'context'     => array( 'items' => array_slice( $duplicate_meta, 0, 50 ) ),
			)
		);

		$this->add(
			array(
				'id'       => 'duplicate_titles',
				'label'    => __( 'Duplicate titles', 'omninox-site-toolkit'),
				'value'    => (string) count( $duplicate_title ),
				'expected' => '0',
				'weight'   => 2,
				'severity' => empty( $duplicate_title ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'items' => array_slice( $duplicate_title, 0, 50 ) ),
			)
		);

		$this->add(
			array(
				'id'       => 'missing_h1',
				'label'    => __( 'Content without an H1', 'omninox-site-toolkit'),
				'value'    => (string) count( $no_h1 ),
				'weight'   => 1.5,
				'severity' => count( $no_h1 ) > $total * 0.5 ? self::SEV_NOTICE : self::SEV_PASS,
				'context'  => array( 'items' => array_slice( $no_h1, 0, 30 ) ),
				'description' => __( 'Many themes output the H1 from the template rather than the content, so check a page before acting on this.', 'omninox-site-toolkit'),
			)
		);

		$this->add(
			array(
				'id'       => 'multiple_h1',
				'label'    => __( 'Content with more than one H1', 'omninox-site-toolkit'),
				'value'    => (string) count( $multi_h1 ),
				'expected' => '0',
				'weight'   => 1.5,
				'severity' => empty( $multi_h1 ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'items' => array_slice( $multi_h1, 0, 30 ) ),
			)
		);

		$this->add(
			array(
				'id'       => 'image_alt_text',
				'label'    => __( 'Content images without alt text', 'omninox-site-toolkit'),
				'value'    => sprintf( '%d / %d', $missing_alt, $images_total ),
				'expected' => '0',
				'weight'   => 2,
				'severity' => 0 === $missing_alt ? self::SEV_PASS : ( $missing_alt > 20 ? self::SEV_WARNING : self::SEV_NOTICE ),
			)
		);

		$this->add(
			array(
				'id'       => 'thin_content',
				'label'    => __( 'Pages under 300 words', 'omninox-site-toolkit'),
				'value'    => (string) count( $thin_content ),
				'weight'   => 1,
				'severity' => count( $thin_content ) > $total * 0.4 ? self::SEV_NOTICE : self::SEV_PASS,
				'context'  => array( 'items' => array_slice( $thin_content, 0, 30 ) ),
				'description' => __( 'Word count alone does not determine quality or rankings. Review search intent, originality, usefulness, and completeness instead.', 'omninox-site-toolkit'),
			)
		);

		// Site-wide signals.
		$html = Utils::front_page_html();

		$this->add(
			array(
				'id'       => 'canonical',
				'label'    => __( 'Canonical URL on the home page', 'omninox-site-toolkit'),
				'value'    => preg_match( '/rel=["\']canonical["\']/i', $html ) ? __( 'Present', 'omninox-site-toolkit') : __( 'Missing', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => preg_match( '/rel=["\']canonical["\']/i', $html ) ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$has_schema = false !== stripos( $html, 'application/ld+json' );
		$schema_errors = $this->schema_errors( $html );
		$this->add(
			array(
				'id'       => 'schema',
				'label'    => __( 'Structured data (JSON-LD)', 'omninox-site-toolkit'),
				'value'    => $has_schema ? __( 'Present', 'omninox-site-toolkit') : __( 'Missing', 'omninox-site-toolkit'),
				'weight'   => 1.5,
				'severity' => $has_schema ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		$this->add(
			array(
				'id'          => 'schema_validity',
				'label'       => __( 'Structured data JSON-LD validity', 'omninox-site-toolkit'),
				'value'       => $has_schema ? ( $schema_errors ? sprintf( __( '%d invalid block(s)', 'omninox-site-toolkit'), count( $schema_errors ) ) : __( 'Valid JSON', 'omninox-site-toolkit') ) : __( 'Not detected', 'omninox-site-toolkit'),
				'expected'    => $has_schema ? 'Valid JSON' : 'Optional',
				'weight'      => 2,
				'severity'    => ! $has_schema ? self::SEV_NOTICE : ( $schema_errors ? self::SEV_WARNING : self::SEV_PASS ),
				'description' => __( 'Valid JSON-LD is required before Google can interpret structured data. Schema still needs testing in Google’s Rich Results Test.', 'omninox-site-toolkit'),
				'context'     => array( 'blocks' => $schema_errors ),
			)
		);

		$has_og = false !== stripos( $html, 'property="og:' );
		$this->add(
			array(
				'id'       => 'open_graph',
				'label'    => __( 'Open Graph tags', 'omninox-site-toolkit'),
				'value'    => $has_og ? __( 'Present', 'omninox-site-toolkit') : __( 'Missing', 'omninox-site-toolkit'),
				'weight'   => 1,
				'severity' => $has_og ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		$indexable = '0' !== get_option( 'blog_public' );
		$this->add(
			array(
				'id'          => 'search_engine_visibility',
				'label'       => __( 'Search engine indexing', 'omninox-site-toolkit'),
				'value'       => $indexable ? __( 'Allowed', 'omninox-site-toolkit') : __( 'Blocked', 'omninox-site-toolkit'),
				'weight'      => 4,
				'severity'    => $indexable ? self::SEV_PASS : self::SEV_CRITICAL,
				'fix'         => $indexable ? '' : 'allow_indexing',
				'description' => $indexable ? '' : __( 'Settings → Reading is set to discourage search engines. The site cannot rank while this is on.', 'omninox-site-toolkit'),
			)
		);

		$has_noindex = (bool) preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $html );
		$this->add(
			array(
				'id'          => 'noindex_directive',
				'label'       => __( 'Page-level noindex directive', 'omninox-site-toolkit'),
				'value'       => $has_noindex ? __( 'Present', 'omninox-site-toolkit') : __( 'Not detected', 'omninox-site-toolkit'),
				'expected'    => 'Not detected',
				'weight'      => 3,
				'severity'    => $has_noindex ? self::SEV_CRITICAL : self::SEV_PASS,
				'description' => $has_noindex ? __( 'The rendered home page asks crawlers not to index it. Remove this only if the noindex directive is accidental.', 'omninox-site-toolkit') : '',
			)
		);

		$is_https = 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		$this->add(
			array(
				'id'          => 'https',
				'label'       => __( 'HTTPS site URL', 'omninox-site-toolkit'),
				'value'       => $is_https ? __( 'Enabled', 'omninox-site-toolkit') : __( 'Not enabled', 'omninox-site-toolkit'),
				'expected'    => 'Enabled',
				'weight'      => 3,
				'severity'    => $is_https ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'HTTPS protects users and is a strong modern web baseline. Configure the server and WordPress URLs before changing this automatically.', 'omninox-site-toolkit'),
			)
		);

		$has_viewport = (bool) preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $html );
		$has_lang = (bool) preg_match( '/<html[^>]+lang=["\'][^"\']+["\']/i', $html );
		$this->add(
			array(
				'id'       => 'mobile_basics',
				'label'    => __( 'Mobile viewport and document language', 'omninox-site-toolkit'),
				'value'    => ( $has_viewport ? 'Viewport' : 'No viewport' ) . ' / ' . ( $has_lang ? 'lang' : 'No lang' ),
				'expected' => 'Viewport / lang',
				'weight'   => 1.5,
				'severity' => $has_viewport && $has_lang ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'A responsive viewport supports mobile usability, while lang helps browsers and assistive technology interpret the page.', 'omninox-site-toolkit'),
			)
		);

		$sitemap = $this->sitemap_status();
		$this->add(
			array(
				'id'       => 'sitemap',
				'label'    => __( 'XML sitemap', 'omninox-site-toolkit'),
				'value'    => $sitemap ?: __( 'Not found', 'omninox-site-toolkit'),
				'weight'   => 2,
				'severity' => $sitemap ? self::SEV_PASS : self::SEV_WARNING,
			)
		);

		$robots = Utils::timed_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
		$robots_ok = ! is_wp_error( $robots ) && 200 === (int) $robots['code'];
		$robots_blocks_all = $robots_ok && (bool) preg_match( '/Disallow:\s*\/\s*$/mi', $robots['body'] );
		$this->add(
			array(
				'id'       => 'robots_txt',
				'label'    => __( 'robots.txt', 'omninox-site-toolkit'),
				'value'    => $robots_ok ? __( 'Reachable', 'omninox-site-toolkit') : __( 'Missing', 'omninox-site-toolkit'),
				'weight'   => 1,
				'severity' => $robots_blocks_all ? self::SEV_CRITICAL : ( $robots_ok ? self::SEV_PASS : self::SEV_NOTICE ),
				'description' => $robots_blocks_all ? __( 'This robots.txt blocks every path for all crawlers. Remove the broad block unless this is an intentional staging site.', 'omninox-site-toolkit') : '',
			)
		);

		$broken = $this->broken_links( absint( $args['link_limit'] ?? 60 ) );
		$this->add(
			array(
				'id'       => 'broken_links',
				'label'    => __( 'Broken outbound links', 'omninox-site-toolkit'),
				'value'    => (string) count( $broken ),
				'expected' => '0',
				'weight'   => 2,
				'severity' => empty( $broken ) ? self::SEV_PASS : self::SEV_WARNING,
				'context'  => array( 'links' => $broken ),
			)
		);

		return $this->result(
			array(
				'analysed_posts' => count( $posts ),
				'internal_links' => $internal_links,
			)
		);
	}

	/**
	 * Fixes.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		if ( 'allow_indexing' === $action ) {
			update_option( 'blog_public', '1' );

			return array( 'message' => __( 'Search engines can index the site again.', 'omninox-site-toolkit') );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Read the description from whichever SEO plugin is in use.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function meta_description( $post_id ) {
		$keys = array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_genesis_description' );

		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( $value ) {
				return (string) $value;
			}
		}

		if ( has_excerpt( $post_id ) ) {
			return (string) get_the_excerpt( $post_id );
		}

		return '';
	}

	/**
	 * Which sitemap is being served?
	 *
	 * @return string
	 */
	private function sitemap_status() {
		foreach ( array( 'sitemap.xml', 'sitemap_index.xml', 'wp-sitemap.xml' ) as $path ) {
			$response = wp_remote_head( home_url( '/' . $path ), array( 'timeout' => 10, 'redirection' => 2 ) );

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return '/' . $path;
			}
		}

		return '';
	}

	/**
	 * Find malformed JSON-LD blocks in rendered markup.
	 *
	 * @param string $html Rendered markup.
	 * @return array
	 */
	private function schema_errors( $html ) {
		$errors = array();

		preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $blocks );

		foreach ( $blocks[1] ?? array() as $index => $block ) {
			json_decode( trim( html_entity_decode( $block, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) ), true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$errors[] = array(
					'block'  => $index + 1,
					'error'  => json_last_error_msg(),
				);
			}
		}

		return $errors;
	}

	/**
	 * Sample outbound links and report dead ones.
	 *
	 * @param int $limit How many links to check.
	 * @return array
	 */
	private function broken_links( $limit = 60 ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_content LIKE %s LIMIT 200",
				'%<a %'
			),
			ARRAY_A
		);

		$links = array();

		foreach ( (array) $rows as $row ) {
			preg_match_all( '#<a[^>]+href=["\'](https?://[^"\']+)["\']#i', $row['post_content'], $matches );

			foreach ( $matches[1] ?? array() as $url ) {
				if ( 0 === strpos( $url, home_url() ) ) {
					continue;
				}

				$links[ $url ] = (int) $row['ID'];

				if ( count( $links ) >= $limit ) {
					break 2;
				}
			}
		}

		$broken = array();

		foreach ( $links as $url => $post_id ) {
			$response = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 3 ) );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( 0 === $code || $code >= 400 ) {
				$broken[] = array(
					'url'     => $url,
					'status'  => $code ?: __( 'No response', 'omninox-site-toolkit'),
					'post_id' => $post_id,
					'edit'    => get_edit_post_link( $post_id, 'raw' ),
				);
			}
		}

		return $broken;
	}
}
