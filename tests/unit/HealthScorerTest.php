<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\HealthScorer;

/**
 * Unit tests for HealthScorer.
 *
 * Verifies the deterministic status-to-points mapping, per-category
 * averaging, exclusion of unknown/error rows, weight renormalization when
 * a component has no evidence, and the "return null rather than fabricate"
 * behavior when nothing is computable at all.
 */
final class HealthScorerTest extends TestCase {

	private function make_scorer(): HealthScorer {
		return new HealthScorer();
	}

	private function row( string $check_type, string $status ): array {
		return array(
			'check_type' => $check_type,
			'status'     => $status,
		);
	}

	// -------------------------------------------------------------------------
	// Nothing computable
	// -------------------------------------------------------------------------

	public function test_returns_null_when_no_diagnostics_and_no_mail_history(): void {
		$result = $this->make_scorer()->score( array(), array() );

		$this->assertNull( $result );
	}

	public function test_returns_null_when_only_unknown_and_error_rows_exist(): void {
		$rows = array(
			$this->row( 'dns', 'unknown' ),
			$this->row( 'smtp', 'error' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// Status -> points mapping
	// -------------------------------------------------------------------------

	public function test_all_passing_dns_checks_score_100(): void {
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'dns', 'pass' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 100, $result->dns_score );
	}

	public function test_all_failing_dns_checks_score_0(): void {
		$rows = array( $this->row( 'dns', 'fail' ) );

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 0, $result->dns_score );
	}

	public function test_mixed_pass_and_warn_averages_correctly(): void {
		// pass=100, warn=60 -> average 80.
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'dns', 'warn' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 80, $result->dns_score );
	}

	public function test_unknown_and_error_rows_are_excluded_not_penalized(): void {
		// One real pass, plus noise that must not drag the average down.
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'dns', 'unknown' ),
			$this->row( 'dns', 'error' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 100, $result->dns_score );
	}

	// -------------------------------------------------------------------------
	// Category isolation
	// -------------------------------------------------------------------------

	public function test_dns_and_provider_categories_are_scored_independently(): void {
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'smtp', 'fail' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 100, $result->dns_score );
		$this->assertSame( 0, $result->provider_score );
	}

	public function test_unrecognized_check_type_does_not_affect_any_category(): void {
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'some_future_category', 'fail' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 100, $result->dns_score );
		$this->assertNull( $result->provider_score );
	}

	// -------------------------------------------------------------------------
	// Operational reliability (mail history)
	// -------------------------------------------------------------------------

	public function test_failure_score_derived_from_accepted_and_failed_counts(): void {
		$result = $this->make_scorer()->score( array(), array( 'accepted' => 9, 'failed' => 1 ) );

		$this->assertSame( 90, $result->failure_score );
	}

	public function test_failure_score_null_when_no_mail_history(): void {
		$rows = array( $this->row( 'dns', 'pass' ) );

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertNull( $result->failure_score );
	}

	// -------------------------------------------------------------------------
	// Excluded components (V1: no source exists)
	// -------------------------------------------------------------------------

	public function test_security_score_is_always_null(): void {
		$rows = array( $this->row( 'dns', 'pass' ) );

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertNull( $result->security_score );
	}

	public function test_summary_documents_excluded_components(): void {
		$rows = array( $this->row( 'dns', 'pass' ) );

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertStringContainsString( 'Security posture', $result->summary );
		$this->assertStringContainsString( 'WordPress/system health', $result->summary );
	}

	// -------------------------------------------------------------------------
	// Weighted overall + renormalization
	// -------------------------------------------------------------------------

	public function test_overall_score_equals_component_score_when_only_one_component_available(): void {
		$rows = array( $this->row( 'dns', 'pass' ) );

		$result = $this->make_scorer()->score( $rows, array() );

		// Only DNS available -> its weight is the entire pool, so overall == dns_score.
		$this->assertSame( 100, $result->overall_score );
	}

	public function test_overall_score_is_weighted_across_available_components(): void {
		// DNS (weight 30) = 100, Provider (weight 25) = 0. No mail history.
		// Renormalized: (100*30 + 0*25) / 55 = 54.54... -> truncated to 54.
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'smtp', 'fail' ),
		);

		$result = $this->make_scorer()->score( $rows, array() );

		$this->assertSame( 54, $result->overall_score );
	}

	public function test_overall_score_uses_all_three_available_components(): void {
		// DNS(30)=100, Provider(25)=100, Failure(25)=100 -> overall 100.
		$rows = array(
			$this->row( 'dns', 'pass' ),
			$this->row( 'smtp', 'pass' ),
		);

		$result = $this->make_scorer()->score( $rows, array( 'accepted' => 10, 'failed' => 0 ) );

		$this->assertSame( 100, $result->overall_score );
	}
}
