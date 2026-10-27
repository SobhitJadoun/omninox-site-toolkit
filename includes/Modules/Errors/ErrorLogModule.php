<?php
/**
 * Module 12: Error Log Center.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Errors;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Database\LogRepository;
use OmninoxSiteToolkit\Database\Schema;
use OmninoxSiteToolkit\Core\Settings;

defined( 'ABSPATH' ) || exit;

class ErrorLogModule extends AbstractModule {

	protected $id   = 'errors';
	protected $icon = 'warning';

	public function get_label() {
		return __( 'Error Log', 'omninox-site-toolkit' );
	}

	/**
	 * Start collecting.
	 */
	public function register() {
		if ( ! Settings::instance()->get( 'log_errors', true ) ) {
			return;
		}

		set_error_handler( array( $this, 'handle_php_error' ), E_ALL & ~E_DEPRECATED & ~E_STRICT ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		register_shutdown_function( array( $this, 'handle_fatal' ) );

		add_action( 'template_redirect', array( $this, 'record_404' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'record_rest_error' ), 10, 3 );
		add_action( 'doing_it_wrong_run', array( $this, 'record_doing_it_wrong' ), 10, 3 );
	}

	/**
	 * Summarise recent errors.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		global $wpdb;

		$table = Schema::table( 'logs' );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
		$levels = "'fatal', 'error', 'warning'";

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT type, level, COUNT(*) AS entries, SUM(hits) AS hits FROM {$table} WHERE created_at >= %s AND level IN ({$levels}) GROUP BY type, level", $since ),
			ARRAY_A
		);

		$by_type = array();

		foreach ( (array) $rows as $row ) {
			$by_type[ $row['type'] ] = ( $by_type[ $row['type'] ] ?? 0 ) + (int) $row['hits'];
		}

		$fatals = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE level = 'fatal' AND created_at >= %s", $since )
		);

		$this->add(
			array(
				'id'       => 'fatal_errors',
				'label'    => __( 'Fatal errors in the last 7 days', 'omninox-site-toolkit'),
				'value'    => (string) $fatals,
				'expected' => '0',
				'weight'   => 4,
				'severity' => 0 === $fatals ? self::SEV_PASS : self::SEV_CRITICAL,
			)
		);

		foreach ( array(
			'php'  => array( __( 'PHP warnings', 'omninox-site-toolkit'), 50 ),
			'404'  => array( __( '404 responses', 'omninox-site-toolkit'), 100 ),
			'rest' => array( __( 'REST API errors', 'omninox-site-toolkit'), 20 ),
			'cron' => array( __( 'Cron failures', 'omninox-site-toolkit'), 5 ),
			'email' => array( __( 'Email failures', 'omninox-site-toolkit'), 1 ),
		) as $type => $meta ) {
			list( $label, $threshold ) = $meta;
			$count = (int) ( $by_type[ $type ] ?? 0 );

			$this->add(
				array(
					'id'       => 'errors_' . $type,
					'label'    => $label,
					'value'    => number_format_i18n( $count ),
					'expected' => '< ' . $threshold,
					'weight'   => 1.5,
					'severity' => $count <= $threshold ? self::SEV_PASS : self::SEV_WARNING,
				)
			);
		}

		$top = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT message, file, line, hits, type, level FROM {$table} WHERE created_at >= %s AND level IN ({$levels}) ORDER BY hits DESC LIMIT 15", $since ),
			ARRAY_A
		);

		$legacy_errors = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT message, file, line, hits FROM {$table} WHERE created_at >= %s AND level IN ({$levels}) AND (message LIKE %s OR message LIKE %s)", $since, '%ImapPoller%', '%ensure_chat_events%' ),
			ARRAY_A
		);

		if ( $legacy_errors ) {
			$hits  = array_sum( array_map( 'absint', wp_list_pluck( $legacy_errors, 'hits' ) ) );
			$files = array_values( array_unique( array_filter( wp_list_pluck( $legacy_errors, 'file' ) ) ) );

			$this->add(
				array(
					'id'          => 'legacy_chat_scheduler',
					'label'       => __( 'Legacy chat scheduler error', 'omninox-site-toolkit'),
					'value'       => number_format_i18n( $hits ),
					'expected'    => '0',
					'severity'    => self::SEV_CRITICAL,
					'description' => __( 'An old mailbox polling event is still recorded. The safe fix removes that obsolete event and its stale log entries.', 'omninox-site-toolkit'),
					'fix'         => 'remove_legacy_chat_events',
					'context'     => array( 'files' => $files ),
				)
			);
		}

		return $this->result( array( 'top_errors' => (array) $top, 'by_type' => $by_type ) );
	}

	/**
	 * Clear the log.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		if ( 'remove_legacy_chat_events' === $action ) {
			wp_clear_scheduled_hook( 'omninox_chat_expire' );
			wp_clear_scheduled_hook( 'omninox_chat_poll_mailbox' );
			$deleted = LogRepository::delete_message_patterns( array( 'ImapPoller', 'ensure_chat_events' ) );

			return array(
				'message'       => __( 'Removed the obsolete chat scheduler and its stale error records.', 'omninox-site-toolkit'),
				'changes'       => array(
					array(
						'label' => __( 'Legacy chat cron events', 'omninox-site-toolkit'),
						'from'  => __( 'Scheduled', 'omninox-site-toolkit'),
						'to'    => __( 'Cleared', 'omninox-site-toolkit'),
					),
					array(
						'label' => __( 'Matching stale error records', 'omninox-site-toolkit'),
						'from'  => (string) $deleted,
						'to'    => '0',
					),
				),
				'files_modified' => array(),
			);
		}

		if ( 'clear_log' === $action ) {
			$deleted = LogRepository::clear();

			/* translators: %d: number of entries */
			return array( 'message' => sprintf( __( 'Removed %d log entries.', 'omninox-site-toolkit'), $deleted ) );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Available actions.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			array( 'id' => 'clear_log', 'label' => __( 'Clear the log', 'omninox-site-toolkit'), 'type' => 'button' ),
		);
	}

	/**
	 * PHP error handler. Records and hands control back to PHP.
	 *
	 * @param int    $number  Error level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return bool
	 */
	public function handle_php_error( $number, $message, $file = '', $line = 0 ) {
		$levels = array(
			E_ERROR       => 'fatal',
			E_WARNING     => 'warning',
			E_NOTICE      => 'notice',
			E_USER_ERROR  => 'fatal',
			E_USER_WARNING => 'warning',
			E_USER_NOTICE => 'notice',
		);

		LogRepository::record(
			array(
				'type'    => 'php',
				'level'   => $levels[ $number ] ?? 'notice',
				'message' => $message,
				'file'    => $file,
				'line'    => $line,
				'source'  => $this->guess_source( $file ),
			)
		);

		return false;
	}

	/**
	 * Catch fatals on shutdown.
	 */
	public function handle_fatal() {
		$error = error_get_last();

		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}

		LogRepository::record(
			array(
				'type'    => 'php',
				'level'   => 'fatal',
				'message' => $error['message'],
				'file'    => $error['file'],
				'line'    => $error['line'],
				'source'  => $this->guess_source( $error['file'] ),
			)
		);
	}

	/**
	 * Record 404 hits.
	 */
	public function record_404() {
		if ( ! is_404() ) {
			return;
		}

		LogRepository::record(
			array(
				'type'    => '404',
				'level'   => 'notice',
				'message' => sprintf( /* translators: %s: request path */ __( 'Not found: %s', 'omninox-site-toolkit'), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ),
				'source'  => 'frontend',
			)
		);
	}

	/**
	 * Record REST errors.
	 *
	 * @param mixed            $response Response.
	 * @param array            $handler  Handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function record_rest_error( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			LogRepository::record(
				array(
					'type'    => 'rest',
					'level'   => 'error',
					'message' => $response->get_error_message(),
					'source'  => $request->get_route(),
				)
			);
		}

		return $response;
	}

	/**
	 * Record _doing_it_wrong calls.
	 *
	 * @param string $function Function name.
	 * @param string $message  Message.
	 * @param string $version  Version.
	 */
	public function record_doing_it_wrong( $function, $message, $version ) {
		LogRepository::record(
			array(
				'type'    => 'php',
				'level'   => 'notice',
				'message' => $function . ': ' . wp_strip_all_tags( $message ),
				'source'  => 'doing_it_wrong',
			)
		);
	}

	/**
	 * Attribute a file path to a plugin or theme.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	private function guess_source( $file ) {
		if ( preg_match( '#/(?:plugins|mu-plugins)/([^/]+)/#i', (string) $file, $m ) ) {
			return 'plugin:' . $m[1];
		}

		if ( preg_match( '#/themes/([^/]+)/#i', (string) $file, $m ) ) {
			return 'theme:' . $m[1];
		}

		return 'core';
	}
}
