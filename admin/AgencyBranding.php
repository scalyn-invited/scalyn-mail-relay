<?php
/**
 * Scoped identity presentation; never changes WordPress navigation or styles.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Agency\BrandingRepository;

defined( 'ABSPATH' ) || exit;

/** Identity and support links are escaped only at the rendering boundary. */
final class AgencyBranding {

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
