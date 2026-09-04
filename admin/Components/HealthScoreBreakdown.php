<?php
/**
 * Health score breakdown component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the per-component health score list plus the scorer's summary.
 *
 * A component with no evidence renders as "Not evaluated" so the reader can
 * see exactly which inputs produced the overall number. Components are never
 * shown as 0 when they were merely excluded.
 *
 * Ownership: Kim / Admin.
 */
final class HealthScoreBreakdown {

	/**
	 * Renders the breakdown list and summary.
	 *
	 * @param array<string, int|null> $components Component label => score (0-100) or null when not evaluated.
	 * @param string                  $summary    Plain-English summary from HealthScorer, or '' to omit.
	 */
	public static function render( array $components, string $summary ): void {
		echo '<ul class="scalyn-health-breakdown">';
		foreach ( $components as $label => $value ) {
			if ( null === $value ) {
				$value_text = __( 'Not evaluated', 'scalyn-mail-relay' );
			} else {
				/* translators: %d is a component score (0-100) */
				$value_text = sprintf( __( '%d/100', 'scalyn-mail-relay' ), (int) $value );
			}
			printf(
				'<li class="scalyn-health-breakdown__item"><span class="scalyn-health-breakdown__label">%s</span><span class="scalyn-health-breakdown__value">%s</span></li>',
				esc_html( (string) $label ),
				esc_html( $value_text )
			);
		}
		echo '</ul>';

		if ( '' !== $summary ) {
			echo '<p class="scalyn-card__note scalyn-health-summary">' . esc_html( $summary ) . '</p>';
		}
	}
}
