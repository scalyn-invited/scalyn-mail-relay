<?php
/**
 * Plugin capabilities.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	public const VIEW_DASHBOARD   = 'scalyn_mail_relay_view_dashboard';
	public const VIEW_LOGS        = 'scalyn_mail_relay_view_logs';
	public const RUN_DIAGNOSTICS  = 'scalyn_mail_relay_run_diagnostics';
	public const MANAGE_MAIL      = 'scalyn_mail_relay_manage_mail';
	public const MANAGE_SETTINGS  = 'scalyn_mail_relay_manage_settings';
	public const EXPORT_REPORTS   = 'scalyn_mail_relay_export_reports';
	public const MANAGE_AGENCY    = 'scalyn_mail_relay_manage_agency';

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
