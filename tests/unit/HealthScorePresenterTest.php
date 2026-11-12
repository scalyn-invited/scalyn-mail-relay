<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\HealthScorePresenter;

/**
 * Tests for HealthScorePresenter, the single presentation path for the
 * persisted health score used by both the Dashboard and Diagnostics pages.
 */
final class HealthScorePresenterTest extends TestCase {

	public function test_null_row_presents_unknown(): void {
		$view = HealthScorePresenter::present( null );

		$this->assertNull( $view['score'] );
		$this->assertSame( 'unknown', $view['ui_status'] );
		$this->assertSame( 'Unknown', $view['label'] );
		$this->assertSame( '', $view['summary'] );
		$this->assertNull( $view['created_at'] );
		$this->assertCount( 3, $view['components'] );
		foreach ( $view['components'] as $value ) {
			$this->assertNull( $value );
		}
	}

	public function test_row_values_are_cast_from_database_strings(): void {
		$view = HealthScorePresenter::present(
			array(
				'overall_score'  => '85',
				'dns_score'      => '90',
				'provider_score' => null,
				'failure_score'  => '75',
				'summary'        => ' Health score based on: DNS & authentication, Operational reliability. ',
				'created_at'     => '2026-09-04 10:00:00',
			)
		);

		$this->assertSame( 85, $view['score'] );
		$this->assertSame( 'healthy', $view['ui_status'] );
		$this->assertSame( '85/100', $view['label'] );
		$this->assertSame( 'Health score based on: DNS & authentication, Operational reliability.', $view['summary'] );
		$this->assertSame( '2026-09-04 10:00:00', $view['created_at'] );
		$this->assertSame(
			array(
				'DNS & authentication'    => 90,
				'Provider & transport'    => null,
				'Operational reliability' => 75,
			),
			$view['components']
		);
	}

	/**
	 * @dataProvider threshold_provider
	 */
	public function test_status_thresholds( int $score, string $expected ): void {
		$this->assertSame( $expected, HealthScorePresenter::ui_status( $score ) );
	}

	public static function threshold_provider(): array {
		return array(
			array( 100, 'healthy' ),
			array( 80, 'healthy' ),
			array( 79, 'warning' ),
			array( 60, 'warning' ),
			array( 59, 'critical' ),
			array( 0, 'critical' ),
		);
	}

	/**
	 * Regression guard for the QA report: a score derived solely from mail
	 * history must still present its number, but the breakdown must show the
	 * diagnostic components as not evaluated rather than inventing values.
	 */
	public function test_score_with_only_operational_evidence_marks_other_components_not_evaluated(): void {
		$view = HealthScorePresenter::present(
			array(
				'overall_score'  => 100,
				'dns_score'      => null,
				'provider_score' => null,
				'failure_score'  => 100,
				'summary'        => 'Health score based on: Operational reliability.',
			)
		);

		$this->assertSame( 100, $view['score'] );
		$this->assertNull( $view['components']['DNS & authentication'] );
		$this->assertNull( $view['components']['Provider & transport'] );
		$this->assertSame( 100, $view['components']['Operational reliability'] );
	}
}
