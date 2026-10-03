<?php
/**
 * Protected, disabled-only webhook source configuration foundation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/** Draft management only; receiver and collection remain disabled. */
final class PostmarkWebhookSettings {

	/** Private non-autoloaded option; not part of sending settings. */
	public const OPTION = 'scalyn_mail_relay_postmark_webhook';

	/**
	 * Uses the existing server-managed encryption key with a separate context.
	 *
	 * @param CredentialCipher $cipher Credential protector.
	 */
	public function __construct( private CredentialCipher $cipher ) {}

	/**
	 * Returns public draft settings only; configured does not mean verified/enabled.
	 *
	 * @throws \RuntimeException When management permission is missing.
	 */
	public function public_settings(): array {
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			throw new \RuntimeException( 'Webhook configuration is not authorized.' );
		}
		$stored = get_option( self::OPTION, array() );
		return array(
			'configured'  => is_array( $stored ) && isset( $stored['credentials'] ),
			'enabled'     => false,
			'verified'    => false,
			'server_id'   => is_array( $stored ) ? (int) ( $stored['server_id'] ?? 0 ) : 0,
			'stream'      => is_array( $stored ) && is_string( $stored['stream'] ?? null ) ? $stored['stream'] : '',
			'allowed_ips' => is_array( $stored ) && is_array( $stored['allowed_ips'] ?? null ) ? array_values( array_filter( $stored['allowed_ips'], 'is_string' ) ) : array(),
		);
	}

	/**
	 * Saves a disabled draft, never changes transport, verifies a server or opts in.
	 * Only replace/keep are supported; identity changes require fresh credentials.
	 *
	 * @param array  $input Strict draft fields; secrets are not sanitized or echoed.
	 * @param string $nonce Management nonce.
	 * @return bool Whether the draft is durably saved.
	 * @throws \RuntimeException For denied, invalid or unavailable configuration.
	 */
	public function save( #[\SensitiveParameter] array $input, string $nonce ): bool {
		$this->authorize( $nonce );
		$server = $input['server_id'] ?? null;
		$stream = $input['stream'] ?? null;
		$ips    = $input['allowed_ips'] ?? null;
		$action = $input['credential_action'] ?? null;
		if ( ! is_int( $server ) || 1 > $server || ! is_string( $stream ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $stream ) || ! is_array( $ips ) || array() === $ips || count( $ips ) > 256 || ! in_array( $action, array( 'keep', 'replace' ), true ) || ! empty( $input['enabled'] ) ) {
			throw new \RuntimeException( 'Invalid webhook configuration.' );
		}
		foreach ( $ips as $ip ) {
			if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				throw new \RuntimeException( 'Invalid webhook configuration.' );
			}
		}
		$old  = get_option( self::OPTION, array() );
		$same = is_array( $old ) && ( $old['server_id'] ?? null ) === $server && ( $old['stream'] ?? null ) === $stream;
		if ( ! is_array( $old ) || ! empty( $old['enabled'] ) || ( array() !== $old && ( ! $same || ! is_string( $old['id'] ?? null ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $old['id'] ) ) ) ) {
			throw new \RuntimeException( 'Remove the disabled draft before changing its source.' );
		}
		if ( 'keep' === $action ) {
			if ( ! $same || ! empty( $input['username'] ) || ! empty( $input['password'] ) ) {
				throw new \RuntimeException( 'Replace webhook credentials for a new source.' );
			}
			// Verify that a saved envelope is readable before keeping it.
			$credentials = $this->cipher->decrypt( $old['credentials'] ?? '', 'postmark-webhook' );
			$envelope    = $old['credentials'];
		} else {
			$user     = $input['username'] ?? null;
			$password = $input['password'] ?? null;
			if ( ! is_string( $user ) || ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $user ) || ! is_string( $password ) || ! preg_match( '/^[A-Za-z0-9_-]{32,128}$/D', $password ) ) {
				throw new \RuntimeException( 'Invalid webhook credentials.' );
			}
			$credentials = wp_json_encode(
				array(
					'username' => $user,
					'password' => $password,
				)
			);
			$envelope    = $this->cipher->encrypt( $credentials, 'postmark-webhook' );
		}
		unset( $credentials );
		$draft = array(
			'version'     => 1,
			'id'          => $same ? $old['id'] : wp_generate_uuid4(),
			'server_id'   => $server,
			'stream'      => $stream,
			'allowed_ips' => array_values( array_unique( $ips ) ),
			'credentials' => $envelope,
			'enabled'     => false,
		);
		update_option( self::OPTION, $draft, false );
		$saved = get_option( self::OPTION ) === $draft;
		if ( $saved && $draft !== $old ) {
			$this->audit( 'saved_disabled', $draft['id'] );
		}
		return $saved;
	}

	/**
	 * Deletes the disabled draft and its budget only on explicit confirmation.
	 *
	 * @param bool   $confirmed Explicit administrator confirmation.
	 * @param string $nonce Management nonce.
	 * @return bool Whether draft and counter have been removed.
	 * @throws \RuntimeException For denied or active-source removal.
	 */
	public function remove( bool $confirmed, string $nonce ): bool {
		$this->authorize( $nonce );
		$old = get_option( self::OPTION, array() );
		if ( ! $confirmed || ! is_array( $old ) || ! empty( $old['enabled'] ) ) {
			throw new \RuntimeException( 'Webhook removal is unavailable.' );
		}
		$id = $old['id'] ?? '';
		if ( is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/D', $id ) ) {
			$budget = 'scalyn_webhook_budget_' . $id;
			delete_option( $budget );
			if ( false !== get_option( $budget, false ) ) {
				return false;
			}
		}
		delete_option( self::OPTION );
		$removed = false === get_option( self::OPTION, false );
		if ( $removed && is_string( $id ) && preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id ) ) {
			$this->audit( 'removed', $id );
		}
		return $removed;
	}

	/**
	 * Records only a fixed outcome and source UUID; observers cannot reverse a save.
	 *
	 * @param string $outcome Allowlisted action outcome.
	 * @param string $id Source UUID.
	 */
	private function audit( string $outcome, string $id ): void {
		try {
			do_action( HookNames::AUDIT_EVENT, new \Scalyn\MailRelay\Audit\AuditEvent( 'webhook_configuration', $outcome, $id ) );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed message only; no submitted fields or exception.
			error_log( 'Scalyn Mail Relay: webhook configuration audit failed.' );
		}
	}

	/**
	 * Requires the existing management permission plus a dedicated nonce.
	 *
	 * @param string $nonce Management nonce.
	 * @throws \RuntimeException When access is denied.
	 */
	private function authorize( string $nonce ): void {
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) || ! wp_verify_nonce( $nonce, 'scalyn_postmark_webhook' ) ) {
			throw new \RuntimeException( 'Webhook configuration is not authorized.' );
		}
	}
}
