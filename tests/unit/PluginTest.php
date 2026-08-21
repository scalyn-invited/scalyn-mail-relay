<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\AdminMenu;
use Scalyn\MailRelay\Core\Container;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;

final class PluginTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$this->reset_plugin_singleton();
	}

	protected function tearDown(): void {
		$this->reset_plugin_singleton();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function reset_plugin_singleton(): void {
		$prop = new \ReflectionProperty( Plugin::class, 'instance' );
		$prop->setValue( null, null );
	}

	private function booted_container(): Container {
		$plugin = Plugin::instance();
		$plugin->boot();
		return $plugin->container();
	}

	// -------------------------------------------------------------------------
	// Service registration
	// -------------------------------------------------------------------------

	public function test_provider_registry_is_registered_in_container(): void {
		$this->assertTrue( $this->booted_container()->has( ProviderRegistry::class ) );
	}

	public function test_settings_repository_is_registered_in_container(): void {
		$this->assertTrue( $this->booted_container()->has( SettingsRepository::class ) );
	}

	public function test_mail_dispatcher_is_registered_in_container(): void {
		$this->assertTrue( $this->booted_container()->has( MailDispatcher::class ) );
	}

	public function test_admin_menu_remains_registered_after_wiring(): void {
		$this->assertTrue( $this->booted_container()->has( AdminMenu::class ) );
	}

	// -------------------------------------------------------------------------
	// Dependency wiring
	// -------------------------------------------------------------------------

	public function test_mail_dispatcher_receives_shared_provider_registry_instance(): void {
		$container  = $this->booted_container();
		$registry   = $container->get( ProviderRegistry::class );
		$dispatcher = $container->get( MailDispatcher::class );

		$prop = new \ReflectionProperty( MailDispatcher::class, 'registry' );
		$this->assertSame( $registry, $prop->getValue( $dispatcher ) );
	}

	public function test_mail_dispatcher_receives_shared_settings_repository_instance(): void {
		$container  = $this->booted_container();
		$settings   = $container->get( SettingsRepository::class );
		$dispatcher = $container->get( MailDispatcher::class );

		$prop = new \ReflectionProperty( MailDispatcher::class, 'settings' );
		$this->assertSame( $settings, $prop->getValue( $dispatcher ) );
	}

	// -------------------------------------------------------------------------
	// Container caching contract
	// -------------------------------------------------------------------------

	public function test_repeated_resolution_returns_same_instance(): void {
		$container = $this->booted_container();

		$this->assertSame( $container->get( ProviderRegistry::class ), $container->get( ProviderRegistry::class ) );
		$this->assertSame( $container->get( SettingsRepository::class ), $container->get( SettingsRepository::class ) );
		$this->assertSame( $container->get( MailDispatcher::class ), $container->get( MailDispatcher::class ) );
	}

	// -------------------------------------------------------------------------
	// Zero-provider boot safety
	// -------------------------------------------------------------------------

	public function test_boot_does_not_fail_with_zero_providers(): void {
		// No option stored, no providers registered. Boot must complete without exception.
		Plugin::instance()->boot();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// Boot hook
	// -------------------------------------------------------------------------

	public function test_booted_hook_fires_with_container_containing_all_core_services(): void {
		$captured = null;

		$GLOBALS['_test_wp_actions']['scalyn_mail_relay_booted'] = static function ( object $container ) use ( &$captured ): void {
			$captured = $container;
		};

		Plugin::instance()->boot();

		$this->assertInstanceOf( Container::class, $captured );
		$this->assertTrue( $captured->has( ProviderRegistry::class ) );
		$this->assertTrue( $captured->has( SettingsRepository::class ) );
		$this->assertTrue( $captured->has( MailDispatcher::class ) );
	}
}
