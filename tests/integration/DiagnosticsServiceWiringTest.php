<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Container;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;
use Scalyn\MailRelay\Diagnostics\Checks\MxCheck;
use Scalyn\MailRelay\Diagnostics\Checks\SpfCheck;

final class DiagnosticsServiceWiringTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$this->reset_plugin_singleton();
	}

	protected function tearDown(): void {
		$this->reset_plugin_singleton();
	}

	private function reset_plugin_singleton(): void {
		$prop = new \ReflectionProperty( Plugin::class, 'instance' );
		$prop->setValue( null, null );
	}

	private function booted_container(): Container {
		$plugin = Plugin::instance();
		$plugin->boot();
		return $plugin->container();
	}

	public function test_diagnostic_runner_is_registered_in_container(): void {
		$this->assertTrue( $this->booted_container()->has( DiagnosticRunner::class ) );
	}

	public function test_diagnostic_repository_is_registered_in_container(): void {
		$this->assertTrue( $this->booted_container()->has( DiagnosticRepository::class ) );
	}

	public function test_diagnostic_check_registry_is_registered_in_container(): void {
		$this->assertTrue( $this->booted_container()->has( DiagnosticCheckRegistry::class ) );
	}

	public function test_all_core_checks_are_registered_and_discoverable(): void {
		$container = $this->booted_container();
		$registry  = $container->get( DiagnosticCheckRegistry::class );

		// 5 core checks: SPF, MX, DKIM, DMARC, SMTP/TLS
		$this->assertCount( 5, $registry->get_all() );
		$this->assertNotNull( $registry->get( 'spf_record' ) );
		$this->assertNotNull( $registry->get( 'mx_record' ) );
		$this->assertNotNull( $registry->get( 'dkim_record' ) );
		$this->assertNotNull( $registry->get( 'dmarc_policy' ) );
		$this->assertNotNull( $registry->get( 'smtp_tls' ) );
	}

	public function test_diagnostic_runner_can_execute_registered_checks(): void {
		$container = $this->booted_container();
		$runner    = $container->get( DiagnosticRunner::class );
		$registry  = $container->get( DiagnosticCheckRegistry::class );
		$context   = new DiagnosticContext( 'example.com', array() );

		// Inject mock DNS lookups to avoid real network calls.
		$mock_spf_check = new SpfCheck( static fn() => array() );
		$mock_mx_check  = new MxCheck( static fn() => array() );

		$checks = array( $mock_spf_check, $mock_mx_check );

		$results = $runner->run( $checks, $context );

		$this->assertCount( 2, $results );
		$this->assertSame( 'spf_record', $results[0]['id'] );
		$this->assertSame( 'mx_record', $results[1]['id'] );
	}

	public function test_spf_check_can_run_without_error(): void {
		$container = $this->booted_container();
		$runner    = $container->get( DiagnosticRunner::class );
		$context   = new DiagnosticContext( 'example.com', array() );

		// Use mock DNS lookup to avoid real network calls.
		$check = new SpfCheck( static fn() => array() );

		$results = $runner->run( array( $check ), $context );

		$this->assertCount( 1, $results );
		$this->assertSame( 'spf_record', $results[0]['id'] );
		$this->assertNotNull( $results[0]['result'] );
	}

	public function test_mx_check_can_run_without_error(): void {
		$container = $this->booted_container();
		$runner    = $container->get( DiagnosticRunner::class );
		$context   = new DiagnosticContext( 'example.com', array() );

		// Use mock DNS lookup to avoid real network calls.
		$check = new MxCheck( static fn() => array() );

		$results = $runner->run( array( $check ), $context );

		$this->assertCount( 1, $results );
		$this->assertSame( 'mx_record', $results[0]['id'] );
		$this->assertNotNull( $results[0]['result'] );
	}

	public function test_failed_check_does_not_abort_runner(): void {
		$container = $this->booted_container();
		$runner    = $container->get( DiagnosticRunner::class );
		$context   = new DiagnosticContext( 'example.com', array() );

		$good_check = new SpfCheck( static fn() => array() );
		$bad_check  = new FailingDiagnosticCheck();

		$results = $runner->run( array( $good_check, $bad_check ), $context );

		$this->assertCount( 2, $results );

		// Good check should have passed.
		$this->assertSame( 'spf_record', $results[0]['id'] );

		// Bad check should be converted to error status.
		$this->assertSame( 'failing_check', $results[1]['id'] );
		$this->assertSame( 'error', $results[1]['result']->status );
	}

	public function test_scalyn_mail_relay_booted_hook_fires_with_container(): void {
		$captured = null;

		add_action(
			'scalyn_mail_relay_booted',
			static function ( $container ) use ( &$captured ) {
				$captured = $container;
			}
		);

		$container = $this->booted_container();

		$this->assertInstanceOf( Container::class, $captured );
		$this->assertTrue( $captured->has( DiagnosticCheckRegistry::class ) );
		$this->assertTrue( $captured->has( DiagnosticRunner::class ) );
		$this->assertTrue( $captured->has( DiagnosticRepository::class ) );
	}

	public function test_third_party_can_register_check_via_booted_action(): void {
		$captured_registry = null;

		add_action(
			'scalyn_mail_relay_booted',
			static function ( $container ) use ( &$captured_registry ) {
				$registry          = $container->get( DiagnosticCheckRegistry::class );
				$captured_registry = $registry;
				$registry->register( new MockThirdPartyCheck() );
			}
		);

		$container = $this->booted_container();

		// Verify third-party check was registered.
		$this->assertInstanceOf( DiagnosticCheckRegistry::class, $captured_registry );
		$this->assertTrue( $captured_registry->has( 'third_party_check' ) );
		// 6 total: 5 core (SPF, MX, DKIM, DMARC, SMTP/TLS) + 1 third-party
		$this->assertCount( 6, $captured_registry->get_all() );
	}
}

/**
 * Mock check that always throws an exception, for testing error handling.
 */
final class FailingDiagnosticCheck implements \Scalyn\MailRelay\Contracts\DiagnosticCheckInterface {

	public function get_id(): string {
		return 'failing_check';
	}

	public function get_category(): string {
		return 'test';
	}

	public function run( DiagnosticContext $context ): \Scalyn\MailRelay\Diagnostics\DiagnosticResult {
		throw new \Exception( 'Intentional failure for testing' );
	}
}

/**
 * Mock third-party check for testing registry extensibility.
 */
final class MockThirdPartyCheck implements \Scalyn\MailRelay\Contracts\DiagnosticCheckInterface {

	public function get_id(): string {
		return 'third_party_check';
	}

	public function get_category(): string {
		return 'third_party';
	}

	public function run( DiagnosticContext $context ): \Scalyn\MailRelay\Diagnostics\DiagnosticResult {
		return new \Scalyn\MailRelay\Diagnostics\DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: 'Third-party check passed'
		);
	}
}
