<?php
/**
 * Pre-submission delivery associations for opted-in Postmark sends.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Delivery;

use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Database\DeliveryAttemptRepository;
use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Optional tracking never blocks, delays beyond its own writes, or changes a send.
 * When an association cannot be stored the message is still sent, untracked, and
 * its evidence coverage is later reported as unavailable rather than inferred.
 */
final class DeliveryTracker {

	/**
	 * Creates the tracker from source, key and attempt services.
	 *
	 * @param PostmarkWebhookSettings   $sources Source lifecycle.
	 * @param DeliveryKeyRepository     $keys Matching keys.
	 * @param DeliveryAttemptRepository $attempts Attempt associations.
	 * @param PostmarkProvider          $parser Postmark recipient parser shared with send().
	 */
	public function __construct(
		private readonly PostmarkWebhookSettings $sources,
		private readonly DeliveryKeyRepository $keys,
		private readonly DeliveryAttemptRepository $attempts,
		private readonly PostmarkProvider $parser
	) {}

	/**
	 * Stores the attempt and keyed recipient membership before submission.
	 *
	 * @param MailMessage $message Prepared message with its dispatch UUID.
	 * @param string      $provider_id Active provider ID.
	 * @return array|null Association for acknowledgement, or null when untracked.
	 */
	public function prepare( MailMessage $message, string $provider_id ): ?array {
		if ( 'postmark' !== $provider_id ) {
			return null;
		}
		$source = $this->sources->dispatch_source();
		if ( null === $source ) {
			return null;
		}
		$attempt = strtolower( $message->uuid );
		try {
			$addresses = $this->parser->recipient_addresses( $message );
		} catch ( \Throwable $error ) {
			// The adapter will reject the same input before submission; nothing to track.
			return null;
		}
		try {
			$tokens = $this->keys->tokens( $source['key_version'], $source['id'], $attempt, $addresses );
			unset( $addresses );
			$this->attempts->prepare(
				array(
					'message_uuid'     => $attempt,
					'source_id'        => $source['id'],
					'configuration_id' => $source['configuration_id'],
					'key_version'      => $source['key_version'],
				),
				$tokens
			);
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed text only; no addresses, tokens or SQL.
			error_log( 'Scalyn Mail Relay: delivery evidence association unavailable; message sent untracked.' );
			return null;
		}
		return array(
			'source_id'    => $source['id'],
			'message_uuid' => $attempt,
		);
	}

	/**
	 * Binds the provider acknowledgement ID; never changes the transport outcome.
	 *
	 * @param array|null $association Result of prepare().
	 * @param SendResult $result Provider result.
	 */
	public function acknowledge( ?array $association, SendResult $result ): void {
		if ( null === $association || ! $result->success || 'postmark' !== $result->provider || ! is_string( $result->provider_message_id ) ) {
			return;
		}
		try {
			$this->attempts->acknowledge( $association['source_id'], $association['message_uuid'], strtolower( $result->provider_message_id ) );
		} catch ( \Throwable $error ) {
			// A callback can still bind the identifier through the pre-submission association.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed text only.
			error_log( 'Scalyn Mail Relay: delivery acknowledgement binding unavailable.' );
		}
	}
}
