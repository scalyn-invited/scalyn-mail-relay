<?php
/**
 * Uninstall handler. Data is retained unless explicit deletion was enabled.
 *
 * Two modes:
 *
 *  1. Retain (default). Nothing is removed. Settings, tables, logs, diagnostics
 *     and health scores survive an uninstall/reinstall cycle intact.
 *  2. Delete. Enabled only when advanced.delete_data_on_uninstall is true.
 *     Removes tables, options, capabilities, scheduled events and transients.
 *
 * In 0.1.0 the delete flag has no admin UI and is programmatic-only; see
 * docs/UNINSTALL-POLICY.md. The default is false, so an operator who never
 * touches the option can never lose data by uninstalling.
 *
 * Scope: single site only. On multisite this removes data for the site that
 * runs the uninstall and leaves other sites in the network untouched. See
 * docs/adr/0002-mvp-release-hardening-accepted-risks.md.
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
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

foreach ( array( 'scalyn_mail_relay_settings', 'scalyn_mail_relay_db_version', 'scalyn_mail_relay_version' ) as $option ) {
	delete_option( $option );
}

// Revoke the capabilities Lifecycle::grant_capabilities() added on activation.
// Without this, scalyn_mail_relay_* capabilities stay on the Administrator role
// permanently after the plugin and all of its data are gone.
//
// The plugin is not loaded during uninstall, so the class file is required
// directly to keep Core\Capabilities the single source of truth rather than
// duplicating the capability strings here. The autoloader is deliberately not
// used: it resolves paths through SCALYN_MAIL_RELAY_PATH, which only the main
// plugin file defines and which does not exist in the uninstall context.
$scalyn_capabilities_file = __DIR__ . '/includes/Core/Capabilities.php';

if ( is_readable( $scalyn_capabilities_file ) ) {
	require_once $scalyn_capabilities_file;

	$scalyn_roles = wp_roles();

	foreach ( array_keys( $scalyn_roles->get_names() ) as $scalyn_role_name ) {
		$scalyn_role = get_role( $scalyn_role_name );

		if ( ! $scalyn_role ) {
			continue;
		}

		foreach ( \Scalyn\MailRelay\Core\Capabilities::all() as $scalyn_capability ) {
			$scalyn_role->remove_cap( $scalyn_capability );
		}
	}
}

// Must stay in sync with Lifecycle::cron_hooks(). That method is private and the
// plugin is not loaded here, so the list is repeated rather than shared. It is
// the full set of hook names the plugin has ever scheduled, so events left by an
// older install are cleared too.
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
