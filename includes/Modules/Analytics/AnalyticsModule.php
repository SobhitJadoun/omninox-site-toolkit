<?php
/**
 * Analytics Center: how this site has moved over time rather than how it
 * looks right now.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Modules\Analytics;

use OmninoxSiteToolkit\Abstracts\AbstractModule;
use OmninoxSiteToolkit\Database\Schema;
use OmninoxSiteToolkit\Database\ScanRepository;
use OmninoxSiteToolkit\Helpers\Utils;

defined( 'ABSPATH' ) || exit;

class AnalyticsModule extends AbstractModule {

	protected $id   = 'analytics';
	protected $icon = 'chart-area';

	public function get_label() {
		return __( 'Analytics', 'omninox-site-toolkit' );
	}

	/**
	 * Record size metrics once a day so the growth charts have data.
	 */
	public function register() {
		add_action( 'omninox_daily_maintenance', array( $this, 'record_growth' ) );
	}

	/**
	 * Actions.
	 *
	 * @return array
	 */
	public function get_actions() {
		return array(
			array(
				'id'     => 'snapshot',
				'label'  => __( 'Record a size snapshot now', 'omninox-site-toolkit'),
				'type'   => 'button',
				'notice' => __( 'Snapshots are taken automatically once a day. The growth charts need at least two before they can show a line.', 'omninox-site-toolkit'),
			),
			array( 'id' => 'statistics', 'label' => __( 'Lifetime statistics', 'omninox-site-toolkit'), 'type' => 'inspector' ),
		);
	}

	/**
	 * Trends and totals.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public function scan( array $args = array() ) {
		$max_days = \OmninoxSiteToolkit\License\FeatureGate::can_use( 'analytics', 'history_90_days' ) ? 90 : 7;
		$days     = min( $max_days, absint( $args['days'] ?? $max_days ) );

		$trends = array(
			'health'      => __( 'Website health', 'omninox-site-toolkit'),
			'performance' => __( 'Performance', 'omninox-site-toolkit'),
			'security'    => __( 'Security', 'omninox-site-toolkit'),
			'seo'         => __( 'SEO', 'omninox-site-toolkit'),
		);

		$charts = array();

		foreach ( $trends as $module => $label ) {
			$history = ScanRepository::history( $module, $days );

			$charts[] = array(
				'id'    => 'trend_' . $module,
				'type'  => 'line',
				/* translators: %s: module name */
				'title' => sprintf( __( '%s score trend', 'omninox-site-toolkit'), $label ),
				'data'  => $history,
			);

			$direction = $this->direction( $history );

			$this->add(
				array(
					'id'          => 'trend_' . $module,
					/* translators: %s: module name */
					'label'       => sprintf( __( '%s trend', 'omninox-site-toolkit'), $label ),
					'value'       => $direction['label'],
					'weight'      => 1,
					'severity'    => $direction['severity'],
					'description' => $direction['description'],
					'context'     => array( 'points' => count( $history ) ),
				)
			);
		}

		$charts[] = array(
			'id'    => 'db_growth',
			'type'  => 'line',
			'title' => __( 'Database size over time (MB)', 'omninox-site-toolkit'),
			'data'  => $this->metric_series( 'size.database', $days ),
		);

		$charts[] = array(
			'id'    => 'storage_growth',
			'type'  => 'line',
			'title' => __( 'Uploads size over time (MB)', 'omninox-site-toolkit'),
			'data'  => $this->metric_series( 'size.uploads', $days ),
		);

		$charts[] = array(
			'id'    => 'error_frequency',
			'type'  => 'bar',
			'title' => __( 'Errors logged by type, last 30 days', 'omninox-site-toolkit'),
			'data'  => $this->error_frequency(),
		);

		$charts[] = array(
			'id'    => 'scan_activity',
			'type'  => 'bar',
			'title' => __( 'Scans run per module, last 30 days', 'omninox-site-toolkit'),
			'data'  => $this->scan_activity(),
		);

		$stats = $this->statistics();

		foreach ( $stats as $row ) {
			$this->add(
				array(
					'id'       => 'stat_' . sanitize_key( $row['metric'] ),
					'label'    => $row['metric'],
					'value'    => (string) $row['value'],
					'weight'   => 0.5,
					'severity' => self::SEV_PASS,
				)
			);
		}

		return $this->result( array( 'charts' => $charts, 'statistics' => $stats ) );
	}

	/**
	 * Handle actions.
	 *
	 * @param string $action Action.
	 * @param array  $params Params.
	 * @return array|\WP_Error
	 */
	public function fix( $action, array $params = array() ) {
		if ( 'snapshot' === $action ) {
			$this->record_growth();

			return array( 'message' => __( 'Snapshot recorded.', 'omninox-site-toolkit') );
		}

		if ( 'statistics' === $action ) {
			return array( 'rows' => $this->statistics() );
		}

		return parent::fix( $action, $params );
	}

	/**
	 * Store today's database and uploads size.
	 */
	public function record_growth() {
		global $wpdb;

		$db = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()'
		);

		$uploads = wp_upload_dir();
		$files   = Utils::dir_size( $uploads['basedir'], 30000 );

		$this->store( 'size.database', round( $db / 1048576, 2 ) );
		$this->store( 'size.uploads', round( $files['size'] / 1048576, 2 ) );
	}

	/**
	 * Write one metric point.
	 *
	 * @param string $metric Metric name.
	 * @param float  $value  Value.
	 */
	private function store( $metric, $value ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'metrics' ),
			array(
				'metric'      => $metric,
				'value'       => $value,
				'recorded_at' => current_time( 'mysql' ),
			),
			array( '%s', '%f', '%s' )
		);
	}

	/**
	 * Read a metric series.
	 *
	 * @param string $metric Metric name.
	 * @param int    $days   Window.
	 * @return array
	 */
	private function metric_series( $metric, $days ) {
		global $wpdb;

		$table = Schema::table( 'metrics' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT value, recorded_at FROM {$table} WHERE metric = %s AND recorded_at >= %s ORDER BY recorded_at ASC",
				$metric,
				gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array( 'x' => $row['recorded_at'], 'y' => (float) $row['value'] );
			},
			(array) $rows
		);
	}

	/**
	 * Describe which way a series is heading.
	 *
	 * @param array $series Points.
	 * @return array
	 */
	private function direction( array $series ) {
		if ( count( $series ) < 2 ) {
			return array(
				'label'       => __( 'Not enough history yet', 'omninox-site-toolkit'),
				'severity'    => self::SEV_NOTICE,
				'description' => __( 'Two or more scans are needed before a direction means anything.', 'omninox-site-toolkit'),
			);
		}

		$first = (float) $series[0]['y'];
		$last  = (float) $series[ count( $series ) - 1 ]['y'];
		$delta = round( $last - $first );

		if ( abs( $delta ) < 3 ) {
			return array(
				'label'       => sprintf( __( 'Steady at %d', 'omninox-site-toolkit'), (int) $last ),
				'severity'    => self::SEV_PASS,
				'description' => '',
			);
		}

		if ( $delta > 0 ) {
			return array(
				/* translators: 1: points gained, 2: current score */
				'label'       => sprintf( __( 'Up %1$d points to %2$d', 'omninox-site-toolkit'), $delta, (int) $last ),
				'severity'    => self::SEV_PASS,
				'description' => '',
			);
		}

		return array(
			/* translators: 1: points lost, 2: current score */
			'label'       => sprintf( __( 'Down %1$d points to %2$d', 'omninox-site-toolkit'), abs( $delta ), (int) $last ),
			'severity'    => abs( $delta ) > 15 ? self::SEV_WARNING : self::SEV_NOTICE,
			'description' => __( 'Something changed on the site between these scans. Compare the two most recent results for this module to see which check moved.', 'omninox-site-toolkit'),
		);
	}

	/**
	 * Errors grouped by type.
	 *
	 * @return array
	 */
	private function error_frequency() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT type, SUM(hits) AS total FROM ' . Schema::table( 'logs' ) . ' WHERE created_at >= %s GROUP BY type ORDER BY total DESC',
				gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array( 'label' => $row['type'], 'value' => (int) $row['total'] );
			},
			(array) $rows
		);
	}

	/**
	 * Scans per module.
	 *
	 * @return array
	 */
	private function scan_activity() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT module, COUNT(*) AS total FROM ' . Schema::table( 'scans' ) . ' WHERE created_at >= %s GROUP BY module ORDER BY total DESC',
				gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array( 'label' => $row['module'], 'value' => (int) $row['total'] );
			},
			(array) $rows
		);
	}

	/**
	 * Lifetime counters.
	 *
	 * @return array
	 */
	private function statistics() {
		global $wpdb;

		$scans_table  = esc_sql( Schema::table( 'scans' ) );
		$issues_table = esc_sql( Schema::table( 'issues' ) );
		$logs_table   = esc_sql( Schema::table( 'logs' ) );

		$scans  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$scans_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$issues_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$logs   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits), 0) FROM `{$logs_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fixes  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$logs_table}` WHERE type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'action'
			)
		);

		$uploads = wp_upload_dir();
		$reports = (array) glob( trailingslashit( $uploads['basedir'] ) . 'omninox-site-toolkit/reports/*' );
		$backups = (array) glob( trailingslashit( $uploads['basedir'] ) . 'omninox-site-toolkit/backups/*.zip' );

		$saved = 0;

		foreach ( get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => 2000, 'fields' => 'ids', 'meta_key' => '_omninox_webp' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$record = (array) get_post_meta( $id, '_omninox_webp', true );
			$saved += (int) ( $record['saved'] ?? 0 );
		}

		return array(
			array( 'metric' => __( 'Scans run', 'omninox-site-toolkit'), 'value' => number_format_i18n( $scans ) ),
			array( 'metric' => __( 'Issues recorded', 'omninox-site-toolkit'), 'value' => number_format_i18n( $issues ) ),
			array( 'metric' => __( 'Actions performed', 'omninox-site-toolkit'), 'value' => number_format_i18n( $fixes ) ),
			array( 'metric' => __( 'Log entries', 'omninox-site-toolkit'), 'value' => number_format_i18n( $logs ) ),
			array( 'metric' => __( 'Reports generated', 'omninox-site-toolkit'), 'value' => number_format_i18n( count( $reports ) ) ),
			array( 'metric' => __( 'Backups stored', 'omninox-site-toolkit'), 'value' => number_format_i18n( count( $backups ) ) ),
			array( 'metric' => __( 'Image bytes saved', 'omninox-site-toolkit'), 'value' => Utils::format_bytes( $saved ) ),
		);
	}
}
