<?php
/**
 * Main plugin bootstrap.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Admin\AdminMenu;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Future: create database tables and default options.
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Future: clear scheduled events if needed.
	}

	/**
	 * Initialise plugin services.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Load required files.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		require_once SCALYN_MAIL_RELAY_PATH . 'admin/AdminMenu.php';
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		if ( is_admin() ) {
			( new AdminMenu() )->register();
		}
	}
}
