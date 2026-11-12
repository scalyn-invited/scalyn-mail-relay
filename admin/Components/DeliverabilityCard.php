<?php
/** Shared, protected Deliverability Score presentation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Diagnostics\DeliverabilityAssessment;

defined( 'ABSPATH' ) || exit;

/** Both pages use the same persisted assessment and protected refresh action. */
final class DeliverabilityCard {

	/** Fixed public error feedback.
	 *
	 * @var string
	 */
	private string $error = '';

	/** Receives the shared assessment service.
	 *
	 * @param DeliverabilityAssessment $service Assessment boundary.
	 */
	public function __construct( private DeliverabilityAssessment $service ) {}

	/** Only explicit nonce-checked POSTs compute and write evidence. */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Detection only; authorization and nonce follow.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['scalyn_deliverability_assess'] ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			wp_die( esc_html__( 'You do not have permission to assess deliverability.', 'scalyn-mail-relay' ) );
		}
		check_admin_referer( 'scalyn_deliverability_assess' );
		try {
			$this->service->assess();
		} catch ( \Throwable $error ) {
			$this->error = __( 'The assessment could not be saved. Check the plugin database upgrade and current configuration, then try again.', 'scalyn-mail-relay' );
		}
	}

	/** Renders the shared card; all persisted values are escaped at output. */
	public function render(): void {
		$assessment = $this->service->current();
		$error      = $this->error;
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/deliverability-score.php';
	}
}
