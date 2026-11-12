<?php
/**
 * Empty state component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an empty state block for sections where backend data is unavailable.
 *
 * Use this component whenever a data section cannot be populated yet —
 * do not render placeholder numbers or invented statistics.
 *
 * Ownership: Kim / Admin.
 */
final class EmptyState {

	/**
	 * Renders the empty state block.
	 *
	 * @param string $message      Plain-English explanation of why there is no data.
	 * @param string $action_label Optional label for a call-to-action link.
	 * @param string $action_url   Optional URL for the call-to-action link.
	 */
	public static function render( string $message, string $action_label = '', string $action_url = '' ): void {
		echo '<div class="scalyn-empty-state">';
		printf(
			'<p class="scalyn-empty-state__message">%s</p>',
			esc_html( $message )
		);
		if ( '' !== $action_label && '' !== $action_url ) {
			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( $action_url ),
				esc_html( $action_label )
			);
		}
		echo '</div>';
	}
}
