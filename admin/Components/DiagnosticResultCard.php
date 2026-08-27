<?php
/**
 * Diagnostic result card component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a diagnostic result card for SPF/DKIM/DMARC/Health sections.
 *
 * Each card displays a heading, status badge, and content area (either findings
 * or empty state). This component centralizes result presentation so UI views
 * remain clean and B2/B3 can wire real data without layout changes.
 *
 * Ownership: Bernie / Admin.
 */
final class DiagnosticResultCard {

	/**
	 * Renders a diagnostic result card.
	 *
	 * @param string $heading           Card heading (e.g., "SPF Record").
	 * @param string $status            Status identifier (unknown/healthy/warning/critical).
	 * @param string $status_label      Human-readable status label (e.g., "Unknown").
	 * @param callable $content_callback Callback that outputs card content (findings or empty state).
	 * @param string $heading_id        Optional: ID for the h2 element (for aria-labelledby).
	 */
	public static function render(
		string $heading,
		string $status,
		string $status_label,
		callable $content_callback,
		string $heading_id = ''
	): void {
		$section_attrs = '';
		if ( '' !== $heading_id ) {
			$section_attrs = sprintf( ' aria-labelledby="%s"', esc_attr( $heading_id ) );
		}

		printf( '<section class="scalyn-card scalyn-diagnostic-card"%s>', wp_kses_post( $section_attrs ) );

		if ( '' !== $heading_id ) {
			printf( '<h2 id="%s">%s</h2>', esc_attr( $heading_id ), esc_html( $heading ) );
		} else {
			printf( '<h2>%s</h2>', esc_html( $heading ) );
		}

		echo '<div class="scalyn-diagnostic-card__status">';
		StatusBadge::render( $status, $status_label );
		echo '</div>';

		echo '<div class="scalyn-diagnostic-card__content">';
		call_user_func( $content_callback );
		echo '</div>';

		echo '</section>';
	}
}
