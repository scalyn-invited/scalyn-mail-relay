<?php
/**
 * Transient header analysis for a received test message.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Diagnostics\HeaderAnalyzer;

defined( 'ABSPATH' ) || exit;

/** Never stores, logs or re-renders the pasted headers. */
final class HeaderAnalysisForm {

	/** Nonce action. */
	public const NONCE = 'scalyn_header_analysis';

	/**
	 * Analysis result for this request.
	 *
	 * @var array|null
	 */
	private ?array $result = null;

	/**
	 * Fixed error feedback.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * Creates the form.
	 *
	 * @param HeaderAnalyzer $analyzer Pure analyzer.
	 */
	public function __construct( private readonly HeaderAnalyzer $analyzer = new HeaderAnalyzer() ) {}

	/** Handles this form's POST after capability and nonce validation. */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Detection only; nonce verified below.
		if ( ! isset( $_POST['scalyn_header_analysis'] ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			wp_die( esc_html__( 'You do not have permission to analyze messages.', 'scalyn-mail-relay' ) );
		}
		check_admin_referer( self::NONCE );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw headers are parsed, never stored or echoed; sanitizing would corrupt them.
		$headers = isset( $_POST['scalyn_headers'] ) && is_string( $_POST['scalyn_headers'] ) ? wp_unslash( $_POST['scalyn_headers'] ) : '';
		try {
			$this->result = $this->analyzer->analyze( $headers );
		} catch ( \InvalidArgumentException $error ) {
			$this->error = strlen( $headers ) > HeaderAnalyzer::MAX_BYTES
				? __( 'The pasted text is larger than 64 KB. Paste only the message headers.', 'scalyn-mail-relay' )
				: __( 'No message headers were found. Paste the full original headers of the received message.', 'scalyn-mail-relay' );
		}
		unset( $headers );
		$_POST['scalyn_headers'] = '';
	}

	/** Renders the form and, when available, the analysis. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::RUN_DIAGNOSTICS ) ) {
			return;
		}
		$result = $this->result;
		$error  = $this->error;
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/header-analysis.php';
	}
}
