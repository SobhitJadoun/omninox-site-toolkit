<?php
/**
 * Everything the dashboard shows, and the quick actions it can run.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Services;

use OmninoxSiteToolkit\Core\ModuleRegistry;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Database\Schema;
use OmninoxSiteToolkit\Database\ScanRepository;
use OmninoxSiteToolkit\Reports\ReportGenerator;

defined( 'ABSPATH' ) || exit;

class Dashboard {

	/**
	 * Assemble every widget in one request.
	 *
	 * @return array
	 */
	public static function widgets() {
		return array(
			'activity'   => self::recent_activity(),
			'errors'     => self::latest_errors(),
			'analytics'  => self::analytics(),
			'scans'      => self::scan_history(),
			'updates'    => Advisor::updates(),
			'tasks'      => self::scheduled_tasks(),
			'notices'    => self::notifications(),
			'resources'  => self::resources(),
			'quick'      => self::quick_actions(),
		);
	}

	/**
	 * The last things that happened, from the action and security logs.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function recent_activity( $limit = 12 ) {
		global $wpdb;

		$table = Schema::table( 'logs' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT created_at, source, message, type FROM {$table}
				 WHERE type IN ('action','security') ORDER BY created_at DESC LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'when'   => $row['created_at'],
				'ago'    => human_time_diff( strtotime( $row['created_at'] ) ),
				'what'   => $row['message'],
				'source' => $row['source'],
			);
		}

		foreach ( ValueHistory::recent( 8 ) as $change ) {
			$out[] = array(
				'when'   => $change['at'],
				'ago'    => human_time_diff( strtotime( $change['at'] ) ),
				'what'   => sprintf(
					/* translators: 1: setting, 2: old value, 3: new value */
					__( '%1$s changed from %2$s to %3$s', 'omninox-site-toolkit'),
					$change['label'] ? $change['label'] : $change['key'],
					$change['from'],
					$change['to']
				),
				'source' => 'settings',
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['when'], $a['when'] );
			}
		);

		return array_slice( $out, 0, $limit );
	}

	/**
	 * The most recent errors worth looking at.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function latest_errors( $limit = 8 ) {
		global $wpdb;

		$table = Schema::table( 'logs' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT created_at, type, level, message, file, line, hits FROM {$table}
				 WHERE type NOT IN ('action','security') AND level IN ('fatal','error','warning')
				 ORDER BY created_at DESC LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'when'    => $row['created_at'],
					'ago'     => human_time_diff( strtotime( $row['created_at'] ) ),
					'level'   => $row['level'],
					'type'    => $row['type'],
					'message' => wp_trim_words( (string) $row['message'], 24 ),
					'where'   => $row['file'] ? basename( (string) $row['file'] ) . ':' . (int) $row['line'] : '',
					'hits'    => (int) $row['hits'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Site-level counts worth glancing at.
	 *
	 * @return array
	 */
	public static function analytics() {
		global $wpdb;

		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$media    = (array) wp_count_attachments();
		$comments = wp_count_comments();

		$db_size = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			"SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
		);

		$errors_7d = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT COALESCE(SUM(hits), 0) FROM ' . Schema::table( 'logs' ) . " WHERE level IN ('fatal','error') AND created_at >= %s",
				gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) )
			)
		);

		return array(
			'posts'      => (int) ( $posts->publish ?? 0 ),
			'pages'      => (int) ( $pages->publish ?? 0 ),
			'media'      => array_sum( array_map( 'intval', $media ) ),
			'comments'   => (int) ( $comments->approved ?? 0 ),
			'pending'    => (int) ( $comments->moderated ?? 0 ),
			'users'      => (int) count_users()['total_users'],
			'db_size'    => size_format( $db_size ),
			'errors_7d'  => $errors_7d,
		);
	}

	/**
	 * When each module was last scanned, and what it scored.
	 *
	 * @return array
	 */
	public static function scan_history() {
		$scores = (array) get_option( 'omninox_last_scores', array() );
		$rows   = array();

		foreach ( ModuleRegistry::instance()->all() as $id => $module ) {
			$entry = $scores[ $id ] ?? null;

			$rows[] = array(
				'module' => $module->get_label(),
				'id'     => $id,
				'score'  => $entry ? (int) $entry['score'] : null,
				'grade'  => $entry['grade'] ?? '',
				'when'   => $entry['at'] ?? '',
				'ago'    => ! empty( $entry['at'] ) ? human_time_diff( strtotime( $entry['at'] ) ) : '',
			);
		}

		return $rows;
	}

	/**
	 * Scheduled work, ours and everyone else's.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function scheduled_tasks( $limit = 12 ) {
		$rows = array();
		$now  = time();

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					$rows[] = array(
						'hook'     => $hook,
						'ours'     => 0 === strpos( $hook, 'omninox_' ),
						'next'     => gmdate( 'Y-m-d H:i', $timestamp ),
						'in'       => $timestamp > $now
							? human_time_diff( $now, $timestamp )
							: sprintf(
								/* translators: %s: relative time */
								__( '%s overdue', 'omninox-site-toolkit'),
								human_time_diff( $timestamp, $now )
							),
						'schedule' => $event['schedule'] ? $event['schedule'] : __( 'one-off', 'omninox-site-toolkit'),
						'overdue'  => $timestamp < ( $now - 300 ),
					);
				}
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['next'], $b['next'] );
			}
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Things the person should be told without having to go looking.
	 *
	 * @return array
	 */
	public static function notifications() {
		$notices = array();
		$scores  = (array) get_option( 'omninox_last_scores', array() );

		if ( empty( $scores ) ) {
			$notices[] = array(
				'level'   => 'notice',
				'message' => __( 'No scans have been run yet. Start with a full audit so the rest of this dashboard has something to report.', 'omninox-site-toolkit'),
				'action'  => 'scan_website',
			);
		}

		$backup = ScanRepository::latest( 'backup' );

		if ( $backup ) {
			foreach ( (array) $backup['issues'] as $issue ) {
				if ( in_array( $issue['id'], array( 'backup_plugin', 'last_backup' ), true ) && in_array( $issue['severity'], array( 'critical', 'warning' ), true ) ) {
					$notices[] = array(
						'level'   => $issue['severity'],
						'message' => $issue['label'] . ': ' . $issue['value'],
						'action'  => 'backup_now',
					);
				}
			}
		}

		$security = ScanRepository::latest( 'security' );

		if ( $security && ! empty( $security['summary']['critical'] ) ) {
			$notices[] = array(
				'level'   => 'critical',
				'message' => sprintf(
					/* translators: %d: number of issues */
					_n( '%d critical security issue needs attention.', '%d critical security issues need attention.', (int) $security['summary']['critical'], 'omninox-site-toolkit'),
					(int) $security['summary']['critical']
				),
				'action'  => 'security_scan',
			);
		}

		$updates = Advisor::updates();

		if ( $updates['total'] > 0 ) {
			$notices[] = array(
				'level'   => 'warning',
				'message' => $updates['message'],
				'action'  => 'check_updates',
			);
		}

		if ( ! wp_next_scheduled( 'omninox_scheduled_scan' ) && 'off' !== Settings::instance()->get( 'scan_schedule', 'weekly' ) ) {
			$notices[] = array(
				'level'   => 'notice',
				'message' => __( 'Automatic scans are configured but not scheduled. Save the settings once to register the schedule.', 'omninox-site-toolkit'),
				'action'  => '',
			);
		}

		return $notices;
	}

	/**
	 * Storage, memory and load.
	 *
	 * @return array
	 */
	public static function resources() {
		return Advisor::resources();
	}

	/**
	 * The quick action buttons and what each one does.
	 *
	 * @return array
	 */
	public static function quick_actions() {
		return array(
			array( 'id' => 'scan_website', 'label' => __( 'Scan website', 'omninox-site-toolkit'), 'description' => __( 'Runs every module and refreshes all scores.', 'omninox-site-toolkit') ),
			array( 'id' => 'quick_optimization', 'label' => __( 'Quick optimisation', 'omninox-site-toolkit'), 'description' => __( 'Turns on the safe front-end optimisations that carry no risk of breaking a layout.', 'omninox-site-toolkit') ),
			array( 'id' => 'security_scan', 'label' => __( 'Security scan', 'omninox-site-toolkit'), 'description' => __( 'Core checksums, malware heuristics and exposure checks.', 'omninox-site-toolkit') ),
			array( 'id' => 'clean_database', 'label' => __( 'Clean database', 'omninox-site-toolkit'), 'description' => __( 'Previews what would be removed, then backs up before deleting.', 'omninox-site-toolkit') ),
			array( 'id' => 'optimize_images', 'label' => __( 'Optimise images', 'omninox-site-toolkit'), 'description' => __( 'Converts a batch of images to WebP / AVIF.', 'omninox-site-toolkit') ),
			array( 'id' => 'generate_report', 'label' => __( 'Generate report', 'omninox-site-toolkit'), 'description' => __( 'Builds an HTML audit from the latest scans.', 'omninox-site-toolkit') ),
			array( 'id' => 'clear_cache', 'label' => __( 'Clear cache', 'omninox-site-toolkit'), 'description' => __( 'Empties generated minified files and the object cache.', 'omninox-site-toolkit') ),
			array( 'id' => 'check_updates', 'label' => __( 'Check updates', 'omninox-site-toolkit'), 'description' => __( 'Forces WordPress to re-check for core, plugin and theme updates.', 'omninox-site-toolkit') ),
			array( 'id' => 'backup_now', 'label' => __( 'Back up now', 'omninox-site-toolkit'), 'description' => __( 'Creates a database archive you can download.', 'omninox-site-toolkit') ),
		);
	}

	/**
	 * Run a quick action.
	 *
	 * @param string $action Action id.
	 * @return array|\WP_Error
	 */
	public static function run( $action ) {
		$guard = Security::guard( 'quick/' . $action );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$registry = ModuleRegistry::instance();

		switch ( $action ) {
			case 'scan_website':
				$done = array();

				foreach ( $registry->all() as $id => $module ) {
					try {
						$result     = $module->scan( array( 'quick' => true ) );
						$done[ $id ] = (int) ( $result['score'] ?? 0 );
					} catch ( \Throwable $e ) {
						continue;
					}
				}

				return array(
					'scores'  => $done,
					/* translators: %d: number of modules */
					'message' => sprintf( __( 'Scanned %d modules.', 'omninox-site-toolkit'), count( $done ) ),
					'reload'  => true,
				);

			case 'security_scan':
				$module = $registry->get( 'security' );

				if ( ! $module ) {
					return new \WP_Error( 'omninox_no_module', __( 'The security module is not available.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
				}

				$result = $module->scan();

				return array(
					'message' => sprintf(
						/* translators: 1: score, 2: critical count */
						__( 'Security score %1$d. %2$d critical findings.', 'omninox-site-toolkit'),
						(int) $result['score'],
						(int) ( $result['summary']['critical'] ?? 0 )
					),
					'goto'    => 'security',
				);

			case 'quick_optimization':
				$module = $registry->get( 'performance' );

				if ( ! $module ) {
					return new \WP_Error( 'omninox_no_module', __( 'The performance module is not available.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
				}

				// Only the changes that cannot break a layout. Minification
				// and script deferral are deliberately excluded, because both
				// need checking page by page.
				$safe = array( 'disable_emojis', 'disable_embeds', 'remove_query_strings', 'lazy_load', 'optimize_fonts' );

				foreach ( $safe as $flag ) {
					$module->fix( $flag, array( 'value' => 1 ) );
				}

				return array(
					'message' => __( 'Applied the safe optimisations: emojis, embeds, query strings, lazy loading and font hints. Minification and script deferral were left alone because they need checking page by page.', 'omninox-site-toolkit'),
					'goto'    => 'performance',
				);

			case 'clean_database':
				$module = $registry->get( 'database' );

				if ( ! $module ) {
					return new \WP_Error( 'omninox_no_module', __( 'The database module is not available.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
				}

				// Returns a preview, not a deletion. Confirming happens in the
				// module so the person sees what they are agreeing to.
				$preview = $module->fix( 'clean_all', array() );

				return is_wp_error( $preview ) ? $preview : array_merge( $preview, array( 'goto' => 'database' ) );

			case 'optimize_images':
				$module = $registry->get( 'media' );

				if ( ! $module ) {
					return new \WP_Error( 'omninox_no_module', __( 'The media module is not available.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
				}

				$result = $module->fix( 'bulk_convert', array( 'offset' => 0, 'batch' => 10 ) );

				return is_wp_error( $result ) ? $result : array_merge( $result, array( 'goto' => 'media' ) );

			case 'generate_report':
				$report = ReportGenerator::generate( 'html', array() );

				return is_wp_error( $report ) ? $report : array_merge(
					$report,
					array( 'message' => __( 'Report ready.', 'omninox-site-toolkit') )
				);

			case 'clear_cache':
				$removed = \OmninoxSiteToolkit\Modules\Performance\Optimizations::flush_cache();

				wp_cache_flush();
				delete_transient( 'omninox_front_html' );
				delete_transient( 'omninox_asset_inventory' );

				return array(
					/* translators: %d: number of files */
					'message' => sprintf( __( 'Cleared the object cache and removed %d generated files.', 'omninox-site-toolkit'), $removed ),
				);

			case 'check_updates':
				wp_clean_update_cache();
				wp_update_plugins();
				wp_update_themes();
				wp_version_check();

				$updates = Advisor::updates();

				return array( 'message' => $updates['message'], 'updates' => $updates );

			case 'backup_now':
				$module = $registry->get( 'backup' );

				if ( ! $module ) {
					return new \WP_Error( 'omninox_no_module', __( 'The backup module is not available.', 'omninox-site-toolkit'), array( 'status' => 404 ) );
				}

				$result = $module->fix( 'backup_database', array( 'offset' => 0, 'blocking' => true ) );

				return is_wp_error( $result ) ? $result : array_merge( $result, array( 'goto' => 'backup' ) );
		}

		return new \WP_Error( 'omninox_unknown_quick_action', __( 'That quick action does not exist.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
	}
}
