<?php
/**
 * Strict site-local managed policy storage.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Agency;

use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;

defined( 'ABSPATH' ) || exit;

/** Stores only policy booleans; credentials and mail data never enter this option. */
final class AgencyRepository {

	public const OPTION = 'scalyn_mail_relay_agency';
	public const LOCKS  = array( 'smtp', 'providers', 'critical' );

	/**
	 * Returns a fixed policy projection; malformed saved policies fail closed.
	 *
	 * @return array<string, bool> Policy.
	 */
	public function policy(): array {
		$stored = get_option( self::OPTION, null );
		if ( null === $stored ) {
			return array(
				'managed'   => false,
				'smtp'      => false,
				'providers' => false,
				'critical'  => false,
			);
		}
		$safe = array();
		foreach ( array( 'managed', 'smtp', 'providers', 'critical' ) as $field ) {
			$safe[ $field ] = is_array( $stored ) && is_bool( $stored[ $field ] ?? null ) ? $stored[ $field ] : true;
		}
		return $safe;
	}

	/**
	 * Saves a complete explicitly authorized policy, with value-free audit history.
	 *
	 * @param array $input Exact policy booleans.
	 * @throws \RuntimeException When denied, invalid or not durably saved.
	 */
	public function save_policy( array $input ): void {
		$this->authorize();
		$fields = array( 'managed', 'smtp', 'providers', 'critical' );
		if ( count( $input ) !== count( $fields ) || array_diff( $fields, array_keys( $input ) ) ) {
			throw new \RuntimeException( 'Invalid managed settings policy.' );
		}
		$next = array();
		foreach ( $fields as $field ) {
			if ( ! is_bool( $input[ $field ] ) ) {
				throw new \RuntimeException( 'Invalid managed settings policy.' );
			}
			$next[ $field ] = $input[ $field ];
		}
		$before = $this->policy();
		if ( $before === $next ) {
			return;
		}
		if ( ! update_option( self::OPTION, $next, false ) && $this->policy() !== $next ) {
			throw new \RuntimeException( 'Managed settings could not be saved.' );
		}
		$changed = array();
		foreach ( $fields as $field ) {
			if ( $before[ $field ] !== $next[ $field ] ) {
				$changed[] = 'agency.' . $field;
			}
		}
		$this->audit( new AuditEvent( 'agency_policy', 'changed', wp_generate_uuid4(), $changed ) );
	}

	/**
	 * Assigns only plugin access. WordPress roles and other capabilities are untouched.
	 *
	 * @param int    $user_id Existing site member.
	 * @param string $profile Allowlisted access profile.
	 * @throws \RuntimeException When denied or invalid.
	 */
	public function assign_profile( int $user_id, string $profile ): void {
		$this->authorize();
		if ( $user_id < 1 || ! array_key_exists( $profile, ManagedAccess::profiles() )
			|| ! get_userdata( $user_id ) || ! is_user_member_of_blog( $user_id ) ) {
			throw new \RuntimeException( 'Choose an existing site member and valid access profile.' );
		}
		// Non-administrator managers cannot promote themselves or delegate agency authority.
		if ( ! current_user_can( 'manage_options' )
			&& ( 'agency' === $profile || get_current_user_id() === $user_id
				|| user_can( $user_id, 'manage_options' ) || user_can( $user_id, Capabilities::MANAGE_AGENCY ) ) ) {
			throw new \RuntimeException( 'Only an administrator can change agency-manager access.' );
		}
		$before = get_user_meta( $user_id, ManagedAccess::profile_key(), true );
		if ( $before === $profile || ( 'none' === $profile && '' === $before ) ) {
			return;
		}
		update_user_meta( $user_id, ManagedAccess::profile_key(), $profile );
		if ( get_user_meta( $user_id, ManagedAccess::profile_key(), true ) !== $profile ) {
			throw new \RuntimeException( 'Access profile could not be saved.' );
		}
		$this->audit(
			new AuditEvent(
				'agency_access',
				'changed',
				wp_generate_uuid4(),
				array(),
				array(),
				array(
					'user_id' => $user_id,
					'profile' => $profile,
				)
			)
		);
	}

	/**
	 * Requires explicit agency authority or the approved administrator override.
	 *
	 * @throws \RuntimeException When the actor is not authorized.
	 */
	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_AGENCY ) ) {
			throw new \RuntimeException( 'Agency management is not authorized.' );
		}
	}

	/**
	 * Observer failures cannot repeat or undo a committed policy/access change.
	 *
	 * @param AuditEvent $event Fixed metadata only.
	 */
	private function audit( AuditEvent $event ): void {
		try {
			do_action( HookNames::AUDIT_EVENT, $event );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed safe text, no user or policy values.
			error_log( 'Scalyn Mail Relay: agency audit persistence failed.' );
		}
	}
}
