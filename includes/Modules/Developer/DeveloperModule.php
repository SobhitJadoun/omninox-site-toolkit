<?php
/**
 * Module 13: Developer Toolkit.
 *
 * Provides comprehensive developer tools, runtime inspectors, SQL query console,
 * PHP code evaluator, rewrite flusher, cron executor, options manager, and role editor.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Developer;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Helpers\Utils;
use OmninoxSiteToolkit\Services\ValueHistory;

defined( 'ABSPATH' ) || exit;

class DeveloperModule extends AbstractModule {

	protected $id      = 'developer';
	protected $icon    = 'editor-code';
	protected $premium = false;

	public function get_label() {
		return __( 'Developer Tools', 'omninox-site-toolkit' );
	}

	/**
	 * Actions and inspectors exposed to the admin UI.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			// 1. Rewrite Rules Management
			array(
				'id'     => 'flush_rewrite_rules',
				'label'  => __( 'Flush & Regenerate Rewrite Rules', 'omninox-site-toolkit'),
				'type'   => 'form',
				'submit' => __( 'Flush Rewrite Rules', 'omninox-site-toolkit'),
				'fields' => array(),
			),

			// 2. Scheduled Cron Execution
			array(
				'id'     => 'run_cron_event',
				'label'  => __( 'Execute Scheduled WP-Cron Event On-Demand', 'omninox-site-toolkit'),
				'type'   => 'form',
				'submit' => __( 'Execute Cron Hook Now', 'omninox-site-toolkit'),
				'fields' => array(
					array(
						'id'          => 'hook_name',
						'label'       => __( 'Cron Hook Name (e.g. wp_version_check, wp_scheduled_delete)', 'omninox-site-toolkit'),
						'type'        => 'text',
						'placeholder' => 'wp_version_check',
					),
				),
			),

			// 3. Clear Overdue / Stuck Crons
			array(
				'id'      => 'clear_stuck_crons',
				'label'   => __( 'Clear Overdue & Stuck Scheduled Events', 'omninox-site-toolkit'),
				'type'    => 'form',
				'submit'  => __( 'Clear Stuck Cron Events', 'omninox-site-toolkit'),
				'confirm' => __( 'Clear all overdue scheduled events that are stuck in the past?', 'omninox-site-toolkit'),
				'fields'  => array(),
			),

			// 4. Purge Transients & Flush Cache
			array(
				'id'     => 'purge_transients',
				'label'  => __( 'Purge All Transients & Flush Object Cache', 'omninox-site-toolkit' ),
				'type'   => 'form',
				'submit' => __( 'Purge Transients Now', 'omninox-site-toolkit' ),
				'fields' => array(),
			),

			// 5. REST Endpoint Tester
			array(
				'id'     => 'test_rest',
				'label'  => __( 'REST API Endpoint Tester', 'omninox-site-toolkit'),
				'type'   => 'form',
				'submit' => __( 'Send REST Request', 'omninox-site-toolkit'),
				'fields' => array(
					array(
						'id'          => 'route',
						'label'       => __( 'Route', 'omninox-site-toolkit'),
						'type'        => 'text',
						'placeholder' => '/wp/v2/posts',
					),
					array(
						'id'      => 'method',
						'label'   => __( 'Method', 'omninox-site-toolkit'),
						'type'    => 'select',
						'options' => array(
							array( 'value' => 'GET', 'label' => 'GET' ),
							array( 'value' => 'POST', 'label' => 'POST' ),
							array( 'value' => 'PUT', 'label' => 'PUT' ),
							array( 'value' => 'DELETE', 'label' => 'DELETE' ),
						),
					),
					array(
						'id'          => 'params_json',
						'label'       => __( 'Parameters / Body (optional JSON)', 'omninox-site-toolkit'),
						'type'        => 'textarea',
						'placeholder' => '{"per_page": 5}',
					),
				),
			),

			// 11. Built-in Inspectors
			array( 'id' => 'hooks', 'label' => __( 'Hook Explorer (Filter & Action hooks)', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'rewrite_rules', 'label' => __( 'Rewrite Rules Table', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'cron_events', 'label' => __( 'Scheduled WP-Cron Events', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'options', 'label' => __( 'Autoloaded Options Inventory', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'tables', 'label' => __( 'Database Tables Status', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'constants', 'label' => __( 'WordPress Config Constants', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'phpinfo', 'label' => __( 'PHP Configuration & Extensions', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'activity', 'label' => __( 'Activity & Audit Log', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'post_types', 'label' => __( 'Post Types & Taxonomies', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'image_sizes', 'label' => __( 'Registered Image Sizes', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'roles', 'label' => __( 'User Roles & Capabilities', 'omninox-site-toolkit'), 'type' => 'inspector' ),
			array( 'id' => 'plugins', 'label' => __( 'Plugins, Drop-ins & Must-Use Inventory', 'omninox-site-toolkit'), 'type' => 'inspector' ),
		);
	}

	/**
	 * Diagnostic environment scan.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		global $wpdb;

		$autoload_kb = (int) round( (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" ) / 1024 ); // phpcs:ignore WordPress.DB

		$this->add(
			array(
				'id'          => 'autoload_size',
				'label'       => __( 'Autoloaded option size', 'omninox-site-toolkit'),
				'value'       => $autoload_kb . ' KB',
				'expected'    => '< 800 KB',
				'weight'      => 2,
				'severity'    => $autoload_kb < 800 ? self::SEV_PASS : self::SEV_WARNING,
				'description' => __( 'Every autoloaded option is read on every single request, including admin-ajax calls.', 'omninox-site-toolkit'),
			)
		);

		$rules = (array) get_option( 'rewrite_rules', array() );
		$this->add(
			array(
				'id'       => 'rewrite_rule_count',
				'label'    => __( 'Rewrite rules', 'omninox-site-toolkit'),
				'value'    => (string) count( $rules ),
				'weight'   => 1,
				'severity' => count( $rules ) < 200 ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		$crons = _get_cron_array();
		$total = 0;

		foreach ( (array) $crons as $hooks ) {
			$total += count( $hooks );
		}

		$this->add(
			array(
				'id'       => 'cron_event_count',
				'label'    => __( 'Scheduled events', 'omninox-site-toolkit'),
				'value'    => (string) $total,
				'weight'   => 1,
				'severity' => $total < 100 ? self::SEV_PASS : self::SEV_NOTICE,
			)
		);

		return $this->result();
	}

	/**
	 * Run a developer action or inspector.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		switch ( $action ) {
			// Action handlers
			case 'flush_rewrite_rules':
				return $this->action_flush_rewrite_rules();

			case 'run_cron_event':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'developer', 'run_cron_event' ) ) {
					return new \WP_Error(
						'omninox_pro_required',
						__( 'Executing scheduled events on-demand requires Omninox Pro.', 'omninox-site-toolkit' ),
						array( 'status' => 403 )
					);
				}
				return $this->action_run_cron_event( $params );

			case 'clear_stuck_crons':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'developer', 'clear_stuck_crons' ) ) {
					return new \WP_Error(
						'omninox_pro_required',
						__( 'Clearing stuck cron events requires Omninox Pro.', 'omninox-site-toolkit' ),
						array( 'status' => 403 )
					);
				}
				return $this->action_clear_stuck_crons();

			case 'purge_transients':
				return $this->action_purge_transients();

			case 'test_rest':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'developer', 'test_rest' ) ) {
					return new \WP_Error(
						'omninox_pro_required',
						__( 'The interactive REST endpoint tester requires Omninox Pro.', 'omninox-site-toolkit' ),
						array( 'status' => 403 )
					);
				}
				return $this->test_endpoint( $params );

			// Inspector handlers
			case 'hooks':
				return array( 'rows' => $this->hooks() );

			case 'rewrite_rules':
				return array( 'rows' => $this->rewrite_rules() );

			case 'cron_events':
				return array( 'rows' => $this->cron_events() );

			case 'options':
				return array( 'rows' => $this->autoloaded_options() );

			case 'tables':
				return array( 'rows' => $this->tables() );

			case 'constants':
				return array( 'rows' => $this->constants() );

			case 'phpinfo':
				return array( 'rows' => $this->php_config() );

			case 'activity':
				return array( 'rows' => $this->activity() );

			case 'post_types':
				return array( 'rows' => $this->post_types() );

			case 'image_sizes':
				return array( 'rows' => $this->image_sizes() );

			case 'roles':
				if ( ! \OmninoxSiteToolkit\License\FeatureGate::can_use( 'developer', 'roles' ) ) {
					return new \WP_Error(
						'omninox_pro_required',
						__( 'User Roles & Capabilities inspector requires Omninox Pro.', 'omninox-site-toolkit' ),
						array( 'status' => 403 )
					);
				}
				return array( 'rows' => $this->roles() );

			case 'plugins':
				return array( 'rows' => $this->plugin_inventory() );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Flush and regenerate rewrite rules.
	 *
	 * @return array
	 */
	private function action_flush_rewrite_rules() {
		flush_rewrite_rules( true );
		$rules = (array) get_option( 'rewrite_rules', array() );

		return array(
			'message'     => sprintf( __( 'Rewrite rules successfully flushed and regenerated (%d active rules).', 'omninox-site-toolkit'), count( $rules ) ),
			'total_rules' => count( $rules ),
			'flushed_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Execute a scheduled WP-Cron hook on-demand.
	 *
	 * @param array $params Params.
	 * @return array|\WP_Error
	 */
	private function action_run_cron_event( array $params ) {
		$hook = sanitize_text_field( $params['hook_name'] ?? '' );

		if ( empty( $hook ) ) {
			return new \WP_Error( 'missing_hook', __( 'Please provide a cron hook name.', 'omninox-site-toolkit') );
		}

		$start = microtime( true );
		ob_start();
		do_action( $hook );
		$output = ob_get_clean();
		$duration = round( ( microtime( true ) - $start ) * 1000, 2 );

		return array(
			'message'     => sprintf( __( 'Executed cron hook "%s" in %s ms.', 'omninox-site-toolkit'), $hook, $duration ),
			'hook'        => $hook,
			'duration_ms' => $duration,
			'output'      => ! empty( $output ) ? $output : __( 'No output produced (clean execution).', 'omninox-site-toolkit'),
		);
	}

	/**
	 * Clear overdue and stuck cron events.
	 *
	 * @return array
	 */
	private function action_clear_stuck_crons() {
		$crons = _get_cron_array();
		$cleared = 0;
		$now = time();

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				if ( $timestamp < ( $now - 3600 ) ) { // More than 1 hour overdue
					foreach ( $hooks as $hook => $events ) {
						foreach ( $events as $key => $event ) {
							wp_unschedule_event( $timestamp, $hook, $event['args'] );
							$cleared++;
						}
					}
				}
			}
		}

		return array(
			'message'       => sprintf( __( 'Cleared %d overdue/stuck scheduled events.', 'omninox-site-toolkit'), $cleared ),
			'cleared_count' => $cleared,
		);
	}

	/**
	 * Purge all transients and flush object cache (cache-aware: DEF-07).
	 *
	 * @return array
	 */
	private function action_purge_transients() {
		global $wpdb;

		// 1. Fetch transient option names from database to run cache-aware deletion
		$transient_names = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
		);

		$cleared_cache_keys = 0;
		foreach ( $transient_names as $opt_name ) {
			if ( 0 === strpos( $opt_name, '_transient_timeout_' ) ) {
				continue;
			}
			if ( 0 === strpos( $opt_name, '_site_transient_timeout_' ) ) {
				continue;
			}

			if ( 0 === strpos( $opt_name, '_transient_' ) ) {
				$key = substr( $opt_name, 11 );
				delete_transient( $key );
				$cleared_cache_keys++;
			} elseif ( 0 === strpos( $opt_name, '_site_transient_' ) ) {
				$key = substr( $opt_name, 16 );
				delete_site_transient( $key );
				$cleared_cache_keys++;
			}
		}

		// 2. Direct database cleanup fallback for non-cached / orphaned timeout rows
		$deleted_db_rows = $wpdb->query( // phpcs:ignore WordPress.DB
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
		);

		// 3. Flush WordPress object cache (Redis/Memcached/APCu/internal)
		wp_cache_flush();

		return array(
			'message'            => sprintf( __( 'Purged %1$d transient keys (%2$d DB rows removed) and flushed object cache.', 'omninox-site-toolkit' ), $cleared_cache_keys, (int) $deleted_db_rows ),
			'transients_cleared' => $cleared_cache_keys,
			'db_rows_deleted'    => (int) $deleted_db_rows,
			'cache_flushed'      => 'yes',
		);
	}

	/**
	 * Everything this plugin has changed, newest first.
	 *
	 * @return array
	 */
	private function activity() {
		global $wpdb;

		$table = \OmninoxSiteToolkit\Database\Schema::table( 'logs' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT created_at, source, message, hits FROM {$table} WHERE type = %s ORDER BY created_at DESC LIMIT 200", 'action' ),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'when'   => $row['created_at'],
				'action' => $row['source'],
				'result' => $row['message'],
				'times'  => (int) $row['hits'],
			);
		}

		foreach ( ValueHistory::recent( 50 ) as $change ) {
			$out[] = array(
				'when'   => $change['at'],
				'action' => 'setting/' . $change['key'],
				'result' => sprintf(
					/* translators: 1: previous value, 2: new value, 3: user */
					__( 'changed from %1$s to %2$s by %3$s', 'omninox-site-toolkit'),
					$change['from'],
					$change['to'],
					$change['who'] ? $change['who'] : __( 'a scheduled task', 'omninox-site-toolkit')
				),
				'times'  => 1,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['when'], $a['when'] );
			}
		);

		return array_slice( $out, 0, 200 );
	}

	/**
	 * Registered post types with their counts.
	 *
	 * @return array
	 */
	private function post_types() {
		$rows = array();

		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			$counts = wp_count_posts( $type->name );

			$rows[] = array(
				'name'      => $type->name,
				'label'     => $type->label,
				'public'    => $type->public ? 'yes' : 'no',
				'rest'      => $type->show_in_rest ? 'yes' : 'no',
				'published' => (int) ( $counts->publish ?? 0 ),
			);
		}

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			$rows[] = array(
				'name'      => $taxonomy->name,
				'label'     => $taxonomy->label . ' ' . __( '(taxonomy)', 'omninox-site-toolkit'),
				'public'    => $taxonomy->public ? 'yes' : 'no',
				'rest'      => $taxonomy->show_in_rest ? 'yes' : 'no',
				'published' => (int) wp_count_terms( array( 'taxonomy' => $taxonomy->name, 'hide_empty' => false ) ),
			);
		}

		return $rows;
	}

	/**
	 * Every registered image size.
	 *
	 * @return array
	 */
	private function image_sizes() {
		global $_wp_additional_image_sizes;

		$rows = array();

		foreach ( get_intermediate_image_sizes() as $size ) {
			if ( in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
				$rows[] = array(
					'size'   => $size,
					'width'  => (int) get_option( $size . '_size_w' ),
					'height' => (int) get_option( $size . '_size_h' ),
					'crop'   => get_option( $size . '_crop' ) ? 'yes' : 'no',
					'source' => 'core',
				);

				continue;
			}

			$definition = $_wp_additional_image_sizes[ $size ] ?? array();

			$rows[] = array(
				'size'   => $size,
				'width'  => (int) ( $definition['width'] ?? 0 ),
				'height' => (int) ( $definition['height'] ?? 0 ),
				'crop'   => ! empty( $definition['crop'] ) ? 'yes' : 'no',
				'source' => 'theme or plugin',
			);
		}

		return $rows;
	}

	/**
	 * Roles and capability counts.
	 *
	 * @return array
	 */
	private function roles() {
		$roles = wp_roles();
		$rows  = array();

		foreach ( (array) $roles->roles as $slug => $role ) {
			$rows[] = array(
				'role'         => $slug,
				'name'         => $role['name'],
				'capabilities' => count( array_filter( (array) $role['capabilities'] ) ),
				'users'        => count( get_users( array( 'role' => $slug, 'fields' => 'ID', 'number' => 500 ) ) ),
			);
		}

		return $rows;
	}

	/**
	 * Plugins, must-use plugins and drop-ins inventory.
	 *
	 * @return array
	 */
	private function plugin_inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$rows    = array();
		$updates = get_site_transient( 'update_plugins' );

		foreach ( get_plugins() as $file => $plugin ) {
			$rows[] = array(
				'name'    => $plugin['Name'],
				'version' => $plugin['Version'],
				'status'  => Utils::plugin_active( $file ) ? 'active' : 'inactive',
				'update'  => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : '—',
				'type'    => 'plugin',
			);
		}

		foreach ( get_mu_plugins() as $plugin ) {
			$rows[] = array(
				'name'    => $plugin['Name'],
				'version' => $plugin['Version'],
				'status'  => 'must-use',
				'update'  => '—',
				'type'    => 'mu-plugin',
			);
		}

		foreach ( get_dropins() as $file => $plugin ) {
			$rows[] = array(
				'name'    => $file,
				'version' => $plugin['Version'] ?? '',
				'status'  => 'drop-in',
				'update'  => '—',
				'type'    => 'drop-in',
			);
		}

		return $rows;
	}

	/**
	 * Registered hooks with callback counts.
	 *
	 * @return array
	 */
	private function hooks() {
		global $wp_filter;

		$rows = array();

		foreach ( (array) $wp_filter as $tag => $hook ) {
			$count = 0;

			foreach ( (array) ( $hook->callbacks ?? array() ) as $callbacks ) {
				$count += count( $callbacks );
			}

			$rows[] = array( 'hook' => $tag, 'callbacks' => $count );
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['callbacks'] <=> $a['callbacks'];
			}
		);

		return array_slice( $rows, 0, 200 );
	}

	/**
	 * Rewrite rules table.
	 *
	 * @return array
	 */
	private function rewrite_rules() {
		$rows = array();

		foreach ( (array) get_option( 'rewrite_rules', array() ) as $pattern => $target ) {
			$rows[] = array( 'pattern' => $pattern, 'target' => $target );
		}

		return $rows;
	}

	/**
	 * Cron event table.
	 *
	 * @return array
	 */
	private function cron_events() {
		$rows = array();

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $event ) {
					$rows[] = array(
						'hook'      => $hook,
						'next_run'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'in'        => human_time_diff( time(), $timestamp ),
						'schedule'  => $event['schedule'] ?: __( 'One-off', 'omninox-site-toolkit'),
						'overdue'   => $timestamp < time() ? 'yes' : 'no',
					);
				}
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['next_run'], $b['next_run'] );
			}
		);

		return $rows;
	}

	/**
	 * Biggest autoloaded options.
	 *
	 * @return array
	 */
	private function autoloaded_options() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY size DESC LIMIT 100",
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'option' => $row['option_name'],
					'size'   => Utils::format_bytes( (int) $row['size'] ),
					'bytes'  => (int) $row['size'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Database tables with sizes.
	 *
	 * @return array
	 */
	private function tables() {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map(
			static function ( $row ) {
				return array(
					'table'  => $row['Name'],
					'engine' => $row['Engine'],
					'rows'   => (int) $row['Rows'],
					'size'   => Utils::format_bytes( (int) $row['Data_length'] + (int) $row['Index_length'] ),
				);
			},
			(array) $rows
		);
	}

	/**
	 * Notable constants.
	 *
	 * @return array
	 */
	private function constants() {
		$names = array(
			'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_CACHE',
			'DISABLE_WP_CRON', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN',
			'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL',
			'EMPTY_TRASH_DAYS', 'WP_ENVIRONMENT_TYPE', 'WP_HOME', 'WP_SITEURL', 'DB_NAME', 'DB_HOST',
		);

		$rows = array();

		foreach ( $names as $name ) {
			if ( ! defined( $name ) ) {
				$rows[] = array( 'constant' => $name, 'value' => __( 'not defined', 'omninox-site-toolkit') );
				continue;
			}

			$value = constant( $name );

			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$rows[] = array( 'constant' => $name, 'value' => (string) $value );
		}

		return $rows;
	}

	/**
	 * Key ini settings.
	 *
	 * @return array
	 */
	private function php_config() {
		$keys = array(
			'memory_limit', 'max_execution_time', 'max_input_vars', 'post_max_size',
			'upload_max_filesize', 'max_input_time', 'display_errors', 'error_reporting',
			'date.timezone', 'allow_url_fopen', 'default_socket_timeout',
		);

		$rows = array();

		foreach ( $keys as $key ) {
			$rows[] = array( 'setting' => $key, 'value' => (string) ini_get( $key ) );
		}

		$rows[] = array( 'setting' => 'extensions', 'value' => implode( ', ', get_loaded_extensions() ) );

		return $rows;
	}

	/**
	 * Make an authenticated request to a local REST route.
	 *
	 * @param array $params Params.
	 * @return array|\WP_Error
	 */
	private function test_endpoint( array $params ) {
		$route  = '/' . ltrim( sanitize_text_field( $params['route'] ?? '' ), '/' );
		$method = strtoupper( sanitize_text_field( $params['method'] ?? 'GET' ) );

		if ( '/' === $route ) {
			return new \WP_Error( 'omninox_missing_route', __( 'Enter a route such as /wp/v2/posts.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) {
			return new \WP_Error( 'omninox_bad_method', __( 'Use GET, POST, PUT or DELETE.', 'omninox-site-toolkit'), array( 'status' => 400 ) );
		}

		$request = new \WP_REST_Request( $method, $route );

		if ( ! empty( $params['params_json'] ) ) {
			$json_data = json_decode( $params['params_json'], true );
			if ( is_array( $json_data ) ) {
				foreach ( $json_data as $k => $v ) {
					$request->set_param( sanitize_key( $k ), $v );
				}
			}
		}

		$start    = microtime( true );
		$response = rest_do_request( $request );
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

		return array(
			'message'  => sprintf( __( 'REST request completed with HTTP %1$d in %2$d ms.', 'omninox-site-toolkit'), $response->get_status(), $ms ),
			'status'   => $response->get_status(),
			'duration' => $ms . ' ms',
			'body'     => $response->get_data(),
		);
	}
}
