<?php
namespace Scalyn\MailRelay\Providers;
defined( 'ABSPATH' ) || exit;
final class ConnectionResult {
	public function __construct( public readonly bool $success, public readonly string $message = '', public readonly array $metadata = array() ) {}
}
