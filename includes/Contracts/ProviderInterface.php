<?php
/**
 * Mail provider contract.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Contracts;

use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

defined( 'ABSPATH' ) || exit;

interface ProviderInterface {
	public function get_id(): string;
	public function get_label(): string;
	public function validate_config( array $config ): ValidationResult;
	public function test_connection( array $config ): ConnectionResult;
	public function send( MailMessage $message, array $config ): SendResult;
	public function get_capabilities(): array;
}
