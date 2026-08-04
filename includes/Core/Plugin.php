<?php
/**
 * Main plugin application.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?Plugin $instance = null;
	private Container $container;
	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->register_services();
		$this->register_hooks();
		$this->booted = true;

		do_action( 'scalyn_mail_relay_booted', $this->container );
	}

	public function container(): Container {
		return $this->container;
	}

	private function register_services(): void {
		$this->container->set( AdminMenu::class, static fn(): AdminMenu => new AdminMenu() );
	}

	private function register_hooks(): void {
		load_plugin_textdomain( 'scalyn-mail-relay', false, dirname( plugin_basename( SCALYN_MAIL_RELAY_FILE ) ) . '/languages' );

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
		}
	}
}
