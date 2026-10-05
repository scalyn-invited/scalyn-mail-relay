<?php
/**
 * Provider health assessment rules tests.
 *
 * @package ScalynMailRelay
 */

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Health\ProviderHealthAssessment;

/** Tests configuration-scoped provider-health assessment rules. */
final class ProviderHealthAssessmentTest extends TestCase {
	private const REVISION = 'a823862b-5478-41b5-be43-3b067b1d5021';

	/** Tests the critical submission-failure threshold. */
	public function test_critical_requires_more_than_twenty_percent_failures_with_minimum_attempts(): void {
		$assessment = ProviderHealthAssessment::evaluate(
			'smtp',
			self::REVISION,
			array(
				'status'     => 'passed',
				'checked_at' => '2026-10-05 01:00:00.000000',
			),
			array(
				'accepted' => 7,
				'failed'   => 3,
			),
			array(
				'accepted' => 7,
				'failed'   => 3,
			)
		);

		$this->assertSame( 'critical', $assessment['health'] );
		$this->assertSame( 0.3, $assessment['failure_rate'] );
		$this->assertStringContainsString( 'Review recent failed submissions', $assessment['recommended_action'] );
	}

	/** Tests the warning submission-failure threshold. */
	public function test_warning_starts_at_five_percent_failures(): void {
		$assessment = ProviderHealthAssessment::evaluate(
			'smtp',
			self::REVISION,
			array(
				'status'     => 'passed',
				'checked_at' => '2026-10-05 01:00:00.000000',
			),
			array(
				'accepted' => 19,
				'failed'   => 1,
			),
			array(
				'accepted' => 19,
				'failed'   => 1,
			)
		);

		$this->assertSame( 'warning', $assessment['health'] );
	}

	/** Tests that fresh, adequate evidence can be healthy. */
	public function test_fresh_connection_and_enough_clean_current_revision_submissions_are_healthy(): void {
		$assessment = ProviderHealthAssessment::evaluate(
			'smtp',
			self::REVISION,
			array(
				'status'     => 'passed',
				'checked_at' => '2026-10-05 01:00:00.000000',
			),
			array(
				'accepted' => 9,
				'failed'   => 0,
			),
			array(
				'accepted' => 10,
				'failed'   => 0,
			)
		);

		$this->assertSame( 'healthy', $assessment['health'] );
		$this->assertSame( 'passed', $assessment['connection']['status'] );
	}

	/** Tests stale connection evidence remains advisory. */
	public function test_stale_connection_is_a_warning_without_becoming_a_failure(): void {
		$assessment = ProviderHealthAssessment::evaluate(
			'smtp',
			self::REVISION,
			array(
				'status'     => 'stale',
				'checked_at' => '2026-09-20 01:00:00.000000',
			),
			array(
				'accepted' => 0,
				'failed'   => 0,
			),
			array(
				'accepted' => 20,
				'failed'   => 0,
			)
		);

		$this->assertSame( 'warning', $assessment['health'] );
		$this->assertSame( 0, $assessment['window_24h']['failed'] );
	}

	/** Tests a generic failed check does not imply a critical failure. */
	public function test_generic_connection_failure_is_unknown_without_a_persisted_failure_category(): void {
		$assessment = ProviderHealthAssessment::evaluate(
			'smtp',
			self::REVISION,
			array(
				'status'     => 'failed',
				'checked_at' => '2026-10-05 01:00:00.000000',
			),
			array(
				'accepted' => 0,
				'failed'   => 0,
			),
			array(
				'accepted' => 0,
				'failed'   => 0,
			)
		);

		$this->assertSame( 'unknown', $assessment['health'] );
		$this->assertStringContainsString( 'not retained', $assessment['findings'][0] );
	}

	/** Tests missing evidence remains explicitly unknown. */
	public function test_insufficient_or_unavailable_evidence_is_unknown_not_healthy(): void {
		$assessment = ProviderHealthAssessment::evaluate(
			'postmark',
			self::REVISION,
			array(
				'status'     => 'unknown',
				'checked_at' => null,
			),
			null,
			null
		);

		$this->assertSame( 'unknown', $assessment['health'] );
		$this->assertFalse( $assessment['window_24h']['available'] );
		$this->assertStringContainsString( 'Bounce-rate assessment', $assessment['limitations'][2] );
		$this->assertStringContainsString( 'Run a connection check', $assessment['recommended_action'] );
	}
}
