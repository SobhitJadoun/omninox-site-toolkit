<?php
/**
 * Module: Search Optimization & Relevance Engine.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Search;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Services\ValueHistory;

defined( 'ABSPATH' ) || exit;

class SearchModule extends AbstractModule {

	protected $id   = 'search';
	protected $icon = 'search';

	public function get_label() {
		return __( 'Search Optimization', 'omninox-site-toolkit' );
	}

	/**
	 * Register search subsystems.
	 */
	public function register() {
		SearchHandler::instance()->boot();
		Highlighter::instance()->boot();
		LiveSearch::instance()->boot();
	}

	/**
	 * Toggles and actions exposed to the admin UI.
	 *
	 * @return array
	 */
	public function get_actions() {
		$actions = array();

		// Database Search & Replace
		$db_tables     = SearchReplacer::get_tables_with_sizes();
		$table_options = array();
		foreach ( $db_tables as $tbl ) {
			$table_options[] = array(
				'value' => $tbl['name'],
				'label' => $tbl['label'],
			);
		}

		$actions[] = array(
			'id'     => 'search_replace',
			'label'  => __( 'Database Search & Replace', 'omninox-site-toolkit'),
			'type'   => 'form',
			'submit' => __( 'Run Search & Replace', 'omninox-site-toolkit'),
			'fields' => array(
				array(
					'id'          => 'search',
					'label'       => __( 'Search for', 'omninox-site-toolkit'),
					'placeholder' => __( 'String, domain, URL, or text to find…', 'omninox-site-toolkit'),
				),
				array(
					'id'          => 'replace',
					'label'       => __( 'Replace with', 'omninox-site-toolkit'),
					'placeholder' => __( 'Replacement text or new URL…', 'omninox-site-toolkit'),
				),
				array(
					'id'      => 'tables',
					'label'   => __( 'Select tables', 'omninox-site-toolkit'),
					'type'    => 'multiselect',
					'help'    => __( 'Select multiple tables with Ctrl-Click for Windows or Cmd-Click for Mac.', 'omninox-site-toolkit'),
					'options' => $table_options,
				),
				array(
					'id'      => 'mode',
					'label'   => __( 'Execution mode', 'omninox-site-toolkit'),
					'type'    => 'select',
					'options' => array(
						array( 'value' => 'dry_run', 'label' => __( 'Dry Run (Preview changes only - safe)', 'omninox-site-toolkit') ),
						array( 'value' => 'live_run', 'label' => __( 'Live Run (Update database)', 'omninox-site-toolkit') ),
					),
				),
				array(
					'id'      => 'case_sensitive',
					'label'   => __( 'Case sensitivity', 'omninox-site-toolkit'),
					'type'    => 'select',
					'options' => array(
						array( 'value' => 'no', 'label' => __( 'Case-insensitive (default)', 'omninox-site-toolkit') ),
						array( 'value' => 'yes', 'label' => __( 'Case-sensitive', 'omninox-site-toolkit') ),
					),
				),
			),
		);

		return $actions;
	}

	/**
	 * Run diagnostic search health scan.
	 *
	 * @param array $args Scan arguments.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$settings = (array) Settings::instance()->get( 'search', array() );

		// 1. Enhanced Search Status
		$is_enabled = ! empty( $settings['enabled'] );
		$this->assert(
			'search_enabled_status',
			__( 'Enhanced Search Engine', 'omninox-site-toolkit'),
			$is_enabled,
			array(
				'value'       => $is_enabled ? __( 'Enabled', 'omninox-site-toolkit') : __( 'Disabled (using basic WordPress search)', 'omninox-site-toolkit'),
				'expected'    => __( 'Enabled', 'omninox-site-toolkit'),
				'severity'    => $is_enabled ? self::SEV_PASS : self::SEV_WARNING,
				'weight'      => 4,
				'description' => __( 'Relevance scoring, exact phrase weighting, and smart fallback dramatically improve search accuracy.', 'omninox-site-toolkit'),
				'fix'         => $is_enabled ? '' : 'toggle_enabled',
				'fix_params'  => array( 'value' => 1 ),
			)
		);

		// 2. Searchable Content Scope
		$post_counts = wp_count_posts( 'post' );
		$page_counts = wp_count_posts( 'page' );
		$published_posts = (int) ( $post_counts->publish ?? 0 );
		$published_pages = (int) ( $page_counts->publish ?? 0 );
		$total_searchable = $published_posts + $published_pages;

		$this->add(
			array(
				'id'          => 'searchable_inventory',
				'label'       => __( 'Searchable public posts & pages', 'omninox-site-toolkit'),
				'value'       => sprintf( __( '%d published items', 'omninox-site-toolkit'), $total_searchable ),
				'severity'    => self::SEV_PASS,
				'weight'      => 2,
				'description' => sprintf(
					/* translators: 1: post count, 2: page count */
					__( '%1$d posts and %2$d pages are indexed and searchable.', 'omninox-site-toolkit'),
					$published_posts,
					$published_pages
				),
			)
		);

		// 3. Posts Without Excerpt
		global $wpdb;
		$missing_excerpts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' AND (post_excerpt = '' OR post_excerpt IS NULL)"
			)
		);

		$smart_excerpt = ! empty( $settings['smart_excerpts'] );
		$excerpt_severity = $missing_excerpts > 0 ? ( $smart_excerpt ? self::SEV_PASS : self::SEV_NOTICE ) : self::SEV_PASS;

		$this->add(
			array(
				'id'          => 'missing_excerpts',
				'label'       => __( 'Posts without explicit excerpts', 'omninox-site-toolkit'),
				'value'       => $missing_excerpts,
				'expected'    => $smart_excerpt ? __( 'Smart excerpts active', 'omninox-site-toolkit') : '0',
				'severity'    => $excerpt_severity,
				'weight'      => 2,
				'description' => $smart_excerpt
					? __( 'Smart contextual excerpts are enabled to automatically generate keyword-focused summaries for these posts.', 'omninox-site-toolkit')
					: __( 'Consider enabling Smart Excerpts to provide rich keyword previews on search results.', 'omninox-site-toolkit'),
				'fix'         => $smart_excerpt ? '' : 'toggle_smart_excerpts',
				'fix_params'  => array( 'value' => 1 ),
			)
		);

		// 4. Search Query Speed Benchmark
		$start_time = microtime( true );
		$benchmark_query = new \WP_Query(
			array(
				's'              => 'the',
				'posts_per_page' => 10,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			)
		);
		$query_duration_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		$this->add(
			array(
				'id'          => 'search_latency',
				'label'       => __( 'Search query execution time', 'omninox-site-toolkit'),
				'value'       => $query_duration_ms . ' ms',
				'expected'    => '< 150 ms',
				'severity'    => $query_duration_ms < 150 ? self::SEV_PASS : ( $query_duration_ms < 400 ? self::SEV_WARNING : self::SEV_CRITICAL ),
				'weight'      => 3,
				'description' => __( 'Fast search query response times ensure smooth visitor and live search experience.', 'omninox-site-toolkit'),
			)
		);

		// 5. Keyword Highlighting Status
		$highlight_active = ! empty( $settings['highlight_terms'] );
		$this->assert(
			'highlighting_status',
			__( 'Keyword Highlighting in Results', 'omninox-site-toolkit'),
			$highlight_active,
			array(
				'value'       => $highlight_active ? __( 'Active', 'omninox-site-toolkit') : __( 'Disabled', 'omninox-site-toolkit'),
				'expected'    => __( 'Active', 'omninox-site-toolkit'),
				'severity'    => $highlight_active ? self::SEV_PASS : self::SEV_NOTICE,
				'weight'      => 1,
				'description' => __( 'Highlighting keywords helps visitors quickly spot relevant information.', 'omninox-site-toolkit'),
				'fix'         => $highlight_active ? '' : 'toggle_highlight_terms',
				'fix_params'  => array( 'value' => 1 ),
			)
		);

		// 6. Search & Replace Engine
		$this->add(
			array(
				'id'          => 'search_replace_engine',
				'label'       => __( 'Database Search & Replace Engine', 'omninox-site-toolkit'),
				'value'       => __( 'Ready (Serialized & JSON safe)', 'omninox-site-toolkit'),
				'severity'    => self::SEV_PASS,
				'weight'      => 1,
				'description' => __( 'Safely find and replace text, domains, and URLs across database tables with automated serialized array/object repair.', 'omninox-site-toolkit'),
			)
		);

		// Additional charts
		$charts = array(
			array(
				'title' => __( 'Searchable Items by Type', 'omninox-site-toolkit'),
				'data'  => array(
					array( 'label' => __( 'Posts', 'omninox-site-toolkit'), 'value' => $published_posts ),
					array( 'label' => __( 'Pages', 'omninox-site-toolkit'), 'value' => $published_pages ),
				),
			),
		);

		return $this->result( array( 'charts' => $charts ) );
	}

	/**
	 * Handle admin actions.
	 *
	 * @param string $action Action name.
	 * @param array  $params Parameters.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		// Handle toggles
		if ( 0 === strpos( $action, 'toggle_' ) ) {
			$key   = substr( $action, 7 );
			$value = ! empty( $params['value'] );

			$search_settings = (array) Settings::instance()->get( 'search', array() );
			$search_settings[ $key ] = $value;

			ValueHistory::record( 'search_' . $key, ! empty( $search_settings[ $key ] ) ? 0 : 1, $value ? 1 : 0, $key );
			Settings::instance()->update( array( 'search' => $search_settings ) );

			return array(
				'message' => $value
					? sprintf( __( 'Enabled "%s".', 'omninox-site-toolkit'), $key )
					: sprintf( __( 'Disabled "%s".', 'omninox-site-toolkit'), $key ),
			);
		}

		// Handle matching mode
		if ( 'set_matching_mode' === $action ) {
			$mode = sanitize_key( $params['value'] ?? 'smart_fallback' );
			if ( ! in_array( $mode, array( 'smart_fallback', 'all', 'any', 'exact' ), true ) ) {
				$mode = 'smart_fallback';
			}

			$search_settings = (array) Settings::instance()->get( 'search', array() );
			$search_settings['matching_mode'] = $mode;

			Settings::instance()->update( array( 'search' => $search_settings ) );

			return array( 'message' => sprintf( __( 'Matching mode updated to "%s".', 'omninox-site-toolkit'), $mode ) );
		}

		// Handle weights configuration form
		if ( 'configure_weights' === $action ) {
			$search_settings = (array) Settings::instance()->get( 'search', array() );

			if ( isset( $params['weight_title'] ) && '' !== trim( (string) $params['weight_title'] ) ) {
				$search_settings['weight_title'] = max( 1, min( 500, (int) $params['weight_title'] ) );
			}
			if ( isset( $params['weight_content'] ) && '' !== trim( (string) $params['weight_content'] ) ) {
				$search_settings['weight_content'] = max( 1, min( 500, (int) $params['weight_content'] ) );
			}
			if ( isset( $params['weight_excerpt'] ) && '' !== trim( (string) $params['weight_excerpt'] ) ) {
				$search_settings['weight_excerpt'] = max( 1, min( 500, (int) $params['weight_excerpt'] ) );
			}
			if ( isset( $params['weight_terms'] ) && '' !== trim( (string) $params['weight_terms'] ) ) {
				$search_settings['weight_terms'] = max( 1, min( 500, (int) $params['weight_terms'] ) );
			}
			if ( isset( $params['weight_meta'] ) && '' !== trim( (string) $params['weight_meta'] ) ) {
				$search_settings['weight_meta'] = max( 1, min( 500, (int) $params['weight_meta'] ) );
			}
			if ( isset( $params['exact_match_bonus'] ) && '' !== trim( (string) $params['exact_match_bonus'] ) ) {
				$search_settings['exact_match_bonus'] = max( 0, min( 1000, (int) $params['exact_match_bonus'] ) );
			}

			Settings::instance()->update( array( 'search' => $search_settings ) );

			$rows = array(
				array(
					'Title Weight'      => $search_settings['weight_title'],
					'Content Weight'    => $search_settings['weight_content'],
					'Excerpt Weight'    => $search_settings['weight_excerpt'],
					'Taxonomy Weight'   => $search_settings['weight_terms'],
					'Meta Weight'       => $search_settings['weight_meta'],
					'Exact Match Bonus' => $search_settings['exact_match_bonus'],
				),
			);

			return array(
				'module'  => 'search',
				'message' => __( 'Relevance weights updated successfully.', 'omninox-site-toolkit'),
				'rows'    => $rows,
			);
		}

		// Handle limits & exclusions configuration form
		if ( 'configure_limits' === $action ) {
			$search_settings = (array) Settings::instance()->get( 'search', array() );

			if ( isset( $params['posts_per_page'] ) && '' !== trim( (string) $params['posts_per_page'] ) ) {
				$search_settings['posts_per_page'] = max( 0, min( 100, (int) $params['posts_per_page'] ) );
			}
			if ( isset( $params['excluded_post_types'] ) ) {
				$search_settings['excluded_post_types'] = array_filter( array_map( 'sanitize_key', explode( ',', (string) $params['excluded_post_types'] ) ) );
			}
			if ( isset( $params['excluded_post_ids'] ) ) {
				$search_settings['excluded_post_ids'] = sanitize_text_field( $params['excluded_post_ids'] );
			}
			if ( isset( $params['excluded_term_ids'] ) ) {
				$search_settings['excluded_term_ids'] = sanitize_text_field( $params['excluded_term_ids'] );
			}
			if ( isset( $params['meta_keys'] ) ) {
				$search_settings['meta_keys'] = sanitize_text_field( $params['meta_keys'] );
			}
			if ( isset( $params['custom_stop_words'] ) ) {
				$search_settings['custom_stop_words'] = sanitize_text_field( $params['custom_stop_words'] );
			}

			Settings::instance()->update( array( 'search' => $search_settings ) );

			$rows = array(
				array(
					'Max Results'         => $search_settings['posts_per_page'] ?: __( 'Theme Default', 'omninox-site-toolkit'),
					'Excluded Post Types' => implode( ', ', (array) $search_settings['excluded_post_types'] ),
					'Excluded Post IDs'   => $search_settings['excluded_post_ids'] ?: __( 'None', 'omninox-site-toolkit'),
					'Excluded Term IDs'   => $search_settings['excluded_term_ids'] ?: __( 'None', 'omninox-site-toolkit'),
					'Custom Meta Keys'    => $search_settings['meta_keys'] ?: __( 'All Meta Keys', 'omninox-site-toolkit'),
					'Custom Stop Words'   => $search_settings['custom_stop_words'] ?: __( 'Default English', 'omninox-site-toolkit'),
				),
			);

			return array(
				'module'  => 'search',
				'message' => __( 'Search limits, stop words, and exclusion rules saved.', 'omninox-site-toolkit'),
				'rows'    => $rows,
			);
		}

		// Handle live test search
		if ( 'test_search' === $action ) {
			$query = sanitize_text_field( $params['query'] ?? '' );
			if ( '' === $query ) {
				return new \WP_Error( 'omninox_missing_query', __( 'Please enter a search query to test.', 'omninox-site-toolkit') );
			}

			$start = microtime( true );
			$items = LiveSearch::query( $query, 15 );
			$time_ms = round( ( microtime( true ) - $start ) * 1000, 2 );

			$rows = array();
			foreach ( $items as $item ) {
				$rows[] = array(
					'Score'        => $item['score'],
					'Title Score'  => $item['title_score'],
					'Content Score'=> $item['content_score'],
					'Tax Score'    => $item['terms_score'],
					'Meta Score'   => $item['meta_score'],
					'Title'        => $item['title'],
					'Type'         => $item['type'],
					'Category'     => $item['category'] ?: '—',
					'Date'         => $item['date'],
					'Snippet'      => $item['excerpt'],
				);
			}

			return array(
				'module'  => 'search',
				'message' => sprintf(
					/* translators: 1: count, 2: query, 3: time */
					__( 'Found %1$d results for "%2$s" in %3$sms with full relevance breakdown.', 'omninox-site-toolkit'),
					count( $rows ),
					$query,
					$time_ms
				),
				'rows'    => $rows,
			);
		}

		// Handle database search & replace
		if ( in_array( $action, array( 'search_replace_matches', 'search_replace', 'revert_search_replace' ), true ) ) {
			if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'database', 'search_replace' ) ) {
				return new \WP_Error(
					'omninox_pro_required',
					__( 'Database Search & Replace requires the Omninox Site Toolkit Pro plugin to be installed and active.', 'omninox-site-toolkit' ),
					array( 'status' => 403 )
				);
			}

			if ( 'search_replace_matches' === $action ) {
				return SearchReplacer::find_matches( $params['search'] ?? '', $params['target'] ?? 'post_content' );
			}

			if ( 'search_replace' === $action ) {
				return SearchReplacer::run( $params );
			}

			if ( 'revert_search_replace' === $action ) {
				return SearchReplacer::revert( $params['history_id'] ?? '' );
			}
		}

		return parent::fix( $action, $params );
	}
}
