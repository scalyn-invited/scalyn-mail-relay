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

	public const FIELDS = array( 'provider.active', 'smtp.host', 'smtp.port', 'smtp.encryption', 'smtp.username', 'smtp.password', 'smtp.from_name', 'smtp.from_email', 'advanced.log_retention_days', 'advanced.delete_data_on_uninstall', 'advanced.diagnostic_schedule', 'advanced.alert_webhook_enabled', 'advanced.dkim_selector' );

	private const OUTCOMES = array(
		'alert_incident'           => array( 'opened', 'resolved' ),
		'alert_notification'       => array( 'sent', 'failed', 'skipped', 'retry' ),
		'settings_changed'         => array( 'changed' ),
		'retention_changed'        => array( 'changed' ),
		'uninstall_policy_changed' => array( 'changed' ),
		'provider_verification'    => array( 'started', 'verified', 'failed' ),
		'test_email'               => array( 'started', 'accepted', 'failed' ),
		'diagnostic_run'           => array( 'started', 'completed', 'failed' ),
	);

	/**
	 * Creates a validated event, rejecting unknown values before persistence.
	 *
	 * @param string $action Fixed operation name.
	 * @param string $outcome Fixed observed outcome.
	 * @param string $correlation_id Optional UUID, never an address/domain/provider response.
	 * @param array  $changed_fields Recognized field names only; no values.
	 * @throws \InvalidArgumentException When any part of the event is not allowed.
	 */
	public function __construct(
		public string $action,
		public string $outcome,
		public string $correlation_id = '',
		public array $changed_fields = array()
	) {
		$this->actor = AuditActor::capture();
		if ( ! isset( self::OUTCOMES[ $action ] ) || ! in_array( $outcome, self::OUTCOMES[ $action ], true )
			|| ( '' !== $correlation_id && 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $correlation_id ) )
			|| count( $changed_fields ) > count( self::FIELDS ) ) {
			throw new \InvalidArgumentException( 'Invalid audit event.' );
		}
		foreach ( $changed_fields as $field ) {
			if ( ! in_array( $field, self::FIELDS, true ) ) {
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
		return array(
			'version'        => 2,
			'source'         => $this->actor->source,
			'outcome'        => $this->outcome,
			'changed_fields' => array_values( array_unique( $this->changed_fields ) ),
		);
	}
}
