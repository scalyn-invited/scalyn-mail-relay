<?php
/** Explicit temporary-content-storage consent and operational recovery switch.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Recovery;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Agency\ManagedAccess;

defined( 'ABSPATH' ) || exit;

/** Non-autoloaded option; recovery is always off on first install. */
final class RecoveryPolicyRepository {

	public const OPTION = 'scalyn_mail_relay_recovery';

	/** Credential-free policy for UI and workers; malformed state is disabled. */
	public function policy(): array {
		$value = get_option( self::OPTION, array() );
		return array(
			'enabled' => is_array( $value ) && true === ( $value['enabled'] ?? false ),
			'token'   => is_array( $value ) && is_string( $value['token'] ?? null ) ? $value['token'] : '',
		);
	}

	/**
	 * Requires consent on every enable, server encryption, schema and named default.
	 *
	 * @param bool               $enabled Explicit switch.
	 * @param bool               $consent Temporary storage consent.
	 * @param string             $token Rendered policy revision.
	 * @param SettingsRepository $settings Current configuration service.
	 * @param CredentialCipher   $cipher Server cipher.
	 * @throws \RuntimeException When access, readiness, consent or write fails.
	 */
	public function save( bool $enabled, bool $consent, string $token, SettingsRepository $settings, CredentialCipher $cipher ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			throw new \RuntimeException( 'Recovery policy change denied.' );
		}
		ManagedAccess::assert_unlocked( 'critical' );
		if ( $enabled ) {
			if ( ! $consent || ! $cipher->is_available() || version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.13.0', '<' ) ) {
				throw new \RuntimeException( 'Recovery requires explicit storage consent, server encryption and the current database schema.' );
			}
			$settings->configuration_target( $settings->get_active_configuration_id() );
		}
		if ( ! add_option( self::OPTION . '_lock', true, '', false ) ) {
			throw new \RuntimeException( 'Recovery policy is being updated.' );
		}
		try {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( $this->policy()['token'] !== $token || ! update_option(
				self::OPTION,
				array(
					'version' => 1,
					'enabled' => $enabled,
					'token'   => wp_generate_uuid4(),
				),
				false
			) ) {
				throw new \RuntimeException( 'Recovery policy changed or could not be saved.' );
			}
		} finally {
			delete_option( self::OPTION . '_lock' );
		}
		try {
			do_action( \Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT, new \Scalyn\MailRelay\Audit\AuditEvent( 'recovery_policy', $enabled ? 'enabled' : 'disabled' ) );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}
}
