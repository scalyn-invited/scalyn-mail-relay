<?php
/**
 * Result of one diagnostics-retention cleanup batch.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Database;

defined( 'ABSPATH' ) || exit;

/** Immutable, non-sensitive counts for diagnostics retention. */
final readonly class DiagnosticRetentionCleanupResult {

	/**
	 * Creates a cleanup result.
	 *
	 * @param string $cutoff                   Exclusive site-time expiry boundary.
	 * @param int    $selected_runs            Expired diagnostic run groups selected.
	 * @param int    $deleted_diagnostic_rows  Diagnostic result rows deleted.
	 * @param int    $selected_health_scores   Expired health snapshots selected.
	 * @param int    $deleted_health_scores    Health snapshot rows deleted.
	 */
	public function __construct(
		public string $cutoff,
		public int $selected_runs,
		public int $deleted_diagnostic_rows,
		public int $selected_health_scores,
		public int $deleted_health_scores
	) {}
}
