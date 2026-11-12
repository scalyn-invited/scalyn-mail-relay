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

/**
 * Contract that every mail provider must implement.
 *
 * A provider encapsulates the logic for a single mail transport (e.g. SMTP,
 * SendGrid API, Mailgun API). Providers are registered with ProviderRegistry
 * and invoked by MailDispatcher.
 *
 * get_capabilities() returns a plain string array of feature flags.
 * Recognized values (non-exhaustive): 'html', 'attachments', 'tracking'.
 */
interface ProviderInterface {

	/**
	 * Returns the unique machine-readable identifier for this provider.
	 *
	 * @return string e.g. 'smtp', 'sendgrid'.
	 */
	public function get_id(): string;

	/**
	 * Returns the human-readable display name for this provider.
	 *
	 * @return string e.g. 'SMTP (PHPMailer)', 'SendGrid'.
	 */
	public function get_label(): string;

	/**
	 * Validates the provided configuration array for this provider.
	 *
	 * Must not make any network calls.
	 *
	 * @param array<string, mixed> $config Provider configuration (from SettingsRepository).
	 * @return ValidationResult
	 */
	public function validate_config( array $config ): ValidationResult;

	/**
	 * Tests whether the provider can establish a connection with the current configuration.
	 *
	 * @param array<string, mixed> $config Provider configuration (from SettingsRepository).
	 * @return ConnectionResult
	 */
	public function test_connection( array $config ): ConnectionResult;

	/**
	 * Sends a mail message through this provider.
	 *
	 * @param MailMessage          $message The prepared message to send.
	 * @param array<string, mixed> $config  Provider configuration (from SettingsRepository).
	 * @return SendResult The normalized result of the send attempt.
	 */
	public function send( MailMessage $message, array $config ): SendResult;

	/**
	 * Returns a list of feature flags supported by this provider.
	 *
	 * @return string[] e.g. [ 'html', 'attachments' ].
	 */
	public function get_capabilities(): array;
}
