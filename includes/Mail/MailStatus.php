<?php
/**
 * Mail delivery lifecycle status constants.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical status identifiers for each stage of the mail delivery lifecycle.
 *
 * Every module that reads or writes a mail status string — database repositories,
 * REST responses, UI labels, log writers — must reference these constants.
 * Never use bare string literals for status values.
 *
 * Lifecycle order (normal success path):
 *   GENERATED → PREPARED → CONNECTED → AUTHENTICATED → SENT → ACCEPTED
 *
 * Failure can occur at any stage and is recorded as FAILED.
 * A subsequent attempt after failure is recorded as RETRIED.
 *
 * IMPORTANT: Do not add 'delivered' to the send path. A provider accepting a
 * message (ACCEPTED) is not the same as confirmed end-to-end delivery. Delivery
 * confirmation requires out-of-band evidence (e.g. provider webhook callbacks)
 * and must never be inferred from a successful SMTP transaction alone.
 */
final class MailStatus {

	/** Message has been created but not yet sent to a transport provider. */
	public const GENERATED = 'generated';

	/** Message has been built and validated; ready for dispatch. */
	public const PREPARED = 'prepared';

	/** A network connection to the mail service has been established. */
	public const CONNECTED = 'connected';

	/** Successfully authenticated with the mail service. */
	public const AUTHENTICATED = 'authenticated';

	/** Message data has been submitted to the provider; awaiting acknowledgement. */
	public const SENT = 'sent';

	/**
	 * Provider has acknowledged acceptance of the message for delivery.
	 * For SMTP this corresponds to a 250 response. For API providers a 2xx response.
	 * Acceptance is NOT a guarantee of delivery to the recipient's inbox.
	 */
	public const ACCEPTED = 'accepted';

	/** Provider returned a failure response or a network/configuration error occurred. */
	public const FAILED = 'failed';

	/** A send has been retried after a previous failure. */
	public const RETRIED = 'retried';

	/**
	 * Returns all defined status values.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::GENERATED,
			self::PREPARED,
			self::CONNECTED,
			self::AUTHENTICATED,
			self::SENT,
			self::ACCEPTED,
			self::FAILED,
			self::RETRIED,
		);
	}
}
