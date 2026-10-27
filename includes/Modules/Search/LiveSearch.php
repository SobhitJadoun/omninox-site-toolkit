<?php
/**
 * Live AJAX search and autocompletion handler.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Search;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class LiveSearch {

	use Singleton;

	/**
	 * Boot the live search hooks.
	 */
	public function boot() {
		add_action( 'wp_ajax_omninox_live_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_omninox_live_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue front-end live search script if active.
	 */
	public function enqueue_assets() {
		if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'search', 'live_search' ) ) {
			return;
		}

		$enabled = (bool) Settings::instance()->get( 'search.enabled', true );
		$live    = (bool) Settings::instance()->get( 'search.live_search', true );

		if ( ! $enabled || ! $live || is_admin() ) {
			return;
		}

		$js_path = OMNINOX_SITE_TOOLKIT_PATH . 'assets/js/search.js';
		$version = file_exists( $js_path ) ? filemtime( $js_path ) : OMNINOX_SITE_TOOLKIT_VERSION;

		wp_enqueue_script(
			'omninox-live-search',
			OMNINOX_SITE_TOOLKIT_URL . 'assets/js/search.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			'omninox-live-search',
			'OmninoxSearch',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'omninox_search_nonce' ),
				'noResults' => __( 'No results found', 'omninox-site-toolkit' ),
				'minChars'  => 2,
			)
		);
	}

	/**
	 * AJAX endpoint handler for frontend live search.
	 */
	public function ajax_search() {
		check_ajax_referer( 'omninox_search_nonce', 'nonce' );

		if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'search', 'live_search' ) ) {
			wp_send_json_error( array( 'message' => __( 'Live search requires Omninox Pro.', 'omninox-site-toolkit' ) ), 403 );
		}

		$term = sanitize_text_field( wp_unslash( $_REQUEST['term'] ?? '' ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
		if ( '' === $term || $len < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$results = self::query( $term, 8 );
		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Perform a search query and return formatted results with score breakdown.
	 *
	 * @param string $term  Search query.
	 * @param int    $limit Max items to return.
	 * @return array
	 */
	public static function query( $term, $limit = 10 ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}

		$settings   = (array) Settings::instance()->get( 'search', array() );
		$post_types = (array) ( $settings['post_types'] ?? array( 'post', 'page' ) );

		$args = array(
			's'                   => $term,
			'posts_per_page'      => max( 1, min( 50, (int) $limit ) ),
			'post_type'           => ! empty( $post_types ) ? $post_types : 'any',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		$query = new \WP_Query( $args );
		$items = array();

		if ( $query->have_posts() ) {
			$highlighter = Highlighter::instance();
			$terms       = RelevanceEngine::parse_query( $term )['all_terms'];

			while ( $query->have_posts() ) {
				$query->the_post();
				$post = get_post();

				$raw_title   = get_the_title();
				$raw_excerpt = has_excerpt() ? get_the_excerpt() : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 25, '…' );

				$escaped_title   = esc_html( (string) $raw_title );
				$escaped_excerpt = esc_html( (string) $raw_excerpt );

				$highlighted_title   = $highlighter->apply_title_highlight( $escaped_title, $terms );
				$highlighted_excerpt = $highlighter->apply_highlight( $escaped_excerpt, $terms );

				$score         = isset( $post->omninox_relevance_score ) ? (int) $post->omninox_relevance_score : 0;
				$title_score   = isset( $post->omninox_title_score ) ? (int) $post->omninox_title_score : 0;
				$content_score = isset( $post->omninox_content_score ) ? (int) $post->omninox_content_score : 0;
				$terms_score   = isset( $post->omninox_terms_score ) ? (int) $post->omninox_terms_score : 0;
				$meta_score    = isset( $post->omninox_meta_score ) ? (int) $post->omninox_meta_score : 0;

				$categories = get_the_category();
				$cat_name   = ! empty( $categories ) ? $categories[0]->name : '';

				$thumb = get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' ) ?: '';

				$post_type_obj = get_post_type_object( get_post_type() );
				$type_name     = ( $post_type_obj && isset( $post_type_obj->labels->singular_name ) ) ? $post_type_obj->labels->singular_name : get_post_type();

				$items[] = array(
					'id'                  => (int) get_the_ID(),
					'title'               => $escaped_title,
					'highlighted_title'   => $highlighted_title,
					'url'                 => esc_url( (string) get_permalink() ),
					'date'                => esc_html( (string) get_the_date() ),
					'type'                => esc_html( (string) $type_name ),
					'category'            => esc_html( (string) $cat_name ),
					'thumbnail'           => esc_url( (string) $thumb ),
					'excerpt'             => $escaped_excerpt,
					'highlighted_excerpt' => $highlighted_excerpt,
					'score'               => $score,
					'title_score'         => $title_score,
					'content_score'       => $content_score,
					'terms_score'         => $terms_score,
					'meta_score'          => $meta_score,
				);
			}
			wp_reset_postdata();
		}

		return $items;
	}
}
