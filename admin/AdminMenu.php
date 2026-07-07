<?php
/**
 * Admin menu registration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu class.
 */
class AdminMenu {

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add admin menu pages.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Scalyn Mail Relay', 'scalyn-mail-relay' ),
			__( 'Scalyn Mail Relay', 'scalyn-mail-relay' ),
			'manage_options',
			'scalyn-mail-relay',
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt2',
			56
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'scalyn-mail-relay' ) ) {
			return;
		}

		wp_enqueue_style(
			'scalyn-mail-relay-admin',
			SCALYN_MAIL_RELAY_URL . 'assets/css/admin.css',
			array(),
			SCALYN_MAIL_RELAY_VERSION
		);

		wp_enqueue_script(
			'scalyn-mail-relay-admin',
			SCALYN_MAIL_RELAY_URL . 'assets/js/admin.js',
			array(),
			SCALYN_MAIL_RELAY_VERSION,
			true
		);
	}

	/**
	 * Render dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require SCALYN_MAIL_RELAY_PATH . 'admin/views/dashboard.php';
	}
}
