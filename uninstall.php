<?php
/**
 * Uninstall handler. Data is retained unless explicit deletion was enabled.
 *
 * @package ScalynMailRelay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'scalyn_mail_relay_settings', array() );
$delete   = is_array( $settings )
	&& isset( $settings['advanced']['delete_data_on_uninstall'] )
	&& true === (bool) $settings['advanced']['delete_data_on_uninstall'];

if ( ! $delete ) {
	return;
}

global $wpdb;

$owned_tables = array(
	'scalyn_mail_logs',
	'scalyn_mail_timeline',
	'scalyn_diagnostics',
	'scalyn_health_scores',
	'scalyn_alerts',
	'scalyn_audit_logs',
);

foreach ( $owned_tables as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	// Table names cannot be parameterized; suffixes are fixed internal constants above.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

foreach ( array( 'scalyn_mail_relay_settings', 'scalyn_mail_relay_db_version', 'scalyn_mail_relay_version' ) as $option ) {
	delete_option( $option );
}

foreach ( array(
	'scalyn_mail_relay_cleanup_logs',
	'scalyn_mail_relay_run_daily_diagnostics',
	'scalyn_mail_relay_generate_health_snapshot',
	'scalyn_mail_relay_send_alerts',
) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

delete_transient( 'scalyn_mail_relay_health_cache' );
delete_transient( 'scalyn_mail_relay_diagnostics_cache' );
