<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

final class MailDispatcherTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_actions'] = array();

		// Other test cases (Plugin boot, service wiring) register a real
		// MailEventSubscriber against the shared stub hook registry. Those
		// listeners survive into this file and turn every dispatch() here into
		// a repository write against a $wpdb that this test never sets up.
		// The dispatcher is under test in isolation; it must not inherit
		// foreign listeners.
		$GLOBALS['_test_wp_added_actions'] = array();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_message(): MailMessage {
		return new MailMessage(
			uuid: 'msg-001',
			from: 'from@example.com',
			to: array( 'to@example.com' ),
			subject: 'Test',
			body: 'Hello'
		);
	}

	private function make_provider( string $id, bool $success ): ProviderInterface {
		return new class( $id, $success ) implements ProviderInterface {
			public function __construct( private string $id, private bool $success ) {}
			public function get_id(): string {
				return $this->id; }
			public function get_label(): string {
				return 'Test Provider'; }
			public function validate_config( array $config ): ValidationResult {
				return new ValidationResult( true ); }
			public function test_connection( array $config ): ConnectionResult {
				return new ConnectionResult( true ); }
			public function send( MailMessage $message, array $config ): SendResult {
				return new SendResult( $this->success, $this->id );
			}
			public function get_capabilities(): array {
				return array(); }
		};
	}

	private function make_settings( string $provider_id ): SettingsRepository {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => $provider_id ),
		);
		return new SettingsRepository();
	}

	// -------------------------------------------------------------------------
	// Configuration failures
	// -------------------------------------------------------------------------

	public function test_returns_failure_when_no_provider_is_configured(): void {
		$dispatcher = new MailDispatcher(
			new ProviderRegistry(),
			new SettingsRepository()
		);

		$result = $dispatcher->dispatch( $this->make_message() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'config', $result->failure_category );
	}

	public function test_returns_failure_when_provider_configured_but_not_registered(): void {
		$dispatcher = new MailDispatcher(
			new ProviderRegistry(),
			$this->make_settings( 'smtp' )
		);

		$result = $dispatcher->dispatch( $this->make_message() );

		$this->assertFalse( $result->success );
		$this->assertSame( 'config', $result->failure_category );
		$this->assertSame( 'smtp', $result->provider );
	}

	// -------------------------------------------------------------------------
	// Successful dispatch
	// -------------------------------------------------------------------------

	public function test_dispatch_returns_success_result_from_provider(): void {
		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', true ) );

		$dispatcher = new MailDispatcher( $registry, $this->make_settings( 'smtp' ) );
		$result     = $dispatcher->dispatch( $this->make_message() );

		$this->assertTrue( $result->success );
		$this->assertSame( 'smtp', $result->provider );
	}

	// -------------------------------------------------------------------------
	// Provider failure
	// -------------------------------------------------------------------------

	public function test_dispatch_returns_failure_result_from_provider(): void {
		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', false ) );

		$dispatcher = new MailDispatcher( $registry, $this->make_settings( 'smtp' ) );
		$result     = $dispatcher->dispatch( $this->make_message() );

		$this->assertFalse( $result->success );
	}

	// -------------------------------------------------------------------------
	// Hook firing — success path
	// -------------------------------------------------------------------------

	public function test_mail_sent_hook_is_fired_on_provider_success(): void {
		$fired = false;
		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_SENT ] = static function () use ( &$fired ): void {
			$fired = true;
		};

		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', true ) );

		( new MailDispatcher( $registry, $this->make_settings( 'smtp' ) ) )
			->dispatch( $this->make_message() );

		$this->assertTrue( $fired, 'MAIL_SENT hook was not fired on successful dispatch.' );
	}

	public function test_mail_failed_hook_is_not_fired_on_provider_success(): void {
		$fired = false;
		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_FAILED ] = static function () use ( &$fired ): void {
			$fired = true;
		};

		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', true ) );

		( new MailDispatcher( $registry, $this->make_settings( 'smtp' ) ) )
			->dispatch( $this->make_message() );

		$this->assertFalse( $fired, 'MAIL_FAILED hook must not fire on successful dispatch.' );
	}

	// -------------------------------------------------------------------------
	// Hook firing — failure path
	// -------------------------------------------------------------------------

	public function test_mail_failed_hook_is_fired_on_provider_failure(): void {
		$fired = false;
		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_FAILED ] = static function () use ( &$fired ): void {
			$fired = true;
		};

		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', false ) );

		( new MailDispatcher( $registry, $this->make_settings( 'smtp' ) ) )
			->dispatch( $this->make_message() );

		$this->assertTrue( $fired, 'MAIL_FAILED hook was not fired on provider failure.' );
	}

	public function test_mail_failed_hook_is_fired_when_no_provider_configured(): void {
		$fired = false;
		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_FAILED ] = static function () use ( &$fired ): void {
			$fired = true;
		};

		( new MailDispatcher( new ProviderRegistry(), new SettingsRepository() ) )
			->dispatch( $this->make_message() );

		$this->assertTrue( $fired, 'MAIL_FAILED hook was not fired for missing provider configuration.' );
	}

	public function test_mail_failed_hook_is_fired_when_provider_not_registered(): void {
		$fired = false;
		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_FAILED ] = static function () use ( &$fired ): void {
			$fired = true;
		};

		( new MailDispatcher( new ProviderRegistry(), $this->make_settings( 'smtp' ) ) )
			->dispatch( $this->make_message() );

		$this->assertTrue( $fired, 'MAIL_FAILED hook was not fired for unregistered provider.' );
	}

	public function test_mail_sent_hook_is_not_fired_when_provider_fails(): void {
		$fired = false;
		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_SENT ] = static function () use ( &$fired ): void {
			$fired = true;
		};

		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', false ) );

		( new MailDispatcher( $registry, $this->make_settings( 'smtp' ) ) )
			->dispatch( $this->make_message() );

		$this->assertFalse( $fired, 'MAIL_SENT hook must not fire on provider failure.' );
	}

	// -------------------------------------------------------------------------
	// Hook argument verification
	// -------------------------------------------------------------------------

	public function test_mail_sent_hook_receives_result_and_message_arguments(): void {
		$captured_result  = null;
		$captured_message = null;

		$GLOBALS['_test_wp_actions'][ HookNames::MAIL_SENT ] = static function (
			SendResult $result,
			MailMessage $message
		) use (
			&$captured_result,
			&$captured_message
): void {
			$captured_result  = $result;
			$captured_message = $message;
		};

		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', true ) );

		$message = $this->make_message();
		( new MailDispatcher( $registry, $this->make_settings( 'smtp' ) ) )
			->dispatch( $message );

		$this->assertInstanceOf( SendResult::class, $captured_result );
		$this->assertSame( $message, $captured_message );
	}
}
