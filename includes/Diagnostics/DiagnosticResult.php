<?php
namespace Scalyn\MailRelay\Diagnostics;
defined( 'ABSPATH' ) || exit;
final class DiagnosticResult {
	public function __construct(
		public readonly string $status,
		public readonly string $severity,
		public readonly string $message,
		public readonly string $evidence = '',
		public readonly string $impact = '',
		public readonly string $recommended_action = '',
		public readonly ?int $score = null,
		public readonly array $raw = array()
	) {}
}
