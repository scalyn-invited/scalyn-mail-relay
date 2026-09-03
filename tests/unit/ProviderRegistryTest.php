<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

final class ProviderRegistryTest extends TestCase {

	private function make_provider( string $id, string $label = 'Test Provider' ): ProviderInterface {
		return new class( $id, $label ) implements ProviderInterface {
			public function __construct( private string $id, private string $label ) {}
			public function get_id(): string {
				return $this->id; }
			public function get_label(): string {
				return $this->label; }
			public function validate_config( array $config ): ValidationResult {
				return new ValidationResult( true ); }
			public function test_connection( array $config ): ConnectionResult {
				return new ConnectionResult( true ); }
			public function send( MailMessage $message, array $config ): SendResult {
				return new SendResult( true, $this->id ); }
			public function get_capabilities(): array {
				return array(); }
		};
	}

	public function test_register_then_get_returns_same_provider(): void {
		$registry = new ProviderRegistry();
		$provider = $this->make_provider( 'smtp' );

		$registry->register( $provider );

		$this->assertSame( $provider, $registry->get( 'smtp' ) );
	}

	public function test_has_returns_true_for_registered_provider(): void {
		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp' ) );

		$this->assertTrue( $registry->has( 'smtp' ) );
	}

	public function test_has_returns_false_for_unregistered_id(): void {
		$registry = new ProviderRegistry();

		$this->assertFalse( $registry->has( 'nonexistent' ) );
	}

	public function test_get_throws_for_unregistered_id(): void {
		$registry = new ProviderRegistry();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Mail provider "missing" is not registered.' );

		$registry->get( 'missing' );
	}

	public function test_all_returns_all_registered_providers_keyed_by_id(): void {
		$registry = new ProviderRegistry();
		$smtp     = $this->make_provider( 'smtp' );
		$api      = $this->make_provider( 'sendgrid' );

		$registry->register( $smtp );
		$registry->register( $api );

		$all = $registry->all();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'smtp', $all );
		$this->assertArrayHasKey( 'sendgrid', $all );
	}

	public function test_all_returns_empty_array_when_no_providers_registered(): void {
		$registry = new ProviderRegistry();

		$this->assertSame( array(), $registry->all() );
	}

	public function test_registering_same_id_replaces_previous_entry(): void {
		$registry = new ProviderRegistry();
		$registry->register( $this->make_provider( 'smtp', 'Old SMTP' ) );
		$registry->register( $this->make_provider( 'smtp', 'New SMTP' ) );

		$this->assertSame( 'New SMTP', $registry->get( 'smtp' )->get_label() );
		$this->assertCount( 1, $registry->all() );
	}
}
