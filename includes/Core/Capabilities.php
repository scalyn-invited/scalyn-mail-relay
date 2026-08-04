<?php
/**
 * Plugin capabilities.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress capability constants for Scalyn Mail Relay.
 *
 * These capabilities are granted to the Administrator role on plugin activation
 * (see Lifecycle::grant_capabilities()). Future agency-tier roles will receive
 * a subset of these capabilities.
 */
final class Capabilities {
	public const VIEW_DASHBOARD  = 'scalyn_mail_relay_view_dashboard';
	public const VIEW_LOGS       = 'scalyn_mail_relay_view_logs';
	public const RUN_DIAGNOSTICS = 'scalyn_mail_relay_run_diagnostics';
	public const MANAGE_MAIL     = 'scalyn_mail_relay_manage_mail';
	public const MANAGE_SETTINGS = 'scalyn_mail_relay_manage_settings';
	public const EXPORT_REPORTS  = 'scalyn_mail_relay_export_reports';
	public const MANAGE_AGENCY   = 'scalyn_mail_relay_manage_agency';

	/**
	 * Returns all defined capability strings.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::VIEW_DASHBOARD,
			self::VIEW_LOGS,
			self::RUN_DIAGNOSTICS,
			self::MANAGE_MAIL,
			self::MANAGE_SETTINGS,
			self::EXPORT_REPORTS,
			self::MANAGE_AGENCY,
		);
	}
}
