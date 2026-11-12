<?php
/**
 * Health score result value object.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result returned by HealthScorer::score().
 *
 * Component scores are null when that component could not be evaluated
 * (no evidence available), not when it scored zero. $summary documents
 * exactly which components were included/excluded and why, so the result
 * is always traceable to evidence rather than presented as a black box.
 */
final class HealthScoreResult {

	/**
	 * Constructs a health score result.
	 *
	 * @param int      $overall_score  Weighted overall score (0-100), renormalized across
	 *                                 only the components that had evidence.
	 * @param int|null $dns_score      DNS & authentication component (0-100), or null if excluded.
	 * @param int|null $provider_score Provider & transport component (0-100), or null if excluded.
	 * @param int|null $failure_score  Operational reliability component (0-100), or null if excluded.
	 * @param int|null $security_score Security posture component (0-100), or null if excluded.
	 * @param string   $summary        Plain-English explanation of what was included/excluded.
	 */
	public function __construct(
		public readonly int $overall_score,
		public readonly ?int $dns_score,
		public readonly ?int $provider_score,
		public readonly ?int $failure_score,
		public readonly ?int $security_score,
		public readonly string $summary
	) {}
}
