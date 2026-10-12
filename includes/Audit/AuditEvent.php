<?php
/**
 * Allowlisted audit event contract.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Audit;

defined( 'ABSPATH' ) || exit;

/** Immutable events accept no arbitrary metadata or free-form messages. */
final readonly class AuditEvent {

	/**
	 * Trusted identity captured when the event is created.
	 *
	 * @var AuditActor
	 */
	public AuditActor $actor;

	public const FIELDS = array( 'provider.active', 'smtp.host', 'smtp.port', 'smtp.encryption', 'smtp.username', 'smtp.password', 'smtp.from_name', 'smtp.from_email', 'sendgrid.api_key', 'sendgrid.from_name', 'sendgrid.from_email', 'postmark.api_key', 'brevo.api_key', 'brevo.from_email', 'brevo.from_name', 'smtp2go.api_key', 'smtp2go.from_email', 'smtp2go.from_name', 'postmark.from_name', 'postmark.from_email', 'advanced.log_retention_days', 'advanced.log_message_metadata', 'advanced.delete_data_on_uninstall', 'advanced.diagnostic_schedule', 'advanced.alert_webhook_enabled', 'advanced.dkim_selector' );

	public const AGENCY_FIELDS   = array( 'agency.managed', 'agency.smtp', 'agency.providers', 'agency.critical' );
	public const BRANDING_FIELDS = array( 'branding.name', 'branding.logo_id', 'branding.primary', 'branding.secondary', 'branding.support_email', 'branding.support_url', 'branding.docs_url' );

	private const OUTCOMES = array(
		'recovery_work'            => array( 'owned', 'accepted', 'review', 'retry_evaluated', 'stopped' ),
		'recovery_policy'          => array( 'enabled', 'disabled' ),
		'recovery_action'          => array( 'cancelled', 'cancel_denied' ),
		'routing_policy'           => array( 'changed' ),
		'configuration'            => array( 'import', 'create', 'select', 'rename', 'archive' ),
		'client_report'            => array( 'prepared' ),
		'agency_branding'          => array( 'changed' ),
		'agency_policy'            => array( 'changed' ),
		'agency_access'            => array( 'changed' ),
		'report_export'            => array( 'started', 'prepared', 'failed' ),
		'alert_incident'           => array( 'opened', 'resolved' ),
		'alert_notification'       => array( 'sent', 'failed', 'skipped', 'retry' ),
		'settings_changed'         => array( 'changed' ),
		'webhook_configuration'    => array( 'saved_disabled', 'removed', 'verified', 'verification_failed', 'enabled', 'disabled' ),
		'retention_changed'        => array( 'changed' ),
		'uninstall_policy_changed' => array( 'changed' ),
		'provider_verification'    => array( 'started', 'verified', 'failed' ),
		'test_email'               => array( 'started', 'accepted', 'failed', 'unconfirmed' ),
		'diagnostic_run'           => array( 'started', 'completed', 'failed' ),
	);

	/**
	 * Creates a validated event, rejecting unknown values before persistence.
	 *
	 * @param string $action Fixed operation name.
	 * @param string $outcome Fixed observed outcome.
	 * @param string $correlation_id Optional UUID, never an address/domain/provider response.
	 * @param array  $changed_fields Recognized field names only; no values.
	 * @param array  $export Fixed export metadata, permitted only for report exports.
	 * @param array  $access Site member ID and allowlisted profile, only for agency access events.
	 * @throws \InvalidArgumentException When any part of the event is not allowed.
	 */
	public function __construct(
		public string $action,
		public string $outcome,
		public string $correlation_id = '',
		public array $changed_fields = array(),
		public array $export = array(),
		public array $access = array()
	) {
		$this->actor = AuditActor::capture();
		if ( 'agency_access' === $action ) {
			if ( 2 !== count( $access ) || ! is_int( $access['user_id'] ?? null ) || $access['user_id'] < 1
				|| ! in_array( $access['profile'] ?? null, array( 'none', 'agency', 'support', 'auditor', 'client' ), true )
				|| '' === $correlation_id || array() !== $changed_fields ) {
				throw new \InvalidArgumentException( 'Invalid agency access audit event.' );
			}
		} elseif ( array() !== $access ) {
			throw new \InvalidArgumentException( 'Invalid agency access audit event.' );
		}
		if ( 'report_export' === $action ) {
			if ( 3 !== count( $export ) || ! in_array( $export['format'] ?? null, array( 'csv', 'json', 'pdf' ), true )
				|| ! is_bool( $export['references'] ?? null ) || ! is_string( $export['report_uuid'] ?? null )
				|| ( '' !== $export['report_uuid'] && 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $export['report_uuid'] ) )
				|| ( 'prepared' === $outcome && '' === $export['report_uuid'] ) || '' === $correlation_id || array() !== $changed_fields ) {
				throw new \InvalidArgumentException( 'Invalid export audit event.' );
			}
		} elseif ( array() !== $export ) {
			throw new \InvalidArgumentException( 'Invalid export audit event.' );
		}
		if ( ! isset( self::OUTCOMES[ $action ] ) || ! in_array( $outcome, self::OUTCOMES[ $action ], true )
			|| ( '' !== $correlation_id && 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $correlation_id ) )
			|| count( $changed_fields ) > count( self::FIELDS ) + count( self::AGENCY_FIELDS ) ) {
			throw new \InvalidArgumentException( 'Invalid audit event.' );
		}
		foreach ( $changed_fields as $field ) {
			$allowed_fields = match ( $action ) {
				'agency_policy' => self::AGENCY_FIELDS,
				'agency_branding' => self::BRANDING_FIELDS,
				default => self::FIELDS,
			};
			if ( ! in_array( $field, $allowed_fields, true ) ) {
				throw new \InvalidArgumentException( 'Invalid audit field.' );
			}
		}
	}

	/**
	 * Returns the only metadata permitted in storage.
	 *
	 * @return array Versioned metadata without sensitive values.
	 */
	public function metadata(): array {
		$metadata = array(
			'version'        => 2,
			'source'         => $this->actor->source,
			'outcome'        => $this->outcome,
			'changed_fields' => array_values( array_unique( $this->changed_fields ) ),
		);
		if ( 'report_export' === $this->action ) {
			$metadata['version'] = 3;
			$metadata['export']  = $this->export;
		}
		if ( 'agency_access' === $this->action ) {
			$metadata['version'] = 4;
			$metadata['access']  = $this->access;
		}
		return $metadata;
	}
}
