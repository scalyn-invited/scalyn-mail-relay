<?php
namespace Scalyn\MailRelay\Diagnostics;
defined( 'ABSPATH' ) || exit;
final class DiagnosticContext {
	public function __construct( public readonly string $domain, public readonly array $settings = array() ) {}
}
