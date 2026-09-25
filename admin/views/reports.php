<?php
/**
 * Report form, included only after the export capability gate.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay">
	<h1><?php esc_html_e( 'Reports', 'scalyn-mail-relay' ); ?></h1>
	<p><?php esc_html_e( 'Download a consistent snapshot of retained mail activity, site-wide configuration health and diagnostic guidance. Accepted does not mean delivered or placed in an inbox.', 'scalyn-mail-relay' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="scalyn_export_report">
		<?php wp_nonce_field( 'scalyn_export_report' ); ?>
		<p id="report-period-help">
		<?php
		// translators: %s: current WordPress timezone name.
		echo esc_html( sprintf( __( 'Use YYYY-MM-DD HH:MM:SS in site time (%s). Start is inclusive; end is exclusive. Maximum period: 366 days. Historical timezone changes cannot be reconstructed.', 'scalyn-mail-relay' ), wp_timezone()->getName() ) );
		?>
		</p>
		<p><label for="report-start"><?php esc_html_e( 'Start', 'scalyn-mail-relay' ); ?></label><br><input id="report-start" name="start" type="text" required maxlength="19" value="<?php echo esc_attr( $start->format( 'Y-m-d H:i:s' ) ); ?>" aria-describedby="report-period-help"></p>
		<p><label for="report-end"><?php esc_html_e( 'End (exclusive)', 'scalyn-mail-relay' ); ?></label><br><input id="report-end" name="end" type="text" required maxlength="19" value="<?php echo esc_attr( $end->format( 'Y-m-d H:i:s' ) ); ?>" aria-describedby="report-period-help"></p>
		<p><label for="report-provider-scope"><?php esc_html_e( 'Mail provider scope', 'scalyn-mail-relay' ); ?></label><br><select id="report-provider-scope" name="provider_scope"><option value="all"><?php esc_html_e( 'All providers', 'scalyn-mail-relay' ); ?></option><option value="unattributed"><?php esc_html_e( 'Unattributed mail', 'scalyn-mail-relay' ); ?></option><option value="specific"><?php esc_html_e( 'Specific provider', 'scalyn-mail-relay' ); ?></option></select></p>
		<p><label for="report-provider"><?php esc_html_e( 'Provider identifier (only for Specific provider)', 'scalyn-mail-relay' ); ?></label><br><input id="report-provider" name="provider" type="text" maxlength="100" placeholder="smtp" aria-describedby="report-provider-help"></p>
		<p id="report-provider-help"><?php esc_html_e( 'Use the exact identifier, such as smtp. Health scores and diagnostics always describe the whole site, not only this provider.', 'scalyn-mail-relay' ); ?></p>
		<p><label for="report-format"><?php esc_html_e( 'Download format', 'scalyn-mail-relay' ); ?></label><br><select id="report-format" name="format"><option value="csv">CSV</option><option value="json">JSON</option><option value="pdf">PDF</option></select></p>
		<p><?php esc_html_e( 'PDF provides a paginated report using the same snapshot and privacy selection. PDF labels are currently in English. CSV/JSON remain available for machine-readable data.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'CSV uses path, type and value columns to preserve all report sections and distinguish missing values from zero. JSON preserves nested sections. Each download creates a new snapshot.', 'scalyn-mail-relay' ); ?></p>
		<p><label><input type="checkbox" name="references" value="1"> <?php esc_html_e( 'Include internal evidence identifiers for troubleshooting (row IDs and message/run/score UUIDs).', 'scalyn-mail-relay' ); ?></label></p>
		<p><?php esc_html_e( 'Identifiers are excluded by default. No format includes recipients, subjects, message bodies, credentials or raw diagnostic/provider responses. Reports still contain operational metadata; share them only with authorized people. Files download directly and are not stored by the plugin.', 'scalyn-mail-relay' ); ?></p>
		<?php submit_button( __( 'Download report', 'scalyn-mail-relay' ) ); ?>
	</form>
</div>
