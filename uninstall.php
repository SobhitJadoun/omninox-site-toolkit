<?php
/**
 * Uninstall routine. Only removes data when the user opted in.
 *
 * @package OmninoxSiteToolkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$omninox_settings = get_option( 'omninox_site_toolkit_settings', get_option( 'wpmt_settings', array() ) );

if ( empty( $omninox_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$omninox_tables = array(
	'omninox_scans',
	'omninox_issues',
	'omninox_logs',
	'omninox_metrics',
	'omninox_chat',
	'omninox_chat_conversations',
	'omninox_chat_messages',
	'wpmt_scans',
	'wpmt_issues',
	'wpmt_logs',
	'wpmt_metrics',
	'wpmt_chat',
	'wpmt_chat_conversations',
	'wpmt_chat_messages',
);

foreach ( $omninox_tables as $omninox_table ) {
	$safe_table = esc_sql( $wpdb->prefix . $omninox_table );
	$wpdb->query( "DROP TABLE IF EXISTS `{$safe_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$omninox_options = array(
	'omninox_site_toolkit_settings',
	'omninox_site_toolkit_db_version',
	'omninox_license',
	'omninox_white_label',
	'omninox_module_state',
	'omninox_last_scores',
	'omninox_mail_last_error',
	'omninox_value_history',
	'omninox_backup_state',
	'omninox_restore_state',
	'omninox_backup_lock',
	'omninox_blocked_ips',
	'omninox_redirects',
	'omninox_overall_weights',
	'omninox_chat_misses',
	'omninox_chat_feedback',
	'omninox_chat_tickets',
	'omninox_chat_imap_last_run',
	'wpmt_settings',
	'wpmt_db_version',
	'wpmt_license',
	'wpmt_white_label',
	'wpmt_module_state',
	'wpmt_last_scores',
	'wpmt_mail_last_error',
	'wpmt_value_history',
	'wpmt_backup_state',
	'wpmt_restore_state',
	'wpmt_backup_lock',
	'wpmt_blocked_ips',
	'wpmt_redirects',
	'wpmt_overall_weights',
	'wpmt_chat_misses',
	'wpmt_chat_feedback',
	'wpmt_chat_tickets',
	'wpmt_chat_imap_last_run',
);

foreach ( $omninox_options as $omninox_option ) {
	delete_option( $omninox_option );
}

// Clean any remaining omninox_ and wpmt_ options and transients from wp_options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'omninox_%' OR option_name LIKE '_transient_omninox_%' OR option_name LIKE '_transient_timeout_omninox_%' OR option_name LIKE 'wpmt_%' OR option_name LIKE '_transient_wpmt_%' OR option_name LIKE '_transient_timeout_wpmt_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

wp_clear_scheduled_hook( 'omninox_scheduled_scan' );
wp_clear_scheduled_hook( 'omninox_daily_maintenance' );
wp_clear_scheduled_hook( 'omninox_error_monitor' );
wp_clear_scheduled_hook( 'wpmt_scheduled_scan' );
wp_clear_scheduled_hook( 'wpmt_daily_maintenance' );
wp_clear_scheduled_hook( 'wpmt_error_monitor' );
