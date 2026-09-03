<?php
/**
 * Diagnostic evidence, impact, and severity display component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders structured evidence, impact, severity, and raw diagnostic data.
 *
 * Standardizes the display of rich diagnostic details across check type cards.
 * Handles JSON parsing of raw_result, escaping, and collapsible sections.
 *
 * Ownership: Bernie / Admin.
 */
final class EvidenceDisplay {

	/**
	 * Renders evidence, impact, and severity details for a diagnostic finding.
	 *
	 * @param array<string, mixed> $finding        Diagnostic result row from repository.
	 *                                              Expected keys: raw_result (JSON), severity.
	 * @param string               $finding_type   Human-readable check name (e.g., 'SPF Record').
	 * @param bool                 $show_severity  Whether to display the severity level. Default true.
	 */
	public static function render( array $finding, string $finding_type = '', bool $show_severity = true ): void {
		if ( empty( $finding ) ) {
			return;
		}

		$raw_result = $finding['raw_result'] ?? '';
		$severity   = $finding['severity'] ?? '';

		if ( ! $raw_result ) {
			return;
		}

		$decoded = json_decode( $raw_result, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		// Render severity badge if requested and available.
		if ( $show_severity && $severity ) {
			echo '<span class="scalyn-finding__severity scalyn-severity-' . esc_attr( $severity ) . '">';
			echo esc_html( ucfirst( $severity ) );
			echo '</span>';
		}

		// Render evidence section if available.
		$evidence = $decoded['evidence'] ?? '';
		if ( $evidence ) {
			echo '<details class="scalyn-finding__details">';
			echo '<summary>' . esc_html__( 'Evidence', 'scalyn-mail-relay' ) . '</summary>';
			echo '<pre class="scalyn-finding__evidence">' . esc_html( $evidence ) . '</pre>';
			echo '</details>';
		}

		// Render impact section if available.
		$impact = $decoded['impact'] ?? '';
		if ( $impact ) {
			echo '<p class="scalyn-finding__impact">' . esc_html( $impact ) . '</p>';
		}
	}
}
