<?php
/**
 * Scoped identity presentation and owned menu label; keeps native WordPress navigation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Agency\BrandingRepository;

defined( 'ABSPATH' ) || exit;

/** Identity and support links are escaped only at the rendering boundary. */
final class AgencyBranding {

	/** Bounds the owned sidebar image on every admin screen, including collapsed navigation. */
	public static function enqueue_menu_styles(): void {
		wp_add_inline_style( 'admin-menu', self::menu_icon_css() );
	}

	/** Fixed, narrowly scoped CSS; contains no saved values or arbitrary input. */
	public static function menu_icon_css(): string {
		return '#adminmenu #toplevel_page_scalyn-mail-relay .wp-menu-image img{width:20px!important;height:20px!important;max-width:20px!important;max-height:20px!important;object-fit:contain;box-sizing:border-box;padding:0!important;margin:7px auto 0;display:block;}';
	}

	/** Returns the saved plain-text brand, or the original short default label. */
	public static function menu_label(): string {
		$name = ( new BrandingRepository() )->get()['name'];
		return BrandingRepository::defaults()['name'] === $name ? __( 'Mail Relay', 'scalyn-mail-relay' ) : $name;
	}

	/** Changes owned presentation only, preserving WordPress's registered page hooks. */
	public static function apply_menu_label(): void {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $index => $item ) {
			if ( 'scalyn-mail-relay' === ( $item[2] ?? '' ) ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress exposes menu titles through this registry; only the owned display label changes, preserving registered page hooks.
				$menu[ $index ][0] = esc_html( self::menu_label() );
				$icon              = ( new BrandingRepository() )->menu_icon_url();
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Only the owned native icon slot; no HTML, hook or capability changes.
				$menu[ $index ][6] = '' !== $icon && '' !== esc_url( $icon ) ? esc_url( $icon ) : 'dashicons-email-alt2';
				return;
			}
		}
	}

	/** Returns CSS containing only validated six-digit colors.
	 *
	 * @return string Scoped CSS.
	 */
	public static function css(): string {
		$brand = ( new BrandingRepository() )->get();
		return '.scalyn-mail-relay{--scalyn-accent:' . $brand['primary'] . ';--scalyn-ink:' . $brand['secondary'] . ';}';
	}

	/** Renders a compact identity/support strip only on owned plugin screens. */
	public static function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'scalyn-mail-relay' ) ) {
			return;
		}
		$repository = new BrandingRepository();
		$brand      = $repository->get();
		$logo       = $repository->logo_url();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/agency-brand.php';
	}
}
