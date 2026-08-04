<?php
/**
 * Cross-module WordPress action hook name constants.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical names for all cross-module WordPress action hooks.
 *
 * Every do_action() and add_action() call within this plugin must use these
 * constants rather than bare string literals. This prevents mismatched hook
 * names across modules owned by different developers and makes the event
 * surface of the plugin explicit and searchable.
 *
 * Hook argument documentation is listed per constant. Subscriber callbacks
 * must treat all passed objects as read-only value objects.
 *
 * @see MailDispatcher for the hooks fired during mail dispatch.
 */
final class HookNames {

	/**
	 * Fired when a MailMessage has been validated and is ready for transport,
	 * before the provider send() call is made.
	 *
	 * Arguments: ( \Scalyn\MailRelay\Mail\MailMessage $message )
	 */
	public const MAIL_PREPARED = 'scalyn_mail_relay_mail_prepared';

	/**
	 * Fired when a provider has accepted the message (send returned success = true).
	 *
	 * Subscribers use this hook to write mail log entries and timeline events.
	 * Provider acceptance does NOT confirm end-to-end delivery to the recipient.
	 *
	 * Arguments: ( \Scalyn\MailRelay\Mail\SendResult $result, \Scalyn\MailRelay\Mail\MailMessage $message )
	 */
	public const MAIL_SENT = 'scalyn_mail_relay_mail_sent';

	/**
	 * Fired when a provider returns a failure result (send returned success = false),
	 * or when dispatch cannot proceed due to a configuration problem.
	 *
	 * Subscribers use this hook for failure logging, alerting, and retry scheduling.
	 *
	 * Arguments: ( \Scalyn\MailRelay\Mail\SendResult $result, \Scalyn\MailRelay\Mail\MailMessage $message )
	 */
	public const MAIL_FAILED = 'scalyn_mail_relay_mail_failed';

	/**
	 * Fired after a provider connection test completes (success or failure).
	 *
	 * Subscribers may use this for audit logging. The result indicates whether
	 * the connection succeeded; inspect result->success to determine outcome.
	 *
	 * Arguments: ( \Scalyn\MailRelay\Providers\ConnectionResult $result, string $provider_id )
	 */
	public const CONNECTION_TESTED = 'scalyn_mail_relay_connection_tested';
}
