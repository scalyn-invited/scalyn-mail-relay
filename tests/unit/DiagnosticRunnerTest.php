<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;

/**
 * Unit tests for DiagnosticRunner.
 *
 * Verifies that run() executes checks in order, returns normalized results
 * paired with each check's id/category, and isolates a throwing check so it
 * cannot abort the rest of the run.
 */
final class DiagnosticRunnerTest extends TestCase {

	private function make_context(): DiagnosticContext {
		return new DiagnosticContext( 'example.com', array() );
	}

	private function make_passing_check( string $id = 'spf_record', string $category = 'dns' ): DiagnosticCheckInterface {
		return new class( $id, $category ) implements DiagnosticCheckInterface {
			public function __construct( private string $id, private string $category ) {}
			public function get_id(): string {
				return $this->id;
			}
			public function get_category(): string {
				return $this->category;
			}
			public function run( DiagnosticContext $context ): DiagnosticResult {
				return new DiagnosticResult( 'pass', 'low', 'OK' );
			}
		};
	}

	private function make_throwing_check( string $id = 'broken_check', string $category = 'dns' ): DiagnosticCheckInterface {
		return new class( $id, $category ) implements DiagnosticCheckInterface {
			public function __construct( private string $id, private string $category ) {}
			public function get_id(): string {
				return $this->id;
			}
			public function get_category(): string {
				return $this->category;
			}
			public function run( DiagnosticContext $context ): DiagnosticResult {
				throw new \RuntimeException( 'simulated check failure' );
			}
		};
	}

	// -------------------------------------------------------------------------
	// run()
	// -------------------------------------------------------------------------

	public function test_run_returns_empty_array_for_no_checks(): void {
		$this->assertSame( array(), ( new DiagnosticRunner() )->run( array(), $this->make_context() ) );
	}

	public function test_run_returns_id_category_and_result_for_a_passing_check(): void {
		$results = ( new DiagnosticRunner() )->run( array( $this->make_passing_check( 'spf_record', 'dns' ) ), $this->make_context() );

		$this->assertCount( 1, $results );
		$this->assertSame( 'spf_record', $results[0]['id'] );
		$this->assertSame( 'dns', $results[0]['category'] );
		$this->assertInstanceOf( DiagnosticResult::class, $results[0]['result'] );
		$this->assertSame( 'pass', $results[0]['result']->status );
	}

	public function test_run_executes_multiple_checks_in_order(): void {
		$checks = array(
			$this->make_passing_check( 'check_one', 'dns' ),
			$this->make_passing_check( 'check_two', 'smtp' ),
			$this->make_passing_check( 'check_three', 'security' ),
		);

		$results = ( new DiagnosticRunner() )->run( $checks, $this->make_context() );

		$this->assertSame( array( 'check_one', 'check_two', 'check_three' ), array_column( $results, 'id' ) );
		$this->assertSame( array( 'dns', 'smtp', 'security' ), array_column( $results, 'category' ) );
	}

	/**
	 * A check that throws must not abort the run: the throwing check's result is
	 * replaced with a normalized status = 'error' result, and remaining checks
	 * still execute.
	 */
	public function test_run_isolates_a_throwing_check_and_continues(): void {
		$checks = array(
			$this->make_passing_check( 'check_before', 'dns' ),
			$this->make_throwing_check( 'broken_check', 'smtp' ),
			$this->make_passing_check( 'check_after', 'security' ),
		);

		$results = ( new DiagnosticRunner() )->run( $checks, $this->make_context() );

		$this->assertCount( 3, $results );
		$this->assertSame( 'pass', $results[0]['result']->status );
		$this->assertSame( 'error', $results[1]['result']->status );
		$this->assertSame( 'pass', $results[2]['result']->status );
	}

	public function test_run_error_result_for_throwing_check_names_the_check(): void {
		$results = ( new DiagnosticRunner() )->run( array( $this->make_throwing_check( 'broken_check' ) ), $this->make_context() );

		$this->assertStringContainsString( 'broken_check', $results[0]['result']->message );
	}

	/**
	 * The error result substituted for a throwing check must never leak the
	 * underlying exception message, which could contain internal detail the
	 * check author did not intend to expose.
	 */
	public function test_run_error_result_does_not_leak_exception_message(): void {
		$results = ( new DiagnosticRunner() )->run( array( $this->make_throwing_check() ), $this->make_context() );

		$this->assertStringNotContainsString( 'simulated check failure', $results[0]['result']->message );
	}

	public function test_run_error_result_preserves_the_checks_id_and_category(): void {
		$results = ( new DiagnosticRunner() )->run( array( $this->make_throwing_check( 'broken_check', 'smtp' ) ), $this->make_context() );

		$this->assertSame( 'broken_check', $results[0]['id'] );
		$this->assertSame( 'smtp', $results[0]['category'] );
	}

	/**
	 * Defensive handling for malformed input: a non-DiagnosticCheckInterface
	 * value in the $checks array is skipped rather than causing a fatal error.
	 */
	public function test_run_skips_non_diagnostic_check_entries(): void {
		$checks = array(
			$this->make_passing_check( 'check_one' ),
			'not-a-check',
			null,
			$this->make_passing_check( 'check_two' ),
		);

		$results = ( new DiagnosticRunner() )->run( $checks, $this->make_context() );

		$this->assertSame( array( 'check_one', 'check_two' ), array_column( $results, 'id' ) );
	}
}
