<?php
/**
 * Diagnostic result card component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a diagnostic result card for SPF, DKIM, DMARC/Health sections.
 *
 * Each card displays a heading, status badge, and content area (either findings
 * or empty state). This component centralizes result presentation so UI views
 * remain clean and B2/B3 can wire real data without layout changes.
 *
 * Ownership: Admin.
 */
final class DiagnosticResultCard {

	/**
	 * Renders a diagnostic result card.
	 *
	 * @param string     $heading           Card heading (e.g., "SPF Record").
	 * @param string     $status            Status identifier (unknown/healthy/warning/critical).
	 * @param string     $status_label      Human-readable status label (e.g., "Unknown").
	 * @param callable   $content_callback  Callback that outputs card content (findings or empty state).
	 * @param string     $heading_id        Optional: ID for the h2 element (for aria-labelledby).
	 * @param array|null $finding Optional persisted check metadata; null for non-check cards.
	 */
	public static function render(
		string $heading,
		string $status,
		string $status_label,
		callable $content_callback,
		string $heading_id = '',
		?array $finding = null
	): void {
		if ( '' !== $heading_id ) {
			printf(
				'<section class="scalyn-card scalyn-diagnostic-card" aria-labelledby="%s">',
				esc_attr( $heading_id )
			);
		} else {
			echo '<section class="scalyn-card scalyn-diagnostic-card">';
		}

		// Keep warnings and unknown evidence expanded; collapse passing checks.
		echo 'healthy' === $status ? '<details>' : '<details open>';
		echo '<summary>';
		if ( '' !== $heading_id ) {
			printf( '<h3 id="%s">%s</h3>', esc_attr( $heading_id ), esc_html( $heading ) );
		} else {
			printf( '<h3>%s</h3>', esc_html( $heading ) );
		}

		echo '<span class="scalyn-diagnostic-card__status">';
		StatusBadge::render( $status, $status_label );
		echo '</span></summary>';

		echo '<div class="scalyn-diagnostic-card__content">';
		call_user_func( $content_callback );
		echo '</div>';

		echo '</details>';
		if ( null !== $finding ) {
			$check_status = $finding['status'] ?? 'unknown';
			$issues       = in_array( $check_status, array( 'warn', 'fail' ), true ) ? '1' : ( 'pass' === $check_status ? '0' : __( 'Not assessed', 'scalyn-mail-relay' ) );
			echo '<dl class="scalyn-check-meta"><dt>' . esc_html__( 'Recorded issues', 'scalyn-mail-relay' ) . '</dt><dd>' . esc_html( $issues ) . '</dd>';
			echo '<dt>' . esc_html__( 'Last recorded check (site time)', 'scalyn-mail-relay' ) . '</dt><dd>' . esc_html( $finding['created_at'] ?? __( 'Not recorded', 'scalyn-mail-relay' ) ) . '</dd></dl>';
			echo '<a href="#scalyn-diagnostic-guide">' . esc_html__( 'Check documentation and limitations', 'scalyn-mail-relay' ) . '</a>';
		}
		echo '</section>';
	}
}
