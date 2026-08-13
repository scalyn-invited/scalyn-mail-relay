<?php
/**
 * Action button component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an admin action button as either an active link or a disabled button.
 *
 * Actions whose backend REST endpoint is not yet available must be rendered
 * as disabled so users cannot trigger an unsupported operation. Disabled
 * state is conveyed via the HTML disabled attribute and aria-disabled, not
 * colour alone.
 *
 * Ownership: Kim / Admin.
 */
final class ActionButton {

	/**
	 * Renders an action link or a disabled button.
	 *
	 * @param string $label    Button label text.
	 * @param string $url      Destination URL. Empty string forces a disabled button.
	 * @param bool   $disabled Whether to force a disabled state regardless of URL.
	 */
	public static function render( string $label, string $url = '', bool $disabled = false ): void {
		if ( $disabled || '' === $url ) {
			printf(
				'<button type="button" class="button scalyn-action-btn" disabled aria-disabled="true">%s</button>',
				esc_html( $label )
			);
			return;
		}

		printf(
			'<a href="%s" class="button button-primary scalyn-action-btn">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
}
