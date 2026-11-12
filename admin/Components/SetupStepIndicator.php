<?php
/**
 * Setup step indicator component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an ordered list of setup steps with per-step status indicators.
 *
 * Each step entry must be an associative array with keys:
 *   'label'  string  Human-readable step description.
 *   'status' string  One of: pending, active, complete.
 *
 * Ownership: Kim / Admin.
 */
final class SetupStepIndicator {

	/**
	 * Renders the ordered step indicator list.
	 *
	 * @param array<int, array{label: string, status: string}> $steps Ordered step definitions.
	 */
	public static function render( array $steps ): void {
		echo '<ol class="scalyn-setup-steps">';
		foreach ( $steps as $index => $step ) {
			$number = $index + 1;
			$status = sanitize_html_class( $step['status'] ?? 'pending' );
			printf(
				'<li class="scalyn-step scalyn-step--%s"><span class="scalyn-step__number" aria-hidden="true">%d</span><span class="scalyn-step__label">%s</span></li>',
				esc_attr( $status ),
				absint( $number ),
				esc_html( $step['label'] ?? '' )
			);
		}
		echo '</ol>';
	}
}
