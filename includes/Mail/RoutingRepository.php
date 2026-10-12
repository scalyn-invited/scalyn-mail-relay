<?php
/** Repository-owned, bounded routing policy. No message/credential persistence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Agency\ManagedAccess;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Core\HookNames;

defined( 'ABSPATH' ) || exit;

/** Ordered identities are resolved to immutable revisions once per logical message. */
final class RoutingRepository {

	public const OPTION = 'scalyn_mail_relay_routing';

	/** Returns a credential-free versioned policy. Malformed policies fail closed. */
	public function policy(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'version' => 1,
				'token'   => '',
				'enabled' => false,
				'rules'   => array(),
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Validates all routes before changing any policy; rejects stale form submissions.
	 *
	 * @param bool               $enabled Explicit opt-in.
	 * @param array              $rules source/type => ordered stable configuration UUIDs.
	 * @param string             $token Rendered policy token.
	 * @param SettingsRepository $settings Configuration read service.
	 * @throws \RuntimeException For denied, stale, invalid or failed writes.
	 */
	public function save( bool $enabled, array $rules, string $token, SettingsRepository $settings ): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			throw new \RuntimeException( 'Routing change denied.' );
		}
		ManagedAccess::assert_unlocked( 'critical' );
		if ( count( $rules ) > 50 ) {
			throw new \RuntimeException( 'Too many routing rules.' );
		}
		$validated = array();
		foreach ( $rules as $source => $ids ) {
			if ( ! isset( MailOrigin::types()[ $source ] ) || ! is_array( $ids ) || count( $ids ) > 3 || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) {
				throw new \RuntimeException( 'Invalid routing rule.' );
			}
			foreach ( $ids as $id ) {
				if ( ! is_string( $id ) ) {
					throw new \RuntimeException( 'Invalid routing identity.' );
				}
				$settings->configuration_target( $id );
			}
			if ( array() !== $ids ) {
				$validated[ $source ] = array_values( $ids );
			}
		}
		if ( ! add_option( self::OPTION . '_lock', true, '', false ) ) {
			throw new \RuntimeException( 'Routing is being updated.' );
		}
		try {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( $this->policy()['token'] !== $token || ! update_option(
				self::OPTION,
				array(
					'version' => 1,
					'token'   => wp_generate_uuid4(),
					'enabled' => $enabled,
					'rules'   => $validated,
				),
				false
			) ) {
				throw new \RuntimeException( 'Routing changed or could not be saved. Reload before retrying.' );
			}
		} finally {
			delete_option( self::OPTION . '_lock' );
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'routing_policy', 'changed' ) );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Pins an ordered content-free plan; disabled routing preserves legacy default.
	 * Invalid configured routes stop, never silently fall back or guess a sender.
	 *
	 * @param SettingsRepository $settings Sending configuration service.
	 * @param string             $origin Explicit source/type.
	 * @return array List of provider, configuration stable ID, revision, sender.
	 * @throws \RuntimeException For unavailable/archived configured identities.
	 */
	public function plan( SettingsRepository $settings, string $origin ): array {
		$policy = $this->policy();
		if ( true !== $policy['enabled'] ) {
			return array();
		}
		if ( ! isset( MailOrigin::types()[ $origin ] ) ) {
			$origin = 'unknown/default';
		}
		$ids = $policy['rules'][ $origin ] ?? $policy['rules']['unknown/default'] ?? array();
		if ( ! is_array( $ids ) || count( $ids ) > 3 ) {
			throw new \RuntimeException( 'Routing policy is unavailable.' );
		}
		$plan = array();
		foreach ( $ids as $id ) {
			$plan[] = $settings->configuration_target( $id );
		}
		return $plan;
	}
}
