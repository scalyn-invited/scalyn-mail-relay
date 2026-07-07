<?php
/**
 * Uninstall handler for Scalyn Mail Relay.
 *
 * Default policy:
 * Keep plugin data unless the administrator explicitly enabled full data removal.
 *
 * @package ScalynMailRelay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'scalyn_mail_relay_settings', array() );

$delete_all_data = false;

if (
	is_array( $settings )
	&& ! empty( $settings['advanced']['delete_data_on_uninstall'] )
	&& true === (bool) $settings['advanced']['delete_data_on_uninstall']
) {
	$delete_all_data = true;
}

if ( ! $delete_all_data ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'scalyn_mail_logs',
	$wpdb->prefix . 'scalyn_mail_timeline',
	$wpdb->prefix . 'scalyn_diagnostics',
	$wpdb->prefix . 'scalyn_health_scores',
	$wpdb->prefix . 'scalyn_alerts',
	$wpdb->prefix . 'scalyn_audit_logs',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'scalyn_mail_relay_settings' );
delete_option( 'scalyn_mail_relay_db_version' );

wp_clear_scheduled_hook( 'scalyn_mail_cleanup_logs' );
wp_clear_scheduled_hook( 'scalyn_mail_run_daily_diagnostics' );
wp_clear_scheduled_hook( 'scalyn_mail_generate_health_snapshot' );
wp_clear_scheduled_hook( 'scalyn_mail_send_alerts' );

delete_transient( 'scalyn_mail_relay_health_cache' );
delete_transient( 'scalyn_mail_relay_diagnostics_cache' );
