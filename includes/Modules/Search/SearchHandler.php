<?php
/**
 * Main Search Query Handler for Omninox Site Toolkit.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Search;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class SearchHandler {

	use Singleton;

	/**
	 * Boot the search filters.
	 */
	public function boot() {
		add_action( 'pre_get_posts', array( $this, 'filter_query_args' ), 20 );
		add_filter( 'posts_search', array( $this, 'filter_posts_search' ), 20, 2 );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 20, 2 );
		add_filter( 'the_posts', array( $this, 'maybe_fallback_search' ), 20, 2 );
	}

	/**
	 * Check whether enhanced search should run on the given query.
	 *
	 * @param \WP_Query $query Query instance.
	 * @return bool
	 */
	public function should_enhance( \WP_Query $query ) {
		if ( ! $query->is_search() ) {
			return false;
		}

		// Don't modify administrative search screens unless explicitly enabled
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$admin_search = (bool) Settings::instance()->get( 'search.admin_search', false );
			if ( ! $admin_search ) {
				return false;
			}
		}

		$enabled = (bool) Settings::instance()->get( 'search.enabled', true );

		/**
		 * Filter whether enhanced search is active for this query.
		 *
		 * @param bool      $enabled Whether search is enabled.
		 * @param \WP_Query $query   The query object.
		 */
		return (bool) apply_filters( 'omninox_search_enabled', $enabled, $query );
	}

	/**
	 * Configure post types and query arguments before query runs.
	 *
	 * @param \WP_Query $query The query object.
	 */
	public function filter_query_args( \WP_Query $query ) {
		if ( ! $this->should_enhance( $query ) ) {
			return;
		}

		$search_query = trim( (string) $query->get( 's' ) );
		if ( '' === $search_query ) {
			return;
		}

		$settings = (array) Settings::instance()->get( 'search', array() );

		// Normalize search query in query object if normalization is enabled
		if ( ! isset( $settings['normalize_queries'] ) || ! empty( $settings['normalize_queries'] ) ) {
			$normalized = RelevanceEngine::normalize_query( $search_query );
			if ( $normalized !== $search_query ) {
				$query->set( 's', $normalized );
				$search_query = $normalized;
			}
		}

		// Custom post types configuration
		if ( empty( $query->get( 'post_type' ) ) || 'any' === $query->get( 'post_type' ) ) {
			$post_types = (array) ( $settings['post_types'] ?? array( 'post', 'page' ) );

			// Exclude specified post types
			$excluded_types = (array) ( $settings['excluded_post_types'] ?? array( 'attachment', 'revision', 'nav_menu_item' ) );
			$post_types     = array_diff( $post_types, $excluded_types );

			if ( empty( $post_types ) ) {
				$post_types = array( 'post', 'page' );
			}

			/**
			 * Filter searchable post types.
			 *
			 * @param array     $post_types Array of post type slugs.
			 * @param \WP_Query $query      The query object.
			 */
			$post_types = (array) apply_filters( 'omninox_search_post_types', $post_types, $query );

			$query->set( 'post_type', $post_types );
		}

		// Optional posts_per_page override
		$posts_per_page = (int) ( $settings['posts_per_page'] ?? 0 );
		if ( $posts_per_page > 0 && ! $query->get( 'posts_per_page' ) ) {
			$query->set( 'posts_per_page', $posts_per_page );
		}

		// Ensure suppressed filters are not active so posts_clauses runs
		$query->set( 'suppress_filters', false );
	}

	/**
	 * Replace WordPress default search WHERE clause completely with our enhanced relevance WHERE clause.
	 *
	 * @param string    $search Search SQL condition.
	 * @param \WP_Query $query  The query object.
	 * @return string
	 */
	public function filter_posts_search( $search, \WP_Query $query ) {
		if ( ! $this->should_enhance( $query ) || empty( $search ) ) {
			return $search;
		}

		$search_query = trim( (string) $query->get( 's' ) );
		if ( '' === $search_query ) {
			return $search;
		}

		$settings      = (array) Settings::instance()->get( 'search', array() );
		$override_mode = (string) $query->get( 'omninox_matching_mode' );
		$built         = RelevanceEngine::build_clauses( $search_query, $settings, $override_mode );

		if ( empty( $built ) || empty( $built['where_conditions'] ) ) {
			return $search;
		}

		return ' AND (' . implode( ' AND ', $built['where_conditions'] ) . ') ';
	}

	/**
	 * Modify query clauses for relevance scoring, SELECT fields, and JOINs.
	 *
	 * @param array     $clauses Array of SQL query clauses.
	 * @param \WP_Query $query   The query object.
	 * @return array
	 */
	public function filter_posts_clauses( array $clauses, \WP_Query $query ) {
		if ( ! $this->should_enhance( $query ) ) {
			return $clauses;
		}

		$search_query = trim( (string) $query->get( 's' ) );
		if ( '' === $search_query ) {
			return $clauses;
		}

		global $wpdb;

		$settings      = (array) Settings::instance()->get( 'search', array() );
		$override_mode = (string) $query->get( 'omninox_matching_mode' );
		$built         = RelevanceEngine::build_clauses( $search_query, $settings, $override_mode );

		if ( empty( $built ) ) {
			return $clauses;
		}

		// 1. Add relevance score to SELECT fields
		$clauses['fields'] .= ", ({$built['score_formula']}) AS omninox_relevance_score";
		if ( ! empty( $built['title_score_formula'] ) ) {
			$clauses['fields'] .= ", ({$built['title_score_formula']}) AS omninox_title_score";
		}
		if ( ! empty( $built['content_score_formula'] ) ) {
			$clauses['fields'] .= ", ({$built['content_score_formula']}) AS omninox_content_score";
		}
		if ( ! empty( $built['terms_score_formula'] ) ) {
			$clauses['fields'] .= ", ({$built['terms_score_formula']}) AS omninox_terms_score";
		}
		if ( ! empty( $built['meta_score_formula'] ) ) {
			$clauses['fields'] .= ", ({$built['meta_score_formula']}) AS omninox_meta_score";
		}

		// 2. Add JOIN clauses if necessary (e.g. postmeta, taxonomies)
		if ( ! empty( $built['join_clauses'] ) ) {
			foreach ( $built['join_clauses'] as $join ) {
				if ( false === strpos( $clauses['join'], $join ) ) {
					$clauses['join'] .= ' ' . $join;
				}
			}
		}

		// 3. Set DISTINCT if needed to avoid duplicates from joins
		if ( ! empty( $built['distinct'] ) && false === strpos( $clauses['distinct'], 'DISTINCT' ) ) {
			$clauses['distinct'] = 'DISTINCT ';
		}

		// 4. Set guaranteed relevance-based ORDER BY
		$clauses['orderby'] = "omninox_relevance_score DESC, {$wpdb->posts}.post_date DESC";

		/**
		 * Filter the modified search clauses.
		 *
		 * @param array     $clauses The SQL query clauses.
		 * @param \WP_Query $query   The query object.
		 * @param array     $built   Calculated relevance components.
		 */
		return apply_filters( 'omninox_search_clauses', $clauses, $query, $built );
	}

	/**
	 * Smart search fallback: If strict AND search produces 0 results, relax to OR matching.
	 *
	 * @param array     $posts Array of retrieved post objects.
	 * @param \WP_Query $query Query object.
	 * @return array
	 */
	public function maybe_fallback_search( $posts, \WP_Query $query ) {
		if ( ! empty( $posts ) || ! $this->should_enhance( $query ) ) {
			return $posts;
		}

		if ( $query->get( 'omninox_fallback_done' ) ) {
			return $posts;
		}

		$search_query = trim( (string) $query->get( 's' ) );
		$parsed       = RelevanceEngine::parse_query( $search_query );

		// Only fallback if multiple keywords were searched
		if ( count( $parsed['all_terms'] ) <= 1 && empty( $parsed['slugs'] ) ) {
			return $posts;
		}

		$settings = (array) Settings::instance()->get( 'search', array() );
		$mode     = $settings['matching_mode'] ?? 'smart_fallback';

		if ( 'smart_fallback' !== $mode && empty( $settings['smart_fallback'] ) ) {
			return $posts;
		}

		// Re-run fallback query with relaxed 'any' (OR) matching mode
		$fallback_args                       = $query->query_vars;
		$fallback_args['omninox_fallback_done'] = true;
		$fallback_args['omninox_matching_mode'] = 'any';
		$fallback_args['no_found_rows']      = false;

		$fallback_query = new \WP_Query();
		$fallback_posts = $fallback_query->query( $fallback_args );

		if ( ! empty( $fallback_posts ) ) {
			$query->posts                 = $fallback_posts;
			$query->post_count            = count( $fallback_posts );
			$query->found_posts           = $fallback_query->found_posts;
			$query->max_num_pages         = $fallback_query->max_num_pages;
			$query->set( 'omninox_relaxed_fallback', true );
			return $fallback_posts;
		}

		return $posts;
	}
}
