<?php
/** Presentation-only, progressively enhanced page navigation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/** Reuses the Providers navigation behavior without changing protected forms. */
final class PageTabs {

	/**
	 * Render translated labels keyed by stable fragment identifiers.
	 *
	 * @param array<string,string> $tabs Authorized page sections.
	 */
	public static function render( array $tabs ): void {
		echo '<nav class="scalyn-provider-tabs" id="scalyn-provider-tabs" aria-label="' . esc_attr__( 'Page sections', 'scalyn-mail-relay' ) . '">';
		foreach ( $tabs as $key => $label ) {
			echo '<a id="scalyn-tab-' . esc_attr( $key ) . '" href="#' . esc_attr( $key ) . '" data-provider-tab="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}
}
