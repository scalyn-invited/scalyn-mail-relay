<?php
/**
 * Admin menu registration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Scalyn Mail Relay top-level admin menu and enqueues admin assets.
 *
 * Ownership: Mikko / Admin. This skeleton is placed here by Kim as a foundation.
 * Mikko will expand this class with sub-menus, settings pages and the setup wizard.
 */
final class AdminMenu {

	/**
	 * Hooks the admin menu and asset enqueueing actions into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the top-level admin menu page.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Mail Relay', 'scalyn-mail-relay' ),
			Capabilities::VIEW_DASHBOARD,
			'scalyn-mail-relay',
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt2',
			56
		);
	}

	/**
	 * Enqueues admin CSS and JS on Scalyn Mail Relay pages.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'scalyn-mail-relay' ) ) {
			return;
		}

		wp_enqueue_style( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/css/admin.css', array(), SCALYN_MAIL_RELAY_VERSION );
		wp_enqueue_script( 'scalyn-mail-relay-admin', SCALYN_MAIL_RELAY_URL . 'assets/js/admin.js', array(), SCALYN_MAIL_RELAY_VERSION, true );
	}

	/**
	 * Renders the dashboard view after capability check.
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view Scalyn Mail Relay.', 'scalyn-mail-relay' ) );
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/dashboard.php';
	}
}
