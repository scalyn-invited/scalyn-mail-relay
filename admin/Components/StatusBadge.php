<?php
/**
 * Status badge component.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a status badge using the canonical Scalyn status vocabulary.
 *
 * Canonical status identifiers: unknown, healthy, warning, critical,
 * connected, disconnected, verified, unverified.
 *
 * Badges use both colour and text label so status is never communicated
 * by colour alone.
 *
 * Ownership: Kim / Admin.
 */
final class StatusBadge {

	/**
	 * Renders a status badge <span>.
	 *
	 * @param string $status Canonical status identifier (e.g. 'unknown', 'healthy').
	 * @param string $label  Human-readable label displayed inside the badge.
	 */
	public static function render( string $status, string $label ): void {
		printf(
			'<span class="scalyn-badge scalyn-badge--%s">%s</span>',
			esc_attr( sanitize_html_class( $status ) ),
			esc_html( $label )
		);
	}
}
