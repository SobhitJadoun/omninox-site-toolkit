<?php
/**
 * Safe, serialized-aware database search and replace engine.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Search;

defined( 'ABSPATH' ) || exit;

class SearchReplacer {

	const HISTORY_OPTION = 'omninox_search_replace_history';

	public static function find_matches( $search, $target = 'post_content', $limit = 100 ) {
		global $wpdb;
		$search  = trim( (string) $search );
		$target  = sanitize_key( (string) $target );
		$allowed = array( 'url', 'post_content', 'post_excerpt', 'post_title', 'postmeta', 'all' );

		if ( '' === $search ) {
			return new \WP_Error( 'omninox_missing_search_string', __( 'Enter a search term first.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}
		if ( ! in_array( $target, $allowed, true ) ) {
			$target = 'post_content';
		}

		$output_type = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		$like        = '%' . $wpdb->esc_like( $search ) . '%';
		$matches     = array();
		$seen_ids    = array();

		// 1. Direct database candidate search
		if ( 'url' === $target ) {
			$path       = trim( (string) parse_url( $search, PHP_URL_PATH ), '/' );
			$path_parts = explode( '/', $path );
			$slug       = self::slug( rawurldecode( (string) end( $path_parts ) ) );

			$sql  = $wpdb->prepare(
				"SELECT ID, post_title, post_type, post_name FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_status != 'auto-draft' AND (post_name = %s OR post_name LIKE %s OR guid LIKE %s) ORDER BY post_title ASC LIMIT %d",
				$slug,
				'%' . $wpdb->esc_like( $slug ) . '%',
				$like,
				absint( $limit )
			);
			$rows = (array) $wpdb->get_results( $sql, $output_type );
		} elseif ( 'postmeta' === $target ) {
			$sql  = $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_name FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_status != 'trash' AND p.post_status != 'auto-draft' AND pm.meta_value LIKE %s ORDER BY p.post_title ASC LIMIT %d",
				$like,
				absint( $limit )
			);
			$rows = (array) $wpdb->get_results( $sql, $output_type );
		} else {
			$column = 'all' === $target
				? '(post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s OR post_name LIKE %s)'
				: "{$target} LIKE %s";
			$params = 'all' === $target
				? array( $like, $like, $like, $like, absint( $limit ) )
				: array( $like, absint( $limit ) );

			$sql  = $wpdb->prepare(
				"SELECT ID, post_title, post_type, post_name FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_status != 'auto-draft' AND {$column} ORDER BY post_title ASC LIMIT %d",
				...$params
			);
			$rows = (array) $wpdb->get_results( $sql, $output_type );
		}

		foreach ( $rows as $row ) {
			$pid = (int) $row['ID'];
			if ( $pid > 0 && empty( $seen_ids[ $pid ] ) ) {
				$seen_ids[ $pid ] = true;
				$matches[]        = array(
					'id'    => $pid,
					'title' => ! empty( $row['post_title'] ) ? sanitize_text_field( $row['post_title'] ) : sprintf( __( '(Untitled #%d)', 'omninox-site-toolkit'), $pid ),
					'type'  => sanitize_key( $row['post_type'] ),
					'url'   => function_exists( 'get_permalink' ) ? get_permalink( $pid ) : '',
				);
			}
		}

		// 2. Also query LiveSearch / RelevanceEngine to catch tokenized relevance matches
		if ( class_exists( __NAMESPACE__ . '\LiveSearch' ) ) {
			$search_results = LiveSearch::query( $search, $limit );
			foreach ( $search_results as $item ) {
				$pid = (int) ( $item['id'] ?? 0 );
				if ( $pid > 0 && empty( $seen_ids[ $pid ] ) ) {
					$seen_ids[ $pid ] = true;
					$matches[]        = array(
						'id'    => $pid,
						'title' => sanitize_text_field( $item['title'] ),
						'type'  => sanitize_key( $item['type'] ),
						'url'   => esc_url_raw( $item['url'] ),
					);
				}
			}
		}

		return array(
			'module'  => 'search',
			'target'  => $target,
			'query'   => $search,
			'matches' => $matches,
			'message' => sprintf(
				/* translators: %d: matching posts count */
				__( 'Found %d matching post(s) or page(s). Select the content to replace.', 'omninox-site-toolkit'),
				count( $matches )
			),
		);
	}


	/**
	 * Run a search and replace across database tables.
	 *
	 * @param array $params Request parameters (search, replace, mode, tables, case_sensitive).
	 * @return array|\WP_Error
	 */
	public static function run( array $params = array() ) {
		global $wpdb;

		// Raise resources for database operations
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			@wp_raise_memory_limit( 'admin' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$search         = (string) ( $params['search'] ?? '' );
		$replace        = (string) ( $params['replace'] ?? '' );
		$mode           = (string) ( $params['mode'] ?? 'dry_run' );
		$dry_run        = ( 'live_run' !== $mode );
		$scope          = (string) ( $params['tables'] ?? 'all' );
		$case_sensitive = ! empty( $params['case_sensitive'] ) && 'yes' === $params['case_sensitive'];
		$target         = function_exists( 'sanitize_key' )
			? sanitize_key( $params['target'] ?? 'all' )
			: preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $params['target'] ?? 'all' ) );
		$post_ids       = self::parse_ids( $params['post_ids'] ?? '' );

		if ( '' === trim( $search ) ) {
			return new \WP_Error(
				'omninox_missing_search_string',
				__( 'Please enter a search term or string to find.', 'omninox-site-toolkit'),
				array( 'status' => 400 )
			);
		}

		if ( $search === $replace ) {
			return new \WP_Error(
				'omninox_identical_search_replace',
				__( 'Search and replace strings are identical. Nothing to replace.', 'omninox-site-toolkit'),
				array( 'status' => 400 )
			);
		}

		try {
			$tables = self::target_tables( $target, $scope );
			if ( empty( $tables ) && 'url' !== $target ) {
				return new \WP_Error(
					'omninox_no_tables',
					__( 'No matching database tables found for the selected scope.', 'omninox-site-toolkit'),
					array( 'status' => 404 )
				);
			}

			$start_time          = microtime( true );
			$total_rows_scanned  = 0;
			$total_rows_modified = 0;
			$total_replacements  = 0;
			$table_results       = array();
			$history_rows        = array();

			foreach ( $tables as $table ) {
				$res                  = self::replace_in_table( $table, $search, $replace, $dry_run, $case_sensitive, $target, $post_ids, $history_rows );
				$total_rows_scanned  += $res['scanned'];
				$total_rows_modified += $res['modified'];
				$total_replacements  += $res['replacements'];

				if ( $res['modified'] > 0 || count( $tables ) <= 8 ) {
					$table_results[] = array(
						'Table'        => $table,
						'Rows Scanned' => $res['scanned'],
						'Rows Matched' => $res['modified'],
						'Occurrences'  => $res['replacements'],
						'Action'       => $dry_run ? __( 'Dry Run (Preview)', 'omninox-site-toolkit') : __( 'Updated in DB', 'omninox-site-toolkit'),
					);
				}
			}

			// Automatically update post/page permalink slug if replacing a site URL or slug
			$permalink = 'url' === $target ? self::maybe_update_post_permalink( $search, $replace, $dry_run, $post_ids ) : array( 'changed' => false );
			if ( ! empty( $permalink['changed'] ) ) {
				++$total_rows_modified;
				++$total_replacements;
				$table_results[] = array(
					'Table'        => $wpdb->posts . ' (post_name slug)',
					'Rows Scanned' => 1,
					'Rows Matched' => 1,
					'Occurrences'  => 1,
					'Action'       => $dry_run
						? sprintf( __( 'Slug Preview: "%1$s" → "%2$s"', 'omninox-site-toolkit'), $permalink['old_slug'], $permalink['new_slug'] )
						: sprintf( __( 'Slug Updated: "%1$s" → "%2$s"', 'omninox-site-toolkit'), $permalink['old_slug'], $permalink['new_slug'] ),
				);
				if ( ! $dry_run ) {
					$history_rows[] = array(
						'table'  => $wpdb->posts,
						'where'  => array( 'ID' => $permalink['post_id'] ),
						'before' => array( 'post_name' => $permalink['old_slug'] ),
					);
				}
			}

			$history_id = '';
			if ( ! $dry_run && ! empty( $history_rows ) ) {
				$history_id = self::save_history( $search, $replace, $target, $post_ids, $history_rows );
			}

			$elapsed = round( ( microtime( true ) - $start_time ) * 1000, 2 );

			if ( empty( $table_results ) ) {
				$table_results[] = array(
					'Table'        => __( 'All Scanned Tables', 'omninox-site-toolkit'),
					'Rows Scanned' => $total_rows_scanned,
					'Rows Matched' => 0,
					'Occurrences'  => 0,
					'Action'       => $dry_run ? __( 'Preview Complete', 'omninox-site-toolkit') : __( 'Complete', 'omninox-site-toolkit'),
				);
			}

			$mode_label = $dry_run
				? __( 'DRY RUN (Preview Only — Database not modified)', 'omninox-site-toolkit')
				: __( 'LIVE RUN (Database Updated)', 'omninox-site-toolkit');

			$message = sprintf(
				/* translators: 1: mode label, 2: occurrences count, 3: rows count, 4: search string, 5: replace string, 6: time ms */
				__( '[%1$s] Found %2$d occurrences across %3$d rows for "%4$s" → "%5$s" in %6$sms.', 'omninox-site-toolkit'),
				$mode_label,
				$total_replacements,
				$total_rows_modified,
				$search,
				$replace,
				$elapsed
			);

			return array(
				'module'       => 'search',
				'mode'         => $dry_run ? 'dry_run' : 'live_run',
				'search'       => $search,
				'replace'      => $replace,
				'scanned_rows' => $total_rows_scanned,
				'matched_rows' => $total_rows_modified,
				'replacements' => $total_replacements,
				'duration_ms'  => $elapsed,
				'message'      => $message,
				'rows'         => $table_results,
				'permalink'    => $permalink,
				'history_id'   => $history_id,
				'can_revert'   => '' !== $history_id,
				'target'      => $target,
				'post_ids'    => $post_ids,
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'omninox_search_replace_failed',
				sprintf( __( 'Search and replace stopped safely: %s', 'omninox-site-toolkit'), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}
	}

	public static function revert( $history_id ) {
		global $wpdb;
		$history_id = sanitize_key( $history_id );
		$history     = (array) get_option( self::HISTORY_OPTION, array() );
		$entry       = $history[ $history_id ] ?? null;

		if ( ! is_array( $entry ) || empty( $entry['changes'] ) ) {
			return new \WP_Error( 'omninox_history_not_found', __( 'That replacement history entry is no longer available.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
		}

		$restored = 0;
		foreach ( $entry['changes'] as $change ) {
			if ( empty( $change['table'] ) || empty( $change['where'] ) || ! isset( $change['before'] ) ) {
				continue;
			}
			$updated = $wpdb->update( $change['table'], (array) $change['before'], (array) $change['where'] );
			if ( false !== $updated ) {
				++$restored;
			}
		}

		if ( $restored && function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		unset( $history[ $history_id ] );
		update_option( self::HISTORY_OPTION, $history, false );

		return array(
			'message'  => sprintf( __( 'Replacement reverted. Restored %d changed row(s).', 'omninox-site-toolkit'), $restored ),
			'restored' => $restored,
		);
	}

	private static function save_history( $search, $replace, $target, array $post_ids, array $changes ) {
		$history_id = 'sr_' . strtolower( wp_generate_password( 12, false, false ) );
		$history    = (array) get_option( self::HISTORY_OPTION, array() );
		$history[ $history_id ] = array(
			'search'  => sanitize_text_field( $search ),
			'replace' => sanitize_text_field( $replace ),
			'target'  => sanitize_key( $target ),
			'post_ids' => $post_ids,
			'at'      => current_time( 'mysql' ),
			'changes' => $changes,
		);
		$history = array_slice( $history, -10, 10, true );
		update_option( self::HISTORY_OPTION, $history, false );

		return $history_id;
	}

	private static function parse_ids( $ids ) {
		$values = is_array( $ids ) ? $ids : explode( ',', (string) $ids );
		$values = array_map(
			function ( $value ) {
				return function_exists( 'absint' ) ? absint( $value ) : abs( (int) $value );
			},
			$values
		);
		return array_values( array_filter( $values ) );
	}

	private static function target_tables( $target, $scope ) {
		global $wpdb;

		if ( 'url' === $target ) {
			return array();
		}
		if ( in_array( $target, array( 'post_content', 'post_excerpt', 'post_title' ), true ) ) {
			return array( $wpdb->posts );
		}
		if ( 'postmeta' === $target ) {
			return array( $wpdb->postmeta );
		}
		return self::get_target_tables( $scope );
	}

	/**
	 * Update post or page slug when the request targets a real page URL or slug.
	 *
	 * WordPress builds frontend URLs dynamically from post_name (slug) + permalink structure,
	 * not from raw text occurrences in content. This method resolves the target page/post
	 * and updates post_name and rewrite caches.
	 *
	 * @param string $search  Original URL or slug.
	 * @param string $replace Replacement URL or slug.
	 * @param bool   $dry_run Whether this is a preview.
	 * @return array
	 */
	public static function maybe_update_post_permalink( $search, $replace, $dry_run, array $post_ids = array() ) {
		global $wpdb;

		$result = array( 'changed' => false );
		if ( ! method_exists( $wpdb, 'get_row' ) ) {
			return $result;
		}

		$search_path  = trim( (string) parse_url( $search, PHP_URL_PATH ), '/' );
		$replace_path = trim( (string) parse_url( $replace, PHP_URL_PATH ), '/' );

		if ( '' === $search_path && false === strpos( $search, '/' ) ) {
			$search_path = trim( $search );
		}
		if ( '' === $replace_path && false === strpos( $replace, '/' ) ) {
			$replace_path = trim( $replace );
		}

		if ( '' === $search_path || '' === $replace_path || $search_path === $replace_path ) {
			return $result;
		}

		$search_slugs       = explode( '/', $search_path );
		$target_search_slug = self::slug( rawurldecode( (string) end( $search_slugs ) ) );

		$replace_slugs   = explode( '/', $replace_path );
		$target_new_slug = self::slug( rawurldecode( (string) end( $replace_slugs ) ) );

		if ( '' === $target_search_slug || '' === $target_new_slug || $target_search_slug === $target_new_slug ) {
			return $result;
		}

		// 1. Find matching post or page by post_name in wp_posts
		$matching_post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_name, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status != 'trash' LIMIT 1",
				$target_search_slug
			)
		);

		// 2. Fallback to url_to_postid if needed
		if ( ! $matching_post && function_exists( 'url_to_postid' ) ) {
			$pid = (int) url_to_postid( $search );
			if ( $pid > 0 ) {
				$matching_post = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT ID, post_name, post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
						$pid
					)
				);
			}
		}

		if ( ! $matching_post ) {
			return $result;
		}
		if ( ! empty( $post_ids ) && ! in_array( (int) $matching_post->ID, $post_ids, true ) ) {
			return $result;
		}

		$post_id  = (int) $matching_post->ID;
		$old_slug = (string) $matching_post->post_name;

		$result = array(
			'changed'  => true,
			'post_id'  => $post_id,
			'old_slug' => $old_slug,
			'new_slug' => $target_new_slug,
		);

		if ( ! $dry_run ) {
			// Update wp_posts directly
			$wpdb->update(
				$wpdb->posts,
				array( 'post_name' => $target_new_slug ),
				array( 'ID' => $post_id )
			);

			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $post_id );
			}
			if ( function_exists( 'flush_rewrite_rules' ) ) {
				flush_rewrite_rules( false );
			}
		}

		return $result;
	}

	private static function slug( $value ) {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		$value = strtolower( preg_replace( '/[^a-z0-9\-_ ]/i', '', (string) $value ) );
		return trim( preg_replace( '/[-_ ]+/', '-', $value ), '-' );
	}

	/**
	 * Retrieve all database tables with formatted sizes in MB.
	 *
	 * @return array
	 */
	public static function get_tables_with_sizes() {
		global $wpdb;
		$tables = array();

		$output_type = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		$sql = "SELECT TABLE_NAME, ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . $wpdb->esc_like( $wpdb->prefix ) . "%' ORDER BY TABLE_NAME ASC";
		$rows = (array) $wpdb->get_results( $sql, $output_type ); // phpcs:ignore WordPress.DB

		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$mb = (float) ( $row['size_mb'] ?? 0 );
				$tables[] = array(
					'name'  => $row['TABLE_NAME'],
					'size'  => sprintf( '%.2f MB', $mb ),
					'label' => sprintf( '%s (%.2f MB)', $row['TABLE_NAME'], $mb ),
				);
			}
		} else {
			$raw_tables = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
				"SHOW TABLES LIKE '" . $wpdb->esc_like( $wpdb->prefix ) . "%'"
			);
			foreach ( $raw_tables as $t ) {
				$tables[] = array(
					'name'  => $t,
					'size'  => '',
					'label' => $t,
				);
			}
		}

		return $tables;
	}

	/**
	 * Get database tables matching the chosen scope or selected table list.
	 *
	 * @param string|array $scope Scope slug or array of table names.
	 * @return array
	 */
	public static function get_target_tables( $scope = 'all' ) {
		global $wpdb;

		$all_tables = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SHOW TABLES LIKE '" . $wpdb->esc_like( $wpdb->prefix ) . "%'"
		);

		if ( is_array( $scope ) ) {
			$selected = array_intersect( $all_tables, $scope );
			return ! empty( $selected ) ? array_values( $selected ) : $all_tables;
		}

		if ( is_string( $scope ) && false !== strpos( $scope, ',' ) ) {
			$custom   = array_map( 'trim', explode( ',', $scope ) );
			$selected = array_intersect( $all_tables, $custom );
			if ( ! empty( $selected ) ) {
				return array_values( $selected );
			}
		}

		if ( is_string( $scope ) && in_array( $scope, $all_tables, true ) ) {
			return array( $scope );
		}

		switch ( $scope ) {
			case 'posts':
				return array_values( array_intersect( $all_tables, array( $wpdb->posts ) ) );

			case 'postmeta':
				return array_values( array_intersect( $all_tables, array( $wpdb->postmeta ) ) );

			case 'options':
				return array_values( array_intersect( $all_tables, array( $wpdb->options ) ) );

			case 'users':
				return array_values( array_intersect( $all_tables, array( $wpdb->users, $wpdb->usermeta ) ) );

			case 'all':
			default:
				return $all_tables;
		}
	}

	/**
	 * Perform search and replace in a specific table using fast SQL pre-filtering.
	 *
	 * @param string $table          Table name.
	 * @param string $search         Search string.
	 * @param string $replace        Replacement string.
	 * @param bool   $dry_run        If true, do not execute DB updates.
	 * @param bool   $case_sensitive Whether string replacement is case-sensitive.
	 * @param string $target        Replacement target.
	 * @param array  $post_ids      Optional post IDs.
	 * @return array
	 */
	public static function replace_in_table( $table, $search, $replace, $dry_run = true, $case_sensitive = false, $target = 'all', array $post_ids = array(), array &$history_rows = array() ) {
		global $wpdb;

		$allowed_tables = self::get_target_tables( 'all' );
		if ( ! in_array( $table, $allowed_tables, true ) ) {
			return array(
				'scanned'      => 0,
				'modified'     => 0,
				'replacements' => 0,
			);
		}

		$output_type  = defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A';
		$columns      = (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", $output_type ); // phpcs:ignore WordPress.DB
		$pk_cols      = array();
		$text_columns = array();

		$target_column = in_array( $target, array( 'post_content', 'post_excerpt', 'post_title' ), true )
			? $target
			: ( 'postmeta' === $target ? 'meta_value' : '' );
		foreach ( $columns as $column ) {
			$col_name = (string) ( $column['Field'] ?? '' );
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $col_name ) ) {
				continue;
			}

			if ( 'PRI' === ( $column['Key'] ?? '' ) ) {
				$pk_cols[] = $col_name;
			}

			if ( $target_column && $table === $wpdb->posts && $col_name !== $target_column ) {
				continue;
			}
			if ( 'postmeta' === $target && $table !== $wpdb->postmeta ) {
				continue;
			}
			if ( 'postmeta' === $target && $table === $wpdb->postmeta && $col_name !== 'meta_value' ) {
				continue;
			}
			if ( preg_match( '/char|text|blob|json/i', (string) ( $column['Type'] ?? '' ) ) ) {
				$text_columns[] = $col_name;
			}
		}

		if ( empty( $text_columns ) ) {
			return array(
				'scanned'      => 0,
				'modified'     => 0,
				'replacements' => 0,
			);
		}

		$safe_table = esc_sql( $table );

		// Fast count of total rows in table
		$total_in_table = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Build SQL pre-filter condition so MySQL only returns candidate rows containing the search string
		$where_parts = array();
		foreach ( $text_columns as $col ) {
			$where_parts[] = '`' . sanitize_key( $col ) . '` LIKE %s';
		}
		$where_sql    = '(' . implode( ' OR ', $where_parts ) . ')';
		$like_param   = '%' . $wpdb->esc_like( $search ) . '%';
		$where_params = array_fill( 0, count( $text_columns ), $like_param );

		$modified_rows      = 0;
		$total_replacements = 0;

		$offset     = 0;
		$batch_size = 500;

		while ( true ) {
			if ( ! empty( $pk_cols ) ) {
				$safe_pk_cols = array_map( 'sanitize_key', $pk_cols );
				$order_by     = implode( '`, `', $safe_pk_cols );
				$query        = $wpdb->prepare(
					"SELECT * FROM `{$safe_table}` WHERE {$where_sql} ORDER BY `{$order_by}` LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...array_merge( $where_params, array( $batch_size, $offset ) )
				);
			} else {
				$query = $wpdb->prepare(
					"SELECT * FROM `{$safe_table}` WHERE {$where_sql} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...array_merge( $where_params, array( $batch_size, $offset ) )
				);
			}

			$rows = (array) $wpdb->get_results( $query, $output_type ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! empty( $post_ids ) && $table === $wpdb->posts && ( empty( $row['ID'] ) || ! in_array( (int) $row['ID'], $post_ids, true ) ) ) {
					continue;
				}
				if ( ! empty( $post_ids ) && $table === $wpdb->postmeta && ( empty( $row['post_id'] ) || ! in_array( (int) $row['post_id'], $post_ids, true ) ) ) {
					continue;
				}
				$updates             = array();
				$row_had_replacement = false;

				foreach ( $text_columns as $col ) {
					$value = $row[ $col ] ?? null;
					if ( null === $value || '' === $value ) {
						continue;
					}

					$count   = 0;
					$new_val = self::deep_replace( $value, $search, $replace, $case_sensitive, $count );

					if ( $count > 0 && $new_val !== $value ) {
						$updates[ $col ]     = $new_val;
						$total_replacements += $count;
						$row_had_replacement = true;
					}
				}

				if ( $row_had_replacement ) {
					++$modified_rows;

					if ( ! $dry_run && ! empty( $updates ) ) {
						try {
							if ( ! empty( $pk_cols ) ) {
								$where = array();
								foreach ( $pk_cols as $pk ) {
									if ( isset( $row[ $pk ] ) ) {
										$where[ $pk ] = $row[ $pk ];
									}
								}
								if ( ! empty( $where ) ) {
									$updated = $wpdb->update( $table, $updates, $where ); // phpcs:ignore WordPress.DB
									if ( false !== $updated ) {
										$history_rows[] = array( 'table' => $table, 'where' => $where, 'before' => array_intersect_key( $row, $updates ) );
									}
								}
							} else {
								// Construct safe minimal WHERE for non-PK tables
								$where = array();
								foreach ( $row as $k => $v ) {
									if ( null !== $v && ! isset( $updates[ $k ] ) && strlen( (string) $v ) < 255 ) {
										$where[ $k ] = $v;
									}
								}
								if ( ! empty( $where ) ) {
									$updated = $wpdb->update( $table, $updates, $where ); // phpcs:ignore WordPress.DB
									if ( false !== $updated ) {
										$history_rows[] = array( 'table' => $table, 'where' => $where, 'before' => array_intersect_key( $row, $updates ) );
									}
								}
							}

							// If post was modified, clean post cache
							if ( $table === $wpdb->posts && isset( $row['ID'] ) && function_exists( 'clean_post_cache' ) ) {
								clean_post_cache( $row['ID'] );
							}
						} catch ( \Throwable $e ) {
							// Continue safely on row update error
						}
					}
				}
			}

			$offset += count( $rows );
			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}

		if ( ! $dry_run && $modified_rows > 0 && $table === $wpdb->posts && function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		return array(
			'scanned'      => $total_in_table,
			'modified'     => $modified_rows,
			'replacements' => $total_replacements,
		);
	}

	/**
	 * Recursively search and replace strings in scalar values, arrays, objects, JSON, and serialized strings.
	 *
	 * @param mixed  $data           Input data.
	 * @param string $search         Search string.
	 * @param string $replace        Replace string.
	 * @param bool   $case_sensitive Case sensitivity.
	 * @param int    $count          Output counter for replacements.
	 * @return mixed
	 */
	public static function deep_replace( $data, $search, $replace, $case_sensitive = false, &$count = 0 ) {
		if ( is_string( $data ) ) {
			// Check if serialized PHP string
			if ( self::is_serialized_string( $data ) ) {
				try {
					$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( false !== $unserialized || 'b:0;' === $data ) {
						$replaced_data = self::deep_replace( $unserialized, $search, $replace, $case_sensitive, $count );
						return serialize( $replaced_data );
					}
				} catch ( \Throwable $e ) {
					// Fallback to regex serialized replacement if unserialize/recursion fails
					return self::regex_serialized_replace( $data, $search, $replace, $case_sensitive, $count );
				}
			}

			// Check if valid JSON string
			if ( ( 0 === strpos( $data, '{' ) || 0 === strpos( $data, '[' ) ) ) {
				try {
					$decoded = json_decode( $data, true );
					if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
						$replaced_json = self::deep_replace( $decoded, $search, $replace, $case_sensitive, $count );
						return wp_json_encode( $replaced_json );
					}
				} catch ( \Throwable $e ) {
					// Fall through to plain replace
				}
			}

			// Plain string replacement
			$sub_count = 0;
			if ( $case_sensitive ) {
				$new_string = str_replace( $search, $replace, $data, $sub_count );
			} else {
				$new_string = str_ireplace( $search, $replace, $data, $sub_count );
			}

			$count += $sub_count;
			return $new_string;
		}

		if ( is_array( $data ) ) {
			$copy = array();
			foreach ( $data as $k => $v ) {
				$k_count = 0;
				$new_key = is_string( $k ) ? self::deep_replace( $k, $search, $replace, $case_sensitive, $k_count ) : $k;
				$count  += $k_count;
				$copy[ $new_key ] = self::deep_replace( $v, $search, $replace, $case_sensitive, $count );
			}
			return $copy;
		}

		if ( is_object( $data ) ) {
			if ( $data instanceof \__PHP_Incomplete_Class ) {
				try {
					$arr = (array) $data;
					$copy = clone $data;
					foreach ( $arr as $prop => $val ) {
						$new_val = self::deep_replace( $val, $search, $replace, $case_sensitive, $count );
						if ( 0 !== strpos( (string) $prop, "\0" ) ) {
							try {
								$copy->{$prop} = $new_val;
							} catch ( \Throwable $e ) {
								// Ignore
							}
						}
					}
					return $copy;
				} catch ( \Throwable $e ) {
					return $data;
				}
			}

			if ( $data instanceof \stdClass ) {
				try {
					$copy = clone $data;
					foreach ( get_object_vars( $data ) as $prop => $val ) {
						if ( 0 !== strpos( (string) $prop, "\0" ) ) {
							$copy->{$prop} = self::deep_replace( $val, $search, $replace, $case_sensitive, $count );
						}
					}
					return $copy;
				} catch ( \Throwable $e ) {
					return $data;
				}
			}

			try {
				$ref = new \ReflectionClass( $data );
				if ( ! $ref->isCloneable() ) {
					return $data;
				}
				$copy = clone $data;
				while ( $ref ) {
					foreach ( $ref->getProperties() as $property ) {
						$property->setAccessible( true );
						$val     = $property->getValue( $copy );
						$new_val = self::deep_replace( $val, $search, $replace, $case_sensitive, $count );
						$property->setValue( $copy, $new_val );
					}
					$ref = $ref->getParentClass();
				}
				return $copy;
			} catch ( \Throwable $e ) {
				return $data;
			}
		}

		return $data;
	}

	/**
	 * Regex-based serialized string replacement fallback with byte length recalculation.
	 *
	 * @param string $data           Serialized string.
	 * @param string $search         Search term.
	 * @param string $replace        Replacement.
	 * @param bool   $case_sensitive Case sensitivity.
	 * @param int    $count          Counter.
	 * @return string
	 */
	public static function regex_serialized_replace( $data, $search, $replace, $case_sensitive = false, &$count = 0 ) {
		$callback = function ( $matches ) use ( $search, $replace, $case_sensitive, &$count ) {
			$content     = $matches[2];
			$sub_count   = 0;
			$new_content = $case_sensitive
				? str_replace( $search, $replace, $content, $sub_count )
				: str_ireplace( $search, $replace, $content, $sub_count );

			if ( $sub_count > 0 ) {
				$count   += $sub_count;
				$new_len  = strlen( $new_content );
				return 's:' . $new_len . ':"' . $new_content . '";';
			}
			return $matches[0];
		};

		return preg_replace_callback( '/s:(\d+):"(.*?)";/s', $callback, $data );
	}

	/**
	 * Helper to test if a string appears to be serialized PHP.
	 *
	 * @param string $data String to test.
	 * @return bool
	 */
	private static function is_serialized_string( $data ) {
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}

		if ( strlen( $data ) < 4 ) {
			return false;
		}

		if ( ':' !== $data[1] ) {
			return false;
		}

		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}

		$token = $data[0];
		return in_array( $token, array( 's', 'a', 'O', 'b', 'i', 'd' ), true );
	}
}
