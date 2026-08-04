<?php
namespace Scalyn\MailRelay\Providers;
defined( 'ABSPATH' ) || exit;
final class ValidationResult {
	public function __construct( public readonly bool $valid, public readonly array $errors = array() ) {}
}
