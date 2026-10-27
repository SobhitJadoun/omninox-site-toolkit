<?php
/**
 * Background scanning and maintenance via WP-Cron.
 *
 * @package OmninoxSiteToolkit
 */

namespace OmninoxSiteToolkit\Scheduler;

use OmninoxSiteToolkit\Traits\Singleton;
use OmninoxSiteToolkit\Core\Settings;
use OmninoxSiteToolkit\Core\ModuleRegistry;
use OmninoxSiteToolkit\Database\ScanRepository;
use OmninoxSiteToolkit\Database\LogRepository;
use OmninoxSiteToolkit\License\LicenseManager;
use OmninoxSiteToolkit\Notifications\Notifier;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	use Singleton;

	/**
	 * Hook up.
	 */
	public function boot() {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'omninox_scheduled_scan', array( $this, 'run_scheduled_scan' ) );
		add_action( 'omninox_daily_maintenance', array( $this, 'run_maintenance' ) );
		add_action( 'omninox_error_monitor', array( $this, 'monitor_errors' ) );
		add_action( 'omninox_settings_updated', array( $this, 'resync' ) );

		wp_clear_scheduled_hook( 'omninox_chat_expire' );
		wp_clear_scheduled_hook( 'omninox_chat_poll_mailbox' );
		wp_clear_scheduled_hook( 'omninox_chat_expire' );
		wp_clear_scheduled_hook( 'omninox_chat_poll_mailbox' );
		$this->monitor_errors();

		if ( ! wp_next_scheduled( 'omninox_error_monitor' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'omninox_five_minutes', 'omninox_error_monitor' );
		}
	}

	/**
	 * Add a monthly interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function schedules( $schedules ) {
		$schedules['omninox_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once monthly (Omninox Site Toolkit)', 'omninox-site-toolkit' ),
		);

		$schedules['omninox_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Omninox Site Toolkit)', 'omninox-site-toolkit' ),
		);

		return $schedules;
	}

	/**
	 * Re-schedule when the setting changes.
	 */
	public function resync() {
		$schedule = Settings::instance()->get( 'scan_schedule', 'weekly' );

		wp_clear_scheduled_hook( 'omninox_scheduled_scan' );

		if ( 'off' === $schedule || ! LicenseManager::instance()->is_premium() ) {
			return;
		}

		$map = array(
			'daily'   => 'daily',
			'weekly'  => 'weekly',
			'monthly' => 'omninox_monthly',
		);

		wp_schedule_event( time() + HOUR_IN_SECONDS, $map[ $schedule ] ?? 'weekly', 'omninox_scheduled_scan' );
	}

	/**
	 * Run the configured modules in the background.
	 */
	public function run_scheduled_scan() {
		$settings = Settings::instance();
		$registry = ModuleRegistry::instance();
		$results  = array();

		foreach ( (array) $settings->get( 'scan_modules', array() ) as $id ) {
			$module = $registry->get( $id );

			if ( ! $module || is_wp_error( LicenseManager::instance()->gate( $module ) ) ) {
				continue;
			}

			try {
				$results[ $id ] = $module->scan( array( 'background' => true ) );
			} catch ( \Throwable $e ) {
				\OmninoxSiteToolkit\Database\LogRepository::record(
					array(
						'type'    => 'cron',
						'level'   => 'error',
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
						'source'  => 'omninox/scan/' . $id,
					)
				);
			}
		}

		Notifier::instance()->maybe_notify( $results );

		do_action( 'omninox_scheduled_scan_complete', $results );
	}

	/**
	 * Nightly housekeeping.
	 */
	public function run_maintenance() {
		ScanRepository::prune( (int) Settings::instance()->get( 'retention_days', 90 ) );
		delete_transient( 'omninox_front_html' );
		delete_transient( 'omninox_front_html' );
	}

	/**
	 * Remove records created by the retired mailbox polling implementation.
	 */
	public function monitor_errors() {
		LogRepository::delete_message_patterns( array( 'ImapPoller', 'ensure_chat_events' ) );
	}
}
