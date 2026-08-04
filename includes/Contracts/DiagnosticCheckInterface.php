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

interface DiagnosticCheckInterface {
	public function get_id(): string;
	public function get_category(): string;
	public function run( DiagnosticContext $context ): DiagnosticResult;
}
