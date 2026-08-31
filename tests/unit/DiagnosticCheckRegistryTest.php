<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

final class DiagnosticCheckRegistryTest extends TestCase {

	public function test_register_adds_check_to_registry(): void {
		$registry = new DiagnosticCheckRegistry();
		$check    = new MockDiagnosticCheck( 'test_check', 'test_category' );

		$registry->register( $check );

		$this->assertTrue( $registry->has( 'test_check' ) );
		$this->assertSame( $check, $registry->get( 'test_check' ) );
	}

	public function test_get_all_returns_all_registered_checks(): void {
		$registry = new DiagnosticCheckRegistry();
		$check1   = new MockDiagnosticCheck( 'check1', 'category1' );
		$check2   = new MockDiagnosticCheck( 'check2', 'category2' );

		$registry->register( $check1 );
		$registry->register( $check2 );

		$all = $registry->get_all();

		$this->assertCount( 2, $all );
		$this->assertSame( $check1, $all['check1'] );
		$this->assertSame( $check2, $all['check2'] );
	}

	public function test_get_returns_null_for_unregistered_check(): void {
		$registry = new DiagnosticCheckRegistry();

		$this->assertNull( $registry->get( 'nonexistent_check' ) );
	}

	public function test_has_returns_false_for_unregistered_check(): void {
		$registry = new DiagnosticCheckRegistry();

		$this->assertFalse( $registry->has( 'nonexistent_check' ) );
	}

	public function test_duplicate_registration_replaces_previous_check(): void {
		$registry = new DiagnosticCheckRegistry();
		$check1   = new MockDiagnosticCheck( 'test_check', 'category1' );
		$check2   = new MockDiagnosticCheck( 'test_check', 'category2' );

		$registry->register( $check1 );
		$registry->register( $check2 );

		$this->assertSame( $check2, $registry->get( 'test_check' ) );
	}

	public function test_count_returns_number_of_registered_checks(): void {
		$registry = new DiagnosticCheckRegistry();

		$this->assertSame( 0, $registry->count() );

		$registry->register( new MockDiagnosticCheck( 'check1', 'category' ) );
		$this->assertSame( 1, $registry->count() );

		$registry->register( new MockDiagnosticCheck( 'check2', 'category' ) );
		$this->assertSame( 2, $registry->count() );
	}

	public function test_get_all_can_be_called_multiple_times(): void {
		$registry = new DiagnosticCheckRegistry();
		$check    = new MockDiagnosticCheck( 'test_check', 'category' );

		$registry->register( $check );

		$all1 = $registry->get_all();
		$all2 = $registry->get_all();

		$this->assertSame( $all1, $all2 );
	}
}

/**
 * Mock diagnostic check for testing.
 */
final class MockDiagnosticCheck implements DiagnosticCheckInterface {

	private string $id;
	private string $category;

	public function __construct( string $id, string $category ) {
		$this->id       = $id;
		$this->category = $category;
	}

	public function get_id(): string {
		return $this->id;
	}

	public function get_category(): string {
		return $this->category;
	}

	public function run( DiagnosticContext $context ): DiagnosticResult {
		return new DiagnosticResult(
			status: 'pass',
			severity: 'low',
			message: 'Mock check passed'
		);
	}
}
