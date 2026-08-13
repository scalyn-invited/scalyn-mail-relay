<?php
/**
 * Setup Wizard admin page.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manages wizard step state and renders the setup wizard view.
 *
 * The wizard is purely navigational UI at this stage. Backend-dependent
 * steps (configure, verify, test-email, health-check) render placeholder
 * content until Saturn's provider contracts and Yaj's REST endpoints are
 * ready. Step state is carried in the URL query string — no session or
 * database state is written by this class.
 *
 * Ownership: Kim / Admin.
 */
final class WizardPage {

	/**
	 * Total number of wizard steps.
	 */
	private const TOTAL_STEPS = 7;

	/**
	 * Performs a capability check and renders the wizard view.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access the Setup Wizard.', 'scalyn-mail-relay' ) );
		}

		$current_step = $this->get_current_step();
		$total_steps  = self::TOTAL_STEPS;
		$step_labels  = $this->get_step_labels();

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/wizard.php';
	}

	/**
	 * Returns the current wizard step number, clamped to the valid range 1–TOTAL_STEPS.
	 *
	 * Read-only GET navigation — no state is modified; no nonce is required.
	 *
	 * @return int Step number between 1 and TOTAL_STEPS inclusive.
	 */
	private function get_current_step(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only step navigation; no state is changed by this parameter.
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return max( 1, min( $step, self::TOTAL_STEPS ) );
	}

	/**
	 * Returns the ordered wizard step labels keyed by step number.
	 *
	 * @return array<int, string>
	 */
	private function get_step_labels(): array {
		return array(
			1 => __( 'Welcome', 'scalyn-mail-relay' ),
			2 => __( 'Choose Provider', 'scalyn-mail-relay' ),
			3 => __( 'Configure Provider', 'scalyn-mail-relay' ),
			4 => __( 'Verify Connection', 'scalyn-mail-relay' ),
			5 => __( 'Send Test Email', 'scalyn-mail-relay' ),
			6 => __( 'Initial Health Check', 'scalyn-mail-relay' ),
			7 => __( 'Complete', 'scalyn-mail-relay' ),
		);
	}
}
