<?php
/** Accessible, reusable contextual help disclosure.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/** Native click/keyboard disclosure, enhanced with hover and focus. */
final class HelpText {

	/**
	 * Render plain text only; no privileged data or actions.
	 *
	 * @param string $label Accessible translated control label.
	 * @param string $text Translated help text.
	 */
	public static function render( string $label, string $text ): void {
		if ( '' === trim( $text ) ) {
			return;
		}
		echo '<details class="scalyn-help-text"><summary aria-label="' . esc_attr( $label ) . '"><span aria-hidden="true">?</span></summary><span class="scalyn-help-text__content">' . esc_html( $text ) . '</span></details>';
	}
}
