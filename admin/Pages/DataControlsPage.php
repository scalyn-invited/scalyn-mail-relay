<?php
/**
 * Retention policy and uninstall controls.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Database\RetentionStateRepository;

defined( 'ABSPATH' ) || exit;

/** Settings page; retains its historical class and URL for compatibility. */
final class DataControlsPage {

	/**
	 * Creates the page.
	 *
	 * @param SettingsRepository       $settings Policy store.
	 * @param RetentionStateRepository $state Cleanup read model.
	 */
	public function __construct( private SettingsRepository $settings, private RetentionStateRepository $state ) {}

	/** Validates a POST and renders escaped, credential-free settings and status. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'scalyn-mail-relay' ) );
		}
		$notice = '';
		$error  = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; each form checks its own nonce before saving.
		$is_dkim_form = isset( $_POST['scalyn_dkim_settings'] );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server request method only.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! $is_dkim_form ) {
			check_admin_referer( 'scalyn_retention_settings' );
			$days      = isset( $_POST['retention_days'] ) && is_string( $_POST['retention_days'] ) ? sanitize_text_field( wp_unslash( $_POST['retention_days'] ) ) : '';
			$delete    = isset( $_POST['delete_on_uninstall'] ) && '1' === $_POST['delete_on_uninstall'];
			$confirmed = isset( $_POST['confirm_delete'] ) && '1' === $_POST['confirm_delete'];
			$cadence   = isset( $_POST['diagnostic_schedule'] ) ? ( is_string( $_POST['diagnostic_schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['diagnostic_schedule'] ) ) : '' ) : $this->settings->get_diagnostic_schedule();
			if ( ! in_array( $cadence, SettingsRepository::DIAGNOSTIC_SCHEDULES, true ) ) {
				$notice = __( 'Choose a valid diagnostic schedule. No settings were changed.', 'scalyn-mail-relay' );
				$error  = true;
			} elseif ( ! SettingsRepository::valid_retention_days( $days ) ) {
				$notice = __( 'Enter a whole number from 1 to 3650 days. No settings were changed.', 'scalyn-mail-relay' );
				$error  = true;
			} elseif ( $delete && ! $this->settings->get_delete_data_on_uninstall() && ! $confirmed ) {
				$notice = __( 'Confirm permanent deletion before enabling deletion on uninstall. No settings were changed.', 'scalyn-mail-relay' );
				$error  = true;
			} else {
				$this->settings->save(
					array(
						'advanced' => array(
							'log_retention_days'       => (int) $days,
							'diagnostic_schedule'      => $cadence,
							'delete_data_on_uninstall' => $delete,
							'confirm_delete_data'      => $confirmed,
						),
					)
				);
				$fresh = new SettingsRepository();
				$error = $fresh->get_log_retention_days() !== (int) $days || $fresh->get_delete_data_on_uninstall() !== $delete || $fresh->get_diagnostic_schedule() !== $cadence;
				if ( ! $error ) {
					$error = ! \Scalyn\MailRelay\Core\DiagnosticSchedule::reconcile();
				}
				$this->settings = $fresh;
				$notice         = $error ? __( 'Settings or scheduling could not be applied. Check the saved values and try again.', 'scalyn-mail-relay' ) : __( 'Data controls saved. Retention changes apply to the next scheduled cleanup.', 'scalyn-mail-relay' );
			}
		}
		$days      = $this->settings->get_log_retention_days();
		$cadence   = $this->settings->get_diagnostic_schedule();
		$delete    = $this->settings->get_delete_data_on_uninstall();
		$status    = $this->state->get();
		$next      = wp_next_scheduled( ScheduledHooks::CLEANUP );
		$dkim_form = new \Scalyn\MailRelay\Admin\Components\DkimSettingsForm( $this->settings );
		$labels    = array(
			'never'        => __( 'No cleanup has run yet.', 'scalyn-mail-relay' ),
			'running'      => __( 'Cleanup started; completion has not been recorded. An interrupted run resumes on a later tick.', 'scalyn-mail-relay' ),
			'complete'     => __( 'Last batch completed.', 'scalyn-mail-relay' ),
			'more_pending' => __( 'Last batch completed; more expired data may remain for later hourly batches.', 'scalyn-mail-relay' ),
			'failed'       => __( 'Cleanup failed. Completed batches remain committed; the next hourly run retries the remaining data. Check database availability if failures persist.', 'scalyn-mail-relay' ),
		);
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/data-controls.php';
	}
}
