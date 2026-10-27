<?php
/**
 * Module 5: Database Optimizer.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Database;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;
use OmninoxSiteToolkit\Services\Security;

defined( 'ABSPATH' ) || exit;

class DatabaseModule extends AbstractModule {

	protected $id   = 'database';
	protected $icon = 'database';

	public function get_label() {
		return __( 'Database', 'omninox-site-toolkit' );
	}

	/**
	 * Cleanup buttons.
	 *
	 * @return array
	 */
	public function get_actions() {
		$db_tables     = \OmninoxSiteToolkit\Modules\Search\SearchReplacer::get_tables_with_sizes();
		$table_options = array();
		foreach ( $db_tables as $tbl ) {
			$table_options[] = array(
				'value' => $tbl['name'],
				'label' => $tbl['label'],
			);
		}

		return array(
			array( 'id' => 'clean_revisions', 'label' => __( 'Delete post revisions', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'clean_auto_drafts', 'label' => __( 'Delete auto-drafts', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'clean_trash', 'label' => __( 'Empty trashed posts', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'clean_spam', 'label' => __( 'Delete spam and trashed comments', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'clean_transients', 'label' => __( 'Delete expired transients', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'clean_orphan_meta', 'label' => __( 'Delete orphaned metadata', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'clean_wc_sessions', 'label' => __( 'Delete expired WooCommerce sessions', 'omninox-site-toolkit'), 'type' => 'button' ),
			array( 'id' => 'optimize_tables', 'label' => __( 'Optimize tables', 'omninox-site-toolkit'), 'type' => 'button' ),
			array(
				'id'     => 'clean_all',
				'label'  => __( 'Run every cleanup', 'omninox-site-toolkit'),
				'type'   => 'button',
				'notice' => __( 'Nothing is deleted on the first click. You get a breakdown of what would go, with sample rows, and a database backup is taken automatically before anything is removed.', 'omninox-site-toolkit'),
			),
			array(
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
			),
		);
	}

	/**
	 * Count the bloat.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$counts = $this->counts();

		$checks = array(
			'revisions'    => array( __( 'Post revisions', 'omninox-site-toolkit'), 200, 'clean_revisions' ),
			'auto_drafts'  => array( __( 'Auto-drafts', 'omninox-site-toolkit'), 20, 'clean_auto_drafts' ),
			'trashed_posts' => array( __( 'Trashed posts', 'omninox-site-toolkit'), 20, 'clean_trash' ),
			'spam_comments' => array( __( 'Spam comments', 'omninox-site-toolkit'), 50, 'clean_spam' ),
			'trashed_comments' => array( __( 'Trashed comments', 'omninox-site-toolkit'), 50, 'clean_spam' ),
			'expired_transients' => array( __( 'Expired transients', 'omninox-site-toolkit'), 100, 'clean_transients' ),
			'orphan_postmeta' => array( __( 'Orphaned post metadata', 'omninox-site-toolkit'), 50, 'clean_orphan_meta' ),
			'orphan_commentmeta' => array( __( 'Orphaned comment metadata', 'omninox-site-toolkit'), 50, 'clean_orphan_meta' ),
			'orphan_termmeta' => array( __( 'Orphaned term metadata', 'omninox-site-toolkit'), 50, 'clean_orphan_meta' ),
			'wc_sessions'  => array( __( 'Expired WooCommerce sessions', 'omninox-site-toolkit'), 100, 'clean_wc_sessions' ),
			'autoloaded_kb' => array( __( 'Autoloaded options (KB)', 'omninox-site-toolkit'), 800, '' ),
		);

		foreach ( $checks as $key => $check ) {
			list( $label, $threshold, $fix ) = $check;
			$count = (int) ( $counts[ $key ] ?? 0 );

			$this->add(
				array(
					'id'       => $key,
					'label'    => $label,
					'value'    => number_format_i18n( $count ),
					'expected' => '< ' . number_format_i18n( $threshold ),
					'weight'   => 1,
					'severity' => $count <= $threshold ? self::SEV_PASS : ( $count > $threshold * 5 ? self::SEV_WARNING : self::SEV_NOTICE ),
					'fix'      => $count > $threshold ? $fix : '',
				)
			);
		}

		$tables   = $this->table_stats();
		$overhead = 0;

		foreach ( $tables as $table ) {
			$overhead += $table['overhead_bytes'];
		}

		$this->add(
			array(
				'id'       => 'table_overhead',
				'label'    => __( 'Table overhead', 'omninox-site-toolkit'),
				'value'    => Utils::format_bytes( $overhead ),
				'expected' => '< 5 MB',
				'weight'   => 1.5,
				'severity' => $overhead < 5242880 ? self::SEV_PASS : self::SEV_WARNING,
				'fix'      => $overhead > 5242880 ? 'optimize_tables' : '',
			)
		);

		$total = 0;

		foreach ( $tables as $table ) {
			$total += $table['size_bytes'];
		}

		return $this->result(
			array(
				'tables'     => $tables,
				'total_size' => Utils::format_bytes( $total ),
				'counts'     => $counts,
			)
		);
	}

	/**
	 * Run a cleanup task.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		global $wpdb;

		if ( in_array( $action, array( 'search_replace', 'revert_search_replace', 'search_replace_matches' ), true ) ) {
			if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'database', 'search_replace' ) ) {
				return new \WP_Error(
					'omninox_pro_required',
					__( 'Database Search & Replace requires Omninox Pro.', 'omninox-site-toolkit' ),
					array( 'status' => 403 )
				);
			}

			if ( 'search_replace' === $action ) {
				return \OmninoxSiteToolkit\Modules\Search\SearchReplacer::run( $params );
			}

			if ( 'revert_search_replace' === $action ) {
				return \OmninoxSiteToolkit\Modules\Search\SearchReplacer::revert( $params['history_id'] ?? '' );
			}

			if ( 'search_replace_matches' === $action ) {
				return \OmninoxSiteToolkit\Modules\Search\SearchReplacer::find_matches( $params['search'] ?? '', $params['target'] ?? 'post_content' );
			}
		}

		$destructive = array( 'clean_revisions', 'clean_auto_drafts', 'clean_trash', 'clean_spam', 'clean_transients', 'clean_orphan_meta', 'clean_wc_sessions', 'clean_all' );

		// Nothing destructive runs on the first call. The first call reports
		// exactly what would be removed and hands back a token; only a second
		// call carrying that token actually deletes anything.
		if ( in_array( $action, $destructive, true ) && empty( $params['confirm'] ) ) {
			return $this->preview( $action );
		}

		if ( in_array( $action, $destructive, true ) ) {
			$backup = $this->backup_first( $action, ! empty( $params['skip_backup'] ) );

			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
		}

		$deleted = 0;

		switch ( $action ) {
			case 'clean_revisions':
				$deleted = $this->delete_posts_where( "post_type = 'revision'" );
				break;

			case 'clean_auto_drafts':
				$deleted = $this->delete_posts_where( "post_status = 'auto-draft'" );
				break;

			case 'clean_trash':
				$deleted = $this->delete_posts_where( "post_status = 'trash'" );
				break;

			case 'clean_spam':
				// Meta first, then the comments, so nothing is orphaned.
				$wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_approved IN ('spam', 'trash')" ); // phpcs:ignore WordPress.DB
				$deleted = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')" ); // phpcs:ignore WordPress.DB
				break;

			case 'clean_transients':
				$deleted = $this->delete_expired_transients();
				break;

			case 'clean_orphan_meta':
				$deleted = $this->delete_orphan_meta();
				break;

			case 'clean_wc_sessions':
				$deleted = $this->delete_wc_sessions();
				break;

			case 'optimize_tables':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'database', 'defragment' ) ) {
					return new \WP_Error(
						'omninox_pro_required',
						__( 'Database table defragmentation and optimization requires Omninox Pro.', 'omninox-site-toolkit' ),
						array( 'status' => 403 )
					);
				}
				return $this->optimize_tables();

			case 'clean_all':
				$total = 0;

				$can_pro = \OmninoxSiteToolkit\License\LicenseManager::instance()->is_premium();

				$steps = array( 'clean_revisions', 'clean_auto_drafts', 'clean_trash', 'clean_spam', 'clean_transients', 'clean_wc_sessions' );

				// Orphaned-meta cleanup is a Pro step; include it only when licensed.
				if ( $can_pro ) {
					$steps[] = 'clean_orphan_meta';
				}

				foreach ( $steps as $step ) {
					// The inner steps must carry the confirmation through,
					// otherwise each one returns its dry-run preview and
					// nothing is deleted. The safety backup was already
					// taken once for the whole run, so the steps skip
					// their own.
					$result = $this->fix( $step, array( 'confirm' => 1, 'skip_backup' => 1 ) );

					if ( ! is_wp_error( $result ) ) {
						$total += (int) ( $result['deleted'] ?? 0 );
					}
				}

				// Table optimization is Pro; free runs stop after the deletes.
				if ( $can_pro ) {
					$this->optimize_tables();

					return array(
						'deleted' => $total,
						/* translators: %s: number of rows */
						'message' => sprintf( __( 'Cleanup finished. %s rows removed and tables optimized.', 'omninox-site-toolkit'), number_format_i18n( $total ) ),
					);
				}

				return array(
					'deleted' => $total,
					/* translators: %s: number of rows */
					'message' => sprintf( __( '%s rows removed.', 'omninox-site-toolkit'), number_format_i18n( $total ) ),
				);

			default:
				return parent::fix( $action, $params );
		}

		// Orphaned meta is left behind by post deletions, so tidy up after post-level cleanups.
		if ( in_array( $action, array( 'clean_revisions', 'clean_auto_drafts', 'clean_trash' ), true ) ) {
			$this->delete_orphan_meta();
		}

		return array(
			'deleted' => $deleted,
			/* translators: %s: number of rows */
			'message' => sprintf( __( 'Removed %s rows.', 'omninox-site-toolkit'), number_format_i18n( $deleted ) ),
		);
	}

	/**
	 * Describe what an action would remove, without removing it.
	 *
	 * @param string $action Action id.
	 * @return array
	 */
	private function preview( $action ) {
		$counts  = $this->counts();
		$samples = array();

		$map = array(
			'clean_revisions'   => array( __( 'Post revisions', 'omninox-site-toolkit'), $counts['revisions'], 'revisions' ),
			'clean_auto_drafts' => array( __( 'Auto-drafts', 'omninox-site-toolkit'), $counts['auto_drafts'], 'auto_drafts' ),
			'clean_trash'       => array( __( 'Trashed posts', 'omninox-site-toolkit'), $counts['trashed_posts'], 'trashed_posts' ),
			'clean_spam'        => array( __( 'Spam and trashed comments', 'omninox-site-toolkit'), $counts['spam_comments'] + $counts['trashed_comments'], 'spam' ),
			'clean_transients'  => array( __( 'Expired transients', 'omninox-site-toolkit'), $counts['expired_transients'], 'transients' ),
			'clean_orphan_meta' => array( __( 'Orphaned metadata rows', 'omninox-site-toolkit'), $counts['orphan_postmeta'] + $counts['orphan_commentmeta'] + $counts['orphan_termmeta'], 'orphan' ),
			'clean_wc_sessions' => array( __( 'Expired WooCommerce sessions', 'omninox-site-toolkit'), $counts['wc_sessions'], 'wc' ),
		);

		if ( 'clean_all' === $action ) {
			$total = 0;
			$rows  = array();

			foreach ( $map as $id => $entry ) {
				$total += (int) $entry[1];
				$rows[] = array(
					'what'  => $entry[0],
					'rows'  => number_format_i18n( (int) $entry[1] ),
					'action' => $id,
				);
			}

			return array(
				'preview'    => true,
				'action'     => $action,
				'total_rows' => $total,
				'rows'       => $rows,
				'samples'    => $this->samples( 'revisions' ),
				'reversible' => false,
				'message'    => sprintf(
					/* translators: %s: number of rows */
					__( 'This would permanently delete %s rows across every category below. A database backup is taken automatically before anything is removed. Run it again to confirm.', 'omninox-site-toolkit'),
					number_format_i18n( $total )
				),
			);
		}

		$entry = $map[ $action ] ?? array( $action, 0, '' );

		return array(
			'preview'    => true,
			'action'     => $action,
			'total_rows' => (int) $entry[1],
			'rows'       => array( array( 'what' => $entry[0], 'rows' => number_format_i18n( (int) $entry[1] ) ) ),
			'samples'    => $this->samples( $entry[2] ),
			'reversible' => false,
			'message'    => sprintf(
				/* translators: 1: number of rows, 2: what will be removed */
				__( 'This would permanently delete %1$s rows of %2$s. A database backup is taken automatically first. Run it again to confirm.', 'omninox-site-toolkit'),
				number_format_i18n( (int) $entry[1] ),
				strtolower( $entry[0] )
			),
		);
	}

	/**
	 * A handful of the actual rows that would go, so the number is not
	 * something the person has to take on trust.
	 *
	 * @param string $kind Sample type.
	 * @return array
	 */
	private function samples( $kind ) {
		global $wpdb;

		switch ( $kind ) {
			case 'revisions':
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
					"SELECT p.ID, p.post_title, p.post_modified, parent.post_title AS parent_title
					 FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
					 WHERE p.post_type = 'revision' ORDER BY p.ID DESC LIMIT 10",
					ARRAY_A
				);

				return array_map(
					static function ( $row ) {
						return array(
							'id'      => $row['ID'],
							'item'    => $row['parent_title'] ? $row['parent_title'] : $row['post_title'],
							'changed' => $row['post_modified'],
						);
					},
					(array) $rows
				);

			case 'trashed_posts':
				$rows = $wpdb->get_results( "SELECT ID, post_title, post_type, post_modified FROM {$wpdb->posts} WHERE post_status = 'trash' ORDER BY post_modified DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB

				return array_map(
					static function ( $row ) {
						return array( 'id' => $row['ID'], 'item' => $row['post_title'], 'type' => $row['post_type'], 'changed' => $row['post_modified'] );
					},
					(array) $rows
				);

			case 'transients':
				$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 10",
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						time()
					)
				);

				return array_map(
					static function ( $name ) {
						return array( 'item' => str_replace( '_transient_timeout_', '', $name ) );
					},
					(array) $rows
				);

			case 'spam':
				$rows = $wpdb->get_results( "SELECT comment_ID, comment_author, comment_date FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') ORDER BY comment_date DESC LIMIT 10", ARRAY_A ); // phpcs:ignore WordPress.DB

				return array_map(
					static function ( $row ) {
						return array( 'id' => $row['comment_ID'], 'item' => $row['comment_author'], 'changed' => $row['comment_date'] );
					},
					(array) $rows
				);
		}

		return array();
	}

	/**
	 * Delete posts matching a condition together with their meta and
	 * term relationships, so the cleanup does not create the orphan
	 * rows a later cleanup would have to find.
	 *
	 * The condition is one of a fixed set of literals defined above —
	 * no user input reaches this string.
	 *
	 * @param string $where SQL condition on the posts table.
	 * @return int Posts deleted.
	 */
	private function delete_posts_where( $where ) {
		global $wpdb;

		$wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE {$where}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE {$where}" ); // phpcs:ignore WordPress.DB

		return (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE {$where}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Take a database backup before a destructive cleanup.
	 *
	 * @param string $action Action being run.
	 * @param bool   $skip   Whether the caller opted out.
	 * @return array|\WP_Error
	 */
	private function backup_first( $action, $skip = false ) {
		if ( $skip ) {
			Security::audit( 'performed', 'database/' . $action, 'backup skipped by request' );

			return array( 'skipped' => true );
		}

		$backup = \OmninoxSiteToolkit\Core\ModuleRegistry::instance()->get( 'backup' );
		if ( ! $backup || ! method_exists( $backup, 'fix' ) || $backup instanceof \OmninoxSiteToolkit\Modules\Backup\BackupGate ) {
			Security::audit( 'skipped', 'database/' . $action, 'pre-cleanup backup skipped (requires Omninox Pro)' );

			return array(
				'skipped' => true,
				'notice'  => __( 'Automatic pre-cleanup backup requires Omninox Site Toolkit Pro.', 'omninox-site-toolkit' ),
			);
		}

		$result = $backup->fix( 'backup_database', array( 'offset' => 0, 'auto' => true, 'blocking' => true ) );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'omninox_precleanup_backup_failed',
				sprintf(
					/* translators: %s: underlying error */
					__( 'The automatic backup failed, so the cleanup was cancelled rather than run without a way back. Reason: %s', 'omninox-site-toolkit'),
					$result->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		Security::audit( 'performed', 'database/' . $action, 'backup taken first' );

		return $result;
	}

	/**
	 * Bloat counts.
	 *
	 * @return array
	 */
	private function counts() {
		global $wpdb;

		$counts = array(
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ), // phpcs:ignore WordPress.DB
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ), // phpcs:ignore WordPress.DB
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ), // phpcs:ignore WordPress.DB
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ), // phpcs:ignore WordPress.DB
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ), // phpcs:ignore WordPress.DB
			'orphan_postmeta'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ), // phpcs:ignore WordPress.DB
			'orphan_commentmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" ), // phpcs:ignore WordPress.DB
			'orphan_termmeta'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL" ), // phpcs:ignore WordPress.DB
		);

		$counts['expired_transients'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		$counts['autoloaded_kb'] = (int) round( (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" ) / 1024 ); // phpcs:ignore WordPress.DB

		$sessions = $wpdb->prefix . 'woocommerce_sessions';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) === $sessions ) { // phpcs:ignore WordPress.DB
			$counts['wc_sessions'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE session_expiry < %d", time() ) ); // phpcs:ignore WordPress.DB
		} else {
			$counts['wc_sessions'] = 0;
		}

		return $counts;
	}

	/**
	 * Per-table size and overhead.
	 *
	 * @return array
	 */
	private function table_stats() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();

		foreach ( (array) $rows as $row ) {
			if ( 0 !== strpos( $row['Name'], $wpdb->prefix ) ) {
				continue;
			}

			$size = (int) $row['Data_length'] + (int) $row['Index_length'];

			$out[] = array(
				'name'           => $row['Name'],
				'engine'         => $row['Engine'],
				'rows'           => (int) $row['Rows'],
				'size_bytes'     => $size,
				'size'           => Utils::format_bytes( $size ),
				'overhead_bytes' => (int) $row['Data_free'],
				'overhead'       => Utils::format_bytes( (int) $row['Data_free'] ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['size_bytes'] <=> $a['size_bytes'];
			}
		);

		return $out;
	}

	/**
	 * Delete expired transient pairs.
	 *
	 * @return int
	 */
	private function delete_expired_transients() {
		global $wpdb;

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		$deleted = 0;

		foreach ( (array) $names as $name ) {
			$key = str_replace( '_transient_timeout_', '', $name );

			if ( delete_transient( $key ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Remove metadata whose parent is gone.
	 *
	 * @return int
	 */
	private function delete_orphan_meta() {
		global $wpdb;

		$deleted = 0;

		$deleted += (int) $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ); // phpcs:ignore WordPress.DB
		$deleted += (int) $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" ); // phpcs:ignore WordPress.DB
		$deleted += (int) $wpdb->query( "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL" ); // phpcs:ignore WordPress.DB

		return $deleted;
	}

	/**
	 * Drop expired WooCommerce sessions.
	 *
	 * @return int
	 */
	private function delete_wc_sessions() {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_sessions';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
			return 0;
		}

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE session_expiry < %d", time() ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * OPTIMIZE every prefixed table.
	 *
	 * @return array
	 */
	private function optimize_tables() {
		global $wpdb;

		$tables    = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" ); // phpcs:ignore WordPress.DB
		$optimized = 0;

		foreach ( (array) $tables as $table ) {
			if ( false !== $wpdb->query( "OPTIMIZE TABLE `{$table}`" ) ) { // phpcs:ignore WordPress.DB
				++$optimized;
			}
		}

		return array(
			'optimized' => $optimized,
			/* translators: %d: number of tables */
			'message'   => sprintf( _n( 'Optimized %d table.', 'Optimized %d tables.', $optimized, 'omninox-site-toolkit'), $optimized ),
		);
	}
}
