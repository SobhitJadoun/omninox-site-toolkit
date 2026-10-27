<?php
/**
 * Relevance scoring and SQL clause builder for enhanced search.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Search;

use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class RelevanceEngine {

	/**
	 * Default stop words in English.
	 *
	 * @var array
	 */
	const DEFAULT_STOP_WORDS = array(
		'a', 'about', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
		'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'or', 'that', 'the',
		'to', 'was', 'were', 'will', 'with',
	);

	/**
	 * In-memory parsed query cache.
	 *
	 * @var array
	 */
	private static $query_cache = array();

	/**
	 * In-memory clauses cache.
	 *
	 * @var array
	 */
	private static $clause_cache = array();

	/**
	 * Normalize a search query string.
	 *
	 * @param string $query Raw query string.
	 * @return string Normalized query.
	 */
	public static function normalize_query( $query ) {
		$query = (string) $query;

		// Convert typographic smart quotes to standard ASCII quotes
		$quote_map = array(
			'“' => '"',
			'”' => '"',
			'„' => '"',
			'‟' => '"',
			'«' => '"',
			'»' => '"',
			'‘' => "'",
			'’' => "'",
			'‚' => "'",
			'‛' => "'",
		);
		$query = strtr( $query, $quote_map );

		// Strip control characters / non-printable chars (except whitespace)
		$query = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $query );

		// Normalize multiple whitespace characters, tabs, newlines to single space
		$query = preg_replace( '/\s+/u', ' ', $query );

		return trim( $query );
	}

	/**
	 * Retrieve active stop words list.
	 *
	 * @param string $custom_stop_words Optional comma-separated custom stop words.
	 * @return array
	 */
	public static function get_stop_words( $custom_stop_words = '' ) {
		$stop_words = self::DEFAULT_STOP_WORDS;

		if ( '' !== trim( $custom_stop_words ) ) {
			$custom = array_filter( array_map( 'trim', explode( ',', $custom_stop_words ) ) );
			if ( ! empty( $custom ) ) {
				$stop_words = array_unique( array_merge( $stop_words, array_map( array( __CLASS__, 'str_lower' ), $custom ) ) );
			}
		}

		return (array) apply_filters( 'omninox_search_stop_words', $stop_words );
	}

	/**
	 * Parse a raw search query into terms, phrases, and exclusions with normalization and URL/slug awareness.
	 *
	 * @param string $query    Raw query string.
	 * @param array  $settings Optional settings array.
	 * @return array
	 */
	public static function parse_query( $query, array $settings = array() ) {
		$cache_key = md5( (string) $query . serialize( $settings ) );
		if ( isset( self::$query_cache[ $cache_key ] ) ) {
			return self::$query_cache[ $cache_key ];
		}

		$normalize = ! isset( $settings['normalize_queries'] ) || ! empty( $settings['normalize_queries'] );
		$ignore_stop_words = ! isset( $settings['ignore_stop_words'] ) || ! empty( $settings['ignore_stop_words'] );
		$custom_stop_words = (string) ( $settings['custom_stop_words'] ?? '' );

		if ( $normalize ) {
			$query = self::normalize_query( $query );
		} else {
			$query = trim( (string) $query );
		}

		if ( '' === $query ) {
			$empty = array(
				'raw'         => '',
				'exact'       => '',
				'phrases'     => array(),
				'slugs'       => array(),
				'words'       => array(),
				'exclusions'  => array(),
				'all_terms'   => array(),
				'matched_id'  => 0,
			);
			self::$query_cache[ $cache_key ] = $empty;
			return $empty;
		}

		$exact = $query;
		$phrases = array();
		$slugs = array();
		$exclusions = array();
		$words = array();
		$matched_id = 0;

		// URL detection and path/slug extraction
		if ( preg_match( '#^https?://#i', $query ) || false !== strpos( $query, '/' ) ) {
			if ( function_exists( 'url_to_postid' ) ) {
				$matched_id = (int) url_to_postid( $query );
			}

			$path = trim( (string) parse_url( $query, PHP_URL_PATH ), '/' );
			if ( '' !== $path ) {
				$slug_segments = explode( '/', $path );
				foreach ( $slug_segments as $seg ) {
					$seg = trim( $seg );
					if ( '' !== $seg ) {
						$slugs[] = $seg;
						// Convert slug to readable words (e.g. career-advice -> career advice)
						$unhyphenated = trim( str_replace( array( '-', '_' ), ' ', $seg ) );
						if ( '' !== $unhyphenated && $unhyphenated !== $seg ) {
							$phrases[] = $unhyphenated;
						}
						// Sub-words in slug (e.g. career, advice)
						$sub_words = preg_split( '/[-_]+/', $seg, -1, PREG_SPLIT_NO_EMPTY );
						foreach ( $sub_words as $sw ) {
							$words[] = $sw;
						}
					}
				}
			}
		}

		// Extract quoted exact phrases, e.g. "search term"
		if ( preg_match_all( '/"([^"]+)"/', $query, $matches ) ) {
			foreach ( $matches[1] as $phrase ) {
				$phrase = trim( $phrase );
				if ( '' !== $phrase ) {
					$phrases[] = $phrase;
				}
			}
			$query = preg_replace( '/"([^"]+)"/', ' ', $query );
		}

		// Split remaining tokens
		$tokens = preg_split( '/[\s,]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
		$stop_words = $ignore_stop_words ? self::get_stop_words( $custom_stop_words ) : array();

		foreach ( $tokens as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}

			// Negated term (e.g. -exclude)
			if ( 0 === strpos( $token, '-' ) && strlen( $token ) > 1 ) {
				$exclusions[] = substr( $token, 1 );
				continue;
			}

			// Strip leading '+' if present
			if ( 0 === strpos( $token, '+' ) ) {
				$token = substr( $token, 1 );
			}

			if ( '' !== $token ) {
				$words[] = $token;
			}
		}

		// Combine unique non-stop words with phrases and slugs for all_terms
		$all_terms = array();
		foreach ( array_merge( $phrases, $slugs, $words ) as $t ) {
			$lower = self::str_lower( $t );
			if ( ! $ignore_stop_words || ! in_array( $lower, $stop_words, true ) || count( $words ) === 1 ) {
				$all_terms[ $lower ] = $t;
			}
		}

		// If all words were stop words, keep original words
		if ( empty( $all_terms ) && ! empty( $words ) ) {
			foreach ( $words as $w ) {
				$all_terms[ self::str_lower( $w ) ] = $w;
			}
		}

		$parsed = array(
			'raw'        => $exact,
			'exact'      => $exact,
			'phrases'    => array_values( array_unique( $phrases ) ),
			'slugs'      => array_values( array_unique( $slugs ) ),
			'words'      => array_values( array_unique( $words ) ),
			'exclusions' => array_values( array_unique( $exclusions ) ),
			'all_terms'  => array_values( $all_terms ),
			'matched_id' => $matched_id,
		);

		self::$query_cache[ $cache_key ] = $parsed;
		return $parsed;
	}

	/**
	 * Safe lowercase helper with mbstring fallback.
	 *
	 * @param string $str Input string.
	 * @return string
	 */
	public static function str_lower( $str ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $str, 'UTF-8' );
		}
		return strtolower( $str );
	}

	/**
	 * Safe string length helper with mbstring fallback.
	 *
	 * @param string $str Input string.
	 * @return int
	 */
	public static function str_len( $str ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $str, 'UTF-8' );
		}
		return strlen( $str );
	}

	/**
	 * Build relevance scoring SQL expression and WHERE conditions.
	 *
	 * @param string $search_query Search query string.
	 * @param array  $settings     Active search settings.
	 * @param string $override_mode Optional matching mode override ('smart_fallback', 'all', 'any', 'exact').
	 * @return array
	 */
	public static function build_clauses( $search_query, array $settings = array(), $override_mode = '' ) {
		global $wpdb;

		$clause_key = md5( (string) $search_query . serialize( $settings ) . (string) $override_mode );
		if ( isset( self::$clause_cache[ $clause_key ] ) ) {
			return self::$clause_cache[ $clause_key ];
		}

		$parsed = self::parse_query( $search_query, $settings );
		if ( empty( $parsed['all_terms'] ) && '' === $parsed['raw'] && empty( $parsed['matched_id'] ) ) {
			return array();
		}

		$config = wp_parse_args(
			$settings,
			array(
				'search_title'       => true,
				'search_content'     => true,
				'search_excerpt'     => true,
				'search_terms'       => true,
				'search_meta'        => false,
				'meta_keys'          => '',
				'weight_title'       => 50,
				'weight_content'     => 10,
				'weight_excerpt'     => 20,
				'weight_terms'       => 25,
				'weight_meta'        => 15,
				'exact_match_bonus'  => 100,
				'matching_mode'      => 'smart_fallback', // 'smart_fallback', 'all', 'any', 'exact'
				'prioritize_phrases' => true,
				'partial_matching'   => true,
				'fuzzy_matching'     => false,
				'prevent_duplicates' => true,
				'excluded_post_ids'  => '',
				'excluded_term_ids'  => '',
			)
		);

		if ( '' !== $override_mode ) {
			$config['matching_mode'] = $override_mode;
		}

		/**
		 * Filter search weights.
		 *
		 * @param array $weights Weight settings.
		 */
		$weights = apply_filters(
			'omninox_search_weights',
			array(
				'title'       => max( 1, (int) $config['weight_title'] ),
				'content'     => max( 1, (int) $config['weight_content'] ),
				'excerpt'     => max( 1, (int) $config['weight_excerpt'] ),
				'terms'       => max( 1, (int) ( $config['weight_terms'] ?? 25 ) ),
				'meta'        => max( 1, (int) $config['weight_meta'] ),
				'exact_bonus' => max( 0, (int) $config['exact_match_bonus'] ),
			)
		);

		$score_parts = array();
		$title_score_parts = array();
		$content_score_parts = array();
		$excerpt_score_parts = array();
		$terms_score_parts = array();
		$meta_score_parts = array();

		$where_conditions = array();
		$join_clauses = array();

		$raw_query   = $parsed['raw'];
		$all_terms   = $parsed['all_terms'];
		$phrases     = $parsed['phrases'];
		$slugs       = $parsed['slugs'];
		$exclusions  = $parsed['exclusions'];
		$matched_id  = (int) ( $parsed['matched_id'] ?? 0 );

		$prioritize_phrases = ! empty( $config['prioritize_phrases'] );

		// -------------------------------------------------------------
		// 0. Direct Post ID match (from exact URL resolution)
		// -------------------------------------------------------------
		if ( $matched_id > 0 ) {
			$p = $wpdb->prepare(
				"(CASE WHEN {$wpdb->posts}.ID = %d THEN %d ELSE 0 END)",
				$matched_id,
				$weights['title'] * 10 + $weights['exact_bonus'] * 3
			);
			$score_parts[] = $p;
			$title_score_parts[] = $p;
		}

		// -------------------------------------------------------------
		// 1. Title Scoring (Guaranteed Highest Ranking Tier)
		// -------------------------------------------------------------
		if ( ! empty( $config['search_title'] ) ) {
			// Exact full title match ranks absolute highest
			$p = $wpdb->prepare(
				"(CASE WHEN {$wpdb->posts}.post_title = %s THEN %d ELSE 0 END)",
				$raw_query,
				$weights['title'] * 5 + $weights['exact_bonus'] * 2
			);
			$score_parts[] = $p;
			$title_score_parts[] = $p;

			// Title starts with exact query
			$p = $wpdb->prepare(
				"(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)",
				$wpdb->esc_like( $raw_query ) . '%',
				$weights['title'] * 3 + $weights['exact_bonus']
			);
			$score_parts[] = $p;
			$title_score_parts[] = $p;

			// Title contains exact contiguous phrase
			if ( $prioritize_phrases ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $raw_query ) . '%',
					$weights['title'] * 2 + (int) ( $weights['exact_bonus'] * 0.5 )
				);
				$score_parts[] = $p;
				$title_score_parts[] = $p;
			}

			// Specific quoted / unhyphenated phrases in title
			foreach ( $phrases as $phrase ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $phrase ) . '%',
					$weights['title'] * 2 + $weights['exact_bonus']
				);
				$score_parts[] = $p;
				$title_score_parts[] = $p;
			}

			// Individual token matches in title
			foreach ( $all_terms as $term ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $term ) . '%',
					$weights['title']
				);
				$score_parts[] = $p;
				$title_score_parts[] = $p;
			}
		}

		// -------------------------------------------------------------
		// 1b. Slug (post_name) & GUID / URL Scoring
		// -------------------------------------------------------------
		if ( ! empty( $slugs ) ) {
			foreach ( $slugs as $slug ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_name = %s THEN %d ELSE 0 END)",
					$slug,
					$weights['title'] * 4 + $weights['exact_bonus']
				);
				$score_parts[] = $p;
				$title_score_parts[] = $p;

				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_name LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $slug ) . '%',
					$weights['title'] * 2
				);
				$score_parts[] = $p;
				$title_score_parts[] = $p;
			}
		}

		// GUID URL match
		$p = $wpdb->prepare(
			"(CASE WHEN {$wpdb->posts}.guid LIKE %s THEN %d ELSE 0 END)",
			'%' . $wpdb->esc_like( $raw_query ) . '%',
			$weights['title'] * 2 + $weights['exact_bonus']
		);
		$score_parts[] = $p;
		$title_score_parts[] = $p;

		// -------------------------------------------------------------
		// 2. Excerpt Scoring
		// -------------------------------------------------------------
		if ( ! empty( $config['search_excerpt'] ) ) {
			// Excerpt contains exact phrase
			if ( $prioritize_phrases ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_excerpt LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $raw_query ) . '%',
					$weights['excerpt'] * 2 + (int) ( $weights['exact_bonus'] * 0.25 )
				);
				$score_parts[] = $p;
				$excerpt_score_parts[] = $p;
			}

			// Individual terms in excerpt
			foreach ( $all_terms as $term ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_excerpt LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $term ) . '%',
					$weights['excerpt']
				);
				$score_parts[] = $p;
				$excerpt_score_parts[] = $p;
			}
		}

		// -------------------------------------------------------------
		// 3. Content Scoring
		// -------------------------------------------------------------
		if ( ! empty( $config['search_content'] ) ) {
			// Content contains exact phrase
			if ( $prioritize_phrases ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_content LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $raw_query ) . '%',
					$weights['content'] * 3 + $weights['exact_bonus']
				);
				$score_parts[] = $p;
				$content_score_parts[] = $p;
			}

			// Specific quoted phrases in content
			foreach ( $phrases as $phrase ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_content LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $phrase ) . '%',
					$weights['content'] * 2 + (int) ( $weights['exact_bonus'] * 0.5 )
				);
				$score_parts[] = $p;
				$content_score_parts[] = $p;
			}

			// Individual terms in content
			foreach ( $all_terms as $term ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$wpdb->posts}.post_content LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $term ) . '%',
					$weights['content']
				);
				$score_parts[] = $p;
				$content_score_parts[] = $p;
			}
		}

		// -------------------------------------------------------------
		// 4. Taxonomies / Categories / Tags Scoring & JOIN
		// -------------------------------------------------------------
		$has_term_search = ! empty( $config['search_terms'] );
		if ( $has_term_search ) {
			$join_clauses[] = "LEFT JOIN {$wpdb->term_relationships} AS omninox_tr ON ({$wpdb->posts}.ID = omninox_tr.object_id)";
			$join_clauses[] = "LEFT JOIN {$wpdb->term_taxonomy} AS omninox_tt ON (omninox_tr.term_taxonomy_id = omninox_tt.term_taxonomy_id)";
			$join_clauses[] = "LEFT JOIN {$wpdb->terms} AS omninox_t ON (omninox_tt.term_id = omninox_t.term_id)";

			// Exact category/term name match
			$p = $wpdb->prepare(
				"(CASE WHEN omninox_t.name = %s THEN %d ELSE 0 END)",
				$raw_query,
				$weights['terms'] * 3 + $weights['exact_bonus']
			);
			$score_parts[] = $p;
			$terms_score_parts[] = $p;

			// Term contains query phrase
			$p = $wpdb->prepare(
				"(CASE WHEN omninox_t.name LIKE %s THEN %d ELSE 0 END)",
				'%' . $wpdb->esc_like( $raw_query ) . '%',
				(int) ( $weights['terms'] * 1.5 )
			);
			$score_parts[] = $p;
			$terms_score_parts[] = $p;

			// Individual terms in category/tag names
			foreach ( $all_terms as $term ) {
				$p = $wpdb->prepare(
					"(CASE WHEN omninox_t.name LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $term ) . '%',
					$weights['terms']
				);
				$score_parts[] = $p;
				$terms_score_parts[] = $p;
			}
		}

		// -------------------------------------------------------------
		// 5. Custom Fields (Post Meta) Scoring & JOIN
		// -------------------------------------------------------------
		$has_meta_search = ! empty( $config['search_meta'] );
		if ( $has_meta_search ) {
			$meta_alias = 'omninox_pm';
			$join_clauses[] = "LEFT JOIN {$wpdb->postmeta} AS {$meta_alias} ON ({$wpdb->posts}.ID = {$meta_alias}.post_id)";

			// Meta contains exact phrase
			$p = $wpdb->prepare(
				"(CASE WHEN {$meta_alias}.meta_value LIKE %s THEN %d ELSE 0 END)",
				'%' . $wpdb->esc_like( $raw_query ) . '%',
				$weights['meta'] * 2
			);
			$score_parts[] = $p;
			$meta_score_parts[] = $p;

			// Individual terms in meta
			foreach ( $all_terms as $term ) {
				$p = $wpdb->prepare(
					"(CASE WHEN {$meta_alias}.meta_value LIKE %s THEN %d ELSE 0 END)",
					'%' . $wpdb->esc_like( $term ) . '%',
					$weights['meta']
				);
				$score_parts[] = $p;
				$meta_score_parts[] = $p;
			}
		}

		// -------------------------------------------------------------
		// 6. WHERE matching clause
		// -------------------------------------------------------------
		$matching_mode = $config['matching_mode']; // 'smart_fallback', 'all', 'any', 'exact'

		// Helper to collect all searchable columns for a given term
		$get_column_matches = function( $search_val ) use ( $wpdb, $config, $has_term_search, $has_meta_search ) {
			$matches = array();
			$like = '%' . $wpdb->esc_like( $search_val ) . '%';

			if ( ! empty( $config['search_title'] ) ) {
				$matches[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s)", $like );
				$matches[] = $wpdb->prepare( "({$wpdb->posts}.post_name LIKE %s)", $like );
				$matches[] = $wpdb->prepare( "({$wpdb->posts}.guid LIKE %s)", $like );
			}
			if ( ! empty( $config['search_excerpt'] ) ) {
				$matches[] = $wpdb->prepare( "({$wpdb->posts}.post_excerpt LIKE %s)", $like );
			}
			if ( ! empty( $config['search_content'] ) ) {
				$matches[] = $wpdb->prepare( "({$wpdb->posts}.post_content LIKE %s)", $like );
			}
			if ( $has_term_search ) {
				$matches[] = $wpdb->prepare( "(omninox_t.name LIKE %s)", $like );
			}
			if ( $has_meta_search ) {
				$matches[] = $wpdb->prepare( "(omninox_pm.meta_value LIKE %s)", $like );
			}

			return $matches;
		};

		// If an exact Post ID was matched from the URL
		if ( $matched_id > 0 ) {
			$where_conditions[] = "({$wpdb->posts}.ID = {$matched_id})";
		} elseif ( 'exact' === $matching_mode ) {
			$exact_matches = $get_column_matches( $raw_query );
			if ( ! empty( $slugs ) ) {
				foreach ( $slugs as $slug ) {
					$exact_matches = array_merge( $exact_matches, $get_column_matches( $slug ) );
				}
			}

			if ( ! empty( $exact_matches ) ) {
				$where_conditions[] = '(' . implode( ' OR ', $exact_matches ) . ')';
			}
		} elseif ( 'all' === $matching_mode || 'smart_fallback' === $matching_mode ) {
			// If searching a URL, also include slug/phrases as an OR fallback match
			if ( ! empty( $slugs ) ) {
				$slug_matches = array();
				foreach ( $slugs as $slug ) {
					$slug_matches = array_merge( $slug_matches, $get_column_matches( $slug ) );
				}
				foreach ( $phrases as $phrase ) {
					$slug_matches = array_merge( $slug_matches, $get_column_matches( $phrase ) );
				}
				if ( ! empty( $slug_matches ) ) {
					$where_conditions[] = '(' . implode( ' OR ', $slug_matches ) . ')';
				}
			} else {
				// Every term must match at least one column (AND logic across terms)
				foreach ( $all_terms as $term ) {
					$term_matches = $get_column_matches( $term );
					if ( ! empty( $term_matches ) ) {
						$where_conditions[] = '(' . implode( ' OR ', $term_matches ) . ')';
					}
				}
			}
		} else {
			// 'any' matching mode: at least one term in any column (OR logic)
			$all_matches = array();
			foreach ( $all_terms as $term ) {
				$all_matches = array_merge( $all_matches, $get_column_matches( $term ) );
			}

			if ( ! empty( $all_matches ) ) {
				$where_conditions[] = '(' . implode( ' OR ', $all_matches ) . ')';
			}
		}

		// -------------------------------------------------------------
		// 7. Exclusions (Negated terms like -keyword)
		// -------------------------------------------------------------
		foreach ( $exclusions as $ex ) {
			$ex_conditions = array();
			if ( ! empty( $config['search_title'] ) ) {
				$ex_conditions[] = $wpdb->prepare( "{$wpdb->posts}.post_title NOT LIKE %s", '%' . $wpdb->esc_like( $ex ) . '%' );
			}
			if ( ! empty( $config['search_excerpt'] ) ) {
				$ex_conditions[] = $wpdb->prepare( "{$wpdb->posts}.post_excerpt NOT LIKE %s", '%' . $wpdb->esc_like( $ex ) . '%' );
			}
			if ( ! empty( $config['search_content'] ) ) {
				$ex_conditions[] = $wpdb->prepare( "{$wpdb->posts}.post_content NOT LIKE %s", '%' . $wpdb->esc_like( $ex ) . '%' );
			}

			if ( ! empty( $ex_conditions ) ) {
				$where_conditions[] = '(' . implode( ' AND ', $ex_conditions ) . ')';
			}
		}

		// Excluded post IDs
		if ( ! empty( $config['excluded_post_ids'] ) ) {
			$excluded_ids = array_filter( array_map( 'intval', explode( ',', $config['excluded_post_ids'] ) ) );
			if ( ! empty( $excluded_ids ) ) {
				$where_conditions[] = "{$wpdb->posts}.ID NOT IN (" . implode( ',', $excluded_ids ) . ')';
			}
		}

		// Excluded Term / Category IDs
		if ( ! empty( $config['excluded_term_ids'] ) ) {
			$excluded_terms = array_filter( array_map( 'intval', explode( ',', $config['excluded_term_ids'] ) ) );
			if ( ! empty( $excluded_terms ) ) {
				$term_list = implode( ',', $excluded_terms );
				$where_conditions[] = "{$wpdb->posts}.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$term_list}))";
			}
		}

		// Assemble relevance score formula
		$relevance_formula = ! empty( $score_parts ) ? implode( ' + ', $score_parts ) : '0';

		$results = array(
			'score_formula'        => $relevance_formula,
			'title_score_formula'  => ! empty( $title_score_parts ) ? implode( ' + ', $title_score_parts ) : '0',
			'content_score_formula'=> ! empty( $content_score_parts ) ? implode( ' + ', $content_score_parts ) : '0',
			'excerpt_score_formula'=> ! empty( $excerpt_score_parts ) ? implode( ' + ', $excerpt_score_parts ) : '0',
			'terms_score_formula'  => ! empty( $terms_score_parts ) ? implode( ' + ', $terms_score_parts ) : '0',
			'meta_score_formula'   => ! empty( $meta_score_parts ) ? implode( ' + ', $meta_score_parts ) : '0',
			'where_conditions'     => $where_conditions,
			'join_clauses'         => $join_clauses,
			'parsed'               => $parsed,
			'matching_mode'        => $matching_mode,
			'distinct'             => $has_meta_search || $has_term_search || ! empty( $config['prevent_duplicates'] ),
		);

		self::$clause_cache[ $clause_key ] = $results;
		return $results;
	}
}
