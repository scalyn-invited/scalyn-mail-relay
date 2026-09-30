<?php
/**
 * Report form, included only after the export capability gate.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay scalyn-reports">
	<h1><?php esc_html_e( 'Reports', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Create a shareable snapshot of email activity, configuration health and diagnostic guidance.', 'scalyn-mail-relay' ); ?></p>
	<div class="scalyn-workspace-layout">
	<form class="scalyn-card scalyn-report-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<h2><?php esc_html_e( 'Build your report', 'scalyn-mail-relay' ); ?></h2>
		<input type="hidden" name="action" value="scalyn_export_report">
		<?php wp_nonce_field( 'scalyn_export_report' ); ?>
		<fieldset class="scalyn-form-section"><legend><?php esc_html_e( '1. Reporting period', 'scalyn-mail-relay' ); ?></legend>
		<p id="report-period-help">
		<?php
		// translators: %s: current WordPress timezone name.
		echo esc_html( sprintf( __( 'Choose a date and time using the calendar picker or keyboard. All times use site time (%s), not your device timezone. Start is inclusive; end is exclusive. Maximum period: 366 days. Historical timezone changes cannot be reconstructed.', 'scalyn-mail-relay' ), wp_timezone()->getName() ) );
		?>
		</p>
		<p><label for="report-start"><?php esc_html_e( 'Start', 'scalyn-mail-relay' ); ?></label><br><input id="report-start" name="start" type="datetime-local" required step="1" value="<?php echo esc_attr( $start->format( 'Y-m-d\TH:i:s' ) ); ?>" aria-describedby="report-period-help"></p>
		<p><label for="report-end"><?php esc_html_e( 'End (exclusive)', 'scalyn-mail-relay' ); ?></label><br><input id="report-end" name="end" type="datetime-local" required step="1" value="<?php echo esc_attr( $end->format( 'Y-m-d\TH:i:s' ) ); ?>" aria-describedby="report-period-help"></p>
		</fieldset><fieldset class="scalyn-form-section"><legend><?php esc_html_e( '2. Provider scope', 'scalyn-mail-relay' ); ?></legend>
		<p><label for="report-provider-scope"><?php esc_html_e( 'Mail provider scope', 'scalyn-mail-relay' ); ?></label><br><select id="report-provider-scope" name="provider_scope"><option value="all"><?php esc_html_e( 'All providers', 'scalyn-mail-relay' ); ?></option><option value="unattributed"><?php esc_html_e( 'Unattributed mail', 'scalyn-mail-relay' ); ?></option><option value="specific"><?php esc_html_e( 'Specific provider', 'scalyn-mail-relay' ); ?></option></select></p>
		<p><label for="report-provider"><?php esc_html_e( 'Provider identifier (only for Specific provider)', 'scalyn-mail-relay' ); ?></label><br><input id="report-provider" name="provider" type="text" maxlength="100" placeholder="smtp" aria-describedby="report-provider-help"></p>
		<p id="report-provider-help"><?php esc_html_e( 'Use the exact identifier, such as smtp. Health scores and diagnostics always describe the whole site, not only this provider.', 'scalyn-mail-relay' ); ?></p>
		</fieldset><fieldset class="scalyn-form-section"><legend><?php esc_html_e( '3. Format and privacy', 'scalyn-mail-relay' ); ?></legend>
		<p><label for="report-format"><?php esc_html_e( 'Download format', 'scalyn-mail-relay' ); ?></label><br><select id="report-format" name="format"><option value="csv">CSV</option><option value="json">JSON</option><option value="pdf">PDF</option></select></p>
		<p><?php esc_html_e( 'PDF provides a paginated report using the same snapshot and privacy selection. PDF labels are currently in English. CSV/JSON remain available for machine-readable data.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'CSV uses path, type and value columns to preserve all report sections and distinguish missing values from zero. JSON preserves nested sections. Each download creates a new snapshot.', 'scalyn-mail-relay' ); ?></p>
		<p><label><input type="checkbox" name="references" value="1"> <?php esc_html_e( 'Include internal evidence identifiers for troubleshooting (row IDs and message/run/score UUIDs).', 'scalyn-mail-relay' ); ?></label></p>
		<p><?php esc_html_e( 'Identifiers are excluded by default. No format includes recipients, subjects, message bodies, credentials or raw diagnostic/provider responses. Reports still contain operational metadata; share them only with authorized people. Files download directly and are not stored by the plugin.', 'scalyn-mail-relay' ); ?></p>
		</fieldset>
		<?php submit_button( __( 'Download report', 'scalyn-mail-relay' ) ); ?>
	</form>
	<aside class="scalyn-card scalyn-context-panel" aria-labelledby="scalyn-report-guide">
		<h2 id="scalyn-report-guide"><?php esc_html_e( 'What is included', 'scalyn-mail-relay' ); ?></h2>
		<ul class="scalyn-checklist"><li><?php esc_html_e( 'Retained mail activity and recent failures', 'scalyn-mail-relay' ); ?></li><li><?php esc_html_e( 'Site-wide configuration health and trends', 'scalyn-mail-relay' ); ?></li><li><?php esc_html_e( 'Diagnostic findings and recommendations', 'scalyn-mail-relay' ); ?></li></ul>
		<p><?php esc_html_e( 'One combined operational report is available. Separate deliverability, audit and system report templates are not implemented.', 'scalyn-mail-relay' ); ?></p>
		<h3><?php esc_html_e( 'Choose a format', 'scalyn-mail-relay' ); ?></h3>
		<dl><dt>PDF</dt><dd><?php esc_html_e( 'Readable report for review and sharing.', 'scalyn-mail-relay' ); ?></dd><dt>CSV</dt><dd><?php esc_html_e( 'Structured rows for spreadsheets.', 'scalyn-mail-relay' ); ?></dd><dt>JSON</dt><dd><?php esc_html_e( 'Nested data for technical analysis.', 'scalyn-mail-relay' ); ?></dd></dl>
		<h3><?php esc_html_e( 'Before sharing', 'scalyn-mail-relay' ); ?></h3>
		<p><?php esc_html_e( 'Accepted does not mean delivered or placed in an inbox. Review coverage and evidence age before using a report to make decisions.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'Export attempts are recorded in Audit History with your WordPress user ID, format and evidence-identifier choice. Audit records follow the retention period in Settings. Downloaded copies remain under your control.', 'scalyn-mail-relay' ); ?></p>
	</aside></div>
</div>
