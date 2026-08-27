<?php
/**
 * Diagnostic check execution engine.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Executes a set of DiagnosticCheckInterface implementations against a shared
 * DiagnosticContext and returns normalized, isolated results.
 *
 * Each check runs independently: if a check throws instead of returning a
 * DiagnosticResult, the runner catches it and substitutes a normalized
 * status = 'error' result so one broken check cannot abort the rest of the run.
 *
 * This class only executes checks and returns results in memory. Persisting
 * results (DiagnosticRepository) and orchestrating scheduled/triggered runs
 * are separate concerns handled elsewhere.
 *
 * Ownership: Yaj / Diagnostics.
 */
final class DiagnosticRunner {

	/**
	 * Runs every supplied check against the given context.
	 *
	 * Execution order matches array order. A check that throws any Throwable is
	 * recorded as a status = 'error' result rather than propagating, so a single
	 * defective check cannot prevent the remaining checks from running.
	 *
	 * @param DiagnosticCheckInterface[] $checks  Checks to execute, in order.
	 * @param DiagnosticContext          $context Shared execution context.
	 * @return array<int, array{id: string, category: string, result: DiagnosticResult}>
	 */
	public function run( array $checks, DiagnosticContext $context ): array {
		$results = array();

		foreach ( $checks as $check ) {
			if ( ! $check instanceof DiagnosticCheckInterface ) {
				continue;
			}

			$results[] = array(
				'id'       => $check->get_id(),
				'category' => $check->get_category(),
				'result'   => $this->run_check( $check, $context ),
			);
		}

		return $results;
	}

	/**
	 * Runs a single check, converting any thrown Throwable into a normalized
	 * error result instead of letting it propagate to the caller.
	 *
	 * @param DiagnosticCheckInterface $check   The check to execute.
	 * @param DiagnosticContext        $context Shared execution context.
	 */
	private function run_check( DiagnosticCheckInterface $check, DiagnosticContext $context ): DiagnosticResult {
		try {
			return $check->run( $context );
		} catch ( Throwable $e ) {
			// Fixed, check-id-only message: the exception itself is deliberately not
			// included, so a bug inside a check can never leak internal detail here.
			return new DiagnosticResult(
				status: 'error',
				severity: 'high',
				message: sprintf( 'The "%s" diagnostic check failed to run.', $check->get_id() )
			);
		}
	}
}
