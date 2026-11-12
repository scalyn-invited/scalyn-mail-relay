<?php
/**
 * Site-local access profiles and managed configuration policy.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Agency;

use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Profiles never replace WordPress roles or grant non-plugin capabilities. */
final class ManagedAccess {

	public const PROFILE_META = 'scalyn_mail_relay_access_profile';

	/** Site-bound user metadata; an assignment cannot grant access on another site. */
	public static function profile_key(): string {
		return self::PROFILE_META . '_' . get_current_blog_id();
	}
	/** Registers request-time enforcement, including direct REST/admin actions. */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'filter_capabilities' ), 20, 4 );
	}

	/**
	 * Supported profiles; none means use the existing WordPress capabilities.
	 *
	 * @return array<string, string> Labels.
	 */
	public static function profiles(): array {
		return array(
			'none'    => __( 'Use existing WordPress access', 'scalyn-mail-relay' ),
			'agency'  => __( 'Agency Manager', 'scalyn-mail-relay' ),
			'support' => __( 'Support Engineer', 'scalyn-mail-relay' ),
			'auditor' => __( 'Read-Only Auditor', 'scalyn-mail-relay' ),
			'client'  => __( 'Client Viewer', 'scalyn-mail-relay' ),
		);
	}

	/**
	 * Projects only owned capabilities. Never calls current_user_can recursively.
	 *
	 * @param array  $allcaps Existing effective capabilities.
	 * @param array  $caps Requested primitive capabilities.
	 * @param array  $args Original capability request.
	 * @param object $user WordPress user being checked, not necessarily the actor.
	 * @return array Effective capabilities.
	 */
	public function filter_capabilities( array $allcaps, array $caps, array $args, object $user ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( Capabilities::all() as $capability ) {
				$allcaps[ $capability ] = true;
			}
			return $allcaps;
		}
		$profile = get_user_meta( (int) $user->ID, self::profile_key(), true );
		if ( '' === $profile || 'none' === $profile ) {
			return $allcaps;
		}
		$allowed = match ( $profile ) {
			'agency' => Capabilities::all(),
			'support' => array( Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_LOGS, Capabilities::RUN_DIAGNOSTICS, Capabilities::VIEW_AUDIT ),
			'auditor' => array( Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_LOGS, Capabilities::VIEW_AUDIT ),
			'client' => array( Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_CLIENT_REPORTS ),
			default => array(),
		};
		foreach ( Capabilities::all() as $capability ) {
			$allcaps[ $capability ] = in_array( $capability, $allowed, true );
		}
		return $allcaps;
	}

	/** Whether to replace the operational dashboard with the minimal client view. */
	public static function is_client(): bool {
		return ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_AGENCY )
			&& 'client' === get_user_meta( get_current_user_id(), self::profile_key(), true );
	}

	/**
	 * Reads current policy at the mutation boundary, not from a cached form.
	 * Administrators and agency managers retain explicit override authority.
	 *
	 * @param string $group smtp, providers or critical.
	 * @return bool Whether the setting group is locked for this actor.
	 */
	public static function is_locked( string $group ): bool {
		if ( ! in_array( $group, AgencyRepository::LOCKS, true ) ) {
			return true;
		}
		$policy = ( new AgencyRepository() )->policy();
		if ( ! $policy['managed'] || ! $policy[ $group ] ) {
			return false;
		}
		return ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_AGENCY );
	}

	/**
	 * Protects service writes even when a caller bypasses the normal admin form.
	 *
	 * @param string $group Owned setting group.
	 * @throws \RuntimeException When locked.
	 */
	public static function assert_unlocked( string $group ): void {
		if ( self::is_locked( $group ) ) {
			throw new \RuntimeException( 'These settings are managed by your administrator or agency manager. No changes were saved.' );
		}
	}

	/**
	 * Safe form denial before reading input or performing a privileged action.
	 *
	 * @param string $group Owned setting group.
	 */
	public static function protect_form( string $group ): void {
		if ( self::is_locked( $group ) ) {
			wp_die( esc_html__( 'These settings are managed by your administrator or agency manager. No changes were saved.', 'scalyn-mail-relay' ) );
		}
	}
}
