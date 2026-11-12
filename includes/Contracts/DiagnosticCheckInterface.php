<?php
/**
 * Diagnostic check contract.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Contracts;

use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;

defined( 'ABSPATH' ) || exit;

/**
 * Contract that every diagnostic check must implement.
 *
 * Diagnostic checks are discrete, runnable units of analysis (e.g. SPF record
 * lookup, DKIM verification, SMTP port availability). They are invoked by the
 * diagnostic runner with a shared DiagnosticContext and return a DiagnosticResult.
 */
interface DiagnosticCheckInterface {

	/**
	 * Returns the unique machine-readable identifier for this check.
	 *
	 * @return string e.g. 'spf_record', 'dkim_record', 'smtp_port'.
	 */
	public function get_id(): string;

	/**
	 * Returns the category this check belongs to.
	 *
	 * @return string e.g. 'dns', 'smtp', 'security'.
	 */
	public function get_category(): string;

	/**
	 * Executes the diagnostic check and returns a normalized result.
	 *
	 * Implementations must never store credentials or secrets in the returned
	 * DiagnosticResult::$evidence or DiagnosticResult::$raw fields.
	 *
	 * @param DiagnosticContext $context The execution context for this check run.
	 * @return DiagnosticResult
	 */
	public function run( DiagnosticContext $context ): DiagnosticResult;
}
