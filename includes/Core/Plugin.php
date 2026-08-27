<?php
/**
 * Main plugin application.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Admin\AdminMenu;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;
use Scalyn\MailRelay\Logging\MailEventSubscriber;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton. Bootstraps the service container and registers WordPress hooks.
 *
 * Entry point: scalyn-mail-relay.php fires Plugin::instance()->boot() on plugins_loaded.
 * The scalyn_mail_relay_booted action is fired after all services are registered,
 * giving other modules and third-party code an opportunity to extend the container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The plugin service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has already been called.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance() to obtain the singleton.
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Returns the singleton plugin instance, creating it if necessary.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boots the plugin: registers services, hooks, and fires scalyn_mail_relay_booted.
	 * Subsequent calls are no-ops.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->register_services();
		$this->register_hooks();
		$this->booted = true;

		do_action( 'scalyn_mail_relay_booted', $this->container );
	}

	/**
	 * Returns the plugin service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Registers core services in the container.
	 */
	private function register_services(): void {
		$this->container->set( AdminMenu::class, static fn(): AdminMenu => new AdminMenu() );
		$this->container->set( SettingsRepository::class, static fn(): SettingsRepository => new SettingsRepository() );
		$this->container->set( ProviderRegistry::class, static fn(): ProviderRegistry => new ProviderRegistry() );
		$this->container->set(
			MailDispatcher::class,
			static fn( Container $c ): MailDispatcher => new MailDispatcher(
				$c->get( ProviderRegistry::class ),
				$c->get( SettingsRepository::class )
			)
		);

		$this->container->set( MailLogRepository::class, static fn(): MailLogRepository => new MailLogRepository() );
		$this->container->set( TimelineRepository::class, static fn(): TimelineRepository => new TimelineRepository() );
		$this->container->set(
			MailEventSubscriber::class,
			static fn( Container $c ): MailEventSubscriber => new MailEventSubscriber(
				$c->get( MailLogRepository::class ),
				$c->get( TimelineRepository::class )
			)
		);

		$this->container->set( DiagnosticRunner::class, static fn(): DiagnosticRunner => new DiagnosticRunner() );
		$this->container->set( DiagnosticRepository::class, static fn(): DiagnosticRepository => new DiagnosticRepository() );
	}

	/**
	 * Registers WordPress action and filter hooks.
	 */
	private function register_hooks(): void {
		load_plugin_textdomain( 'scalyn-mail-relay', false, dirname( plugin_basename( SCALYN_MAIL_RELAY_FILE ) ) . '/languages' );

		// Mail logging hooks run on every request (not only admin) because mail
		// can be dispatched from frontend, REST, WP-CLI, and cron contexts.
		$this->container->get( MailEventSubscriber::class )->register();

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
		}
	}
}
