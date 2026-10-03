<?php
/**
 * Protected Postmark webhook source lifecycle: draft, verify, opt in, disable, remove.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Database\DeliveryKeyRepository;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Collection is off by default and changes only through explicit administrator
 * actions. Saving provider settings, verifying a connection or enabling
 * recipient-address logging never enables delivery evidence collection.
 */
final class PostmarkWebhookSettings {

	/** Private non-autoloaded option; not part of sending settings. */
	public const OPTION = 'scalyn_mail_relay_postmark_webhook';

	/** The only stream the Postmark transport sends through. */
	public const TRANSPORT_STREAM = 'outbound';

	/** Minimum schema with key retirement and delivery storage. */
	private const STORAGE_VERSION = '0.6.0';

	/** Canonical lowercase UUID. */
	private const UUID = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D';

	/**
	 * Uses the existing server-managed encryption key with a separate context.
	 *
	 * @param CredentialCipher           $cipher Credential protector.
	 * @param DeliveryKeyRepository|null $keys Matching-key lifecycle.
	 * @param SettingsRepository|null    $mail Sending settings, read for revision and provider only.
	 */
	public function __construct(
		private CredentialCipher $cipher,
		private ?DeliveryKeyRepository $keys = null,
		private ?SettingsRepository $mail = null
	) {}

	/**
	 * Returns public source state only; never credentials, keys or tokens.
	 *
	 * @throws \RuntimeException When management permission is missing.
	 */
	public function public_settings(): array {
		if ( ! current_user_can( Capabilities::MANAGE_MAIL ) ) {
			throw new \RuntimeException( 'Webhook configuration is not authorized.' );
		}
		$stored     = $this->stored();
		$configured = isset( $stored['credentials'] );
		$verified   = $configured && $this->verified_current( $stored );
		$enabled    = $configured && true === ( $stored['enabled'] ?? false );
		return array(
			'configured'     => $configured,
			'enabled'        => $enabled,
			'verified'       => $verified,
			'verified_at'    => $verified && is_string( $stored['verified_at'] ?? null ) ? $stored['verified_at'] : '',
			'collecting'     => $enabled && null !== $this->dispatch_source(),
			'pause_reason'   => $enabled ? $this->pause_reason( $stored ) : '',
			'source_id'      => $configured && is_string( $stored['id'] ?? null ) ? $stored['id'] : '',
			'endpoint'       => $configured && is_string( $stored['id'] ?? null ) ? rest_url( 'scalyn-mail-relay/v1/webhooks/postmark/' . $stored['id'] ) : '',
			'server_id'      => (int) ( $stored['server_id'] ?? 0 ),
			'stream'         => is_string( $stored['stream'] ?? null ) ? $stored['stream'] : '',
			'allowed_ips'    => is_array( $stored['allowed_ips'] ?? null ) ? array_values( array_filter( $stored['allowed_ips'], 'is_string' ) ) : array(),
			'retention_days' => $this->mail()->get_log_retention_days(),
			'storage_ready'  => $this->storage_ready(),
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
		wp_cache_delete( self::OPTION, 'options' );
		$old  = get_option( self::OPTION, array() );
		$same = is_array( $old ) && ( $old['server_id'] ?? null ) === $server && ( $old['stream'] ?? null ) === $stream;
		if ( ! is_array( $old ) || ! empty( $old['enabled'] ) || ( array() !== $old && ( ! $same || ! is_string( $old['id'] ?? null ) || ! preg_match( self::UUID, $old['id'] ) ) ) ) {
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
		// Credential rotation for the same source keeps its verification and key version.
		foreach ( array( 'verified_revision', 'verified_at', 'key_version' ) as $field ) {
			if ( $same && isset( $old[ $field ] ) ) {
				$draft[ $field ] = $old[ $field ];
			}
		}
		update_option( self::OPTION, $draft, false );
		$saved = $this->stored() === $draft;
		if ( $saved && $draft !== $old ) {
			$this->audit( 'saved_disabled', $draft['id'] );
		}
		return $saved;
	}

	/**
	 * Confirms the source's server ID belongs to the configured Live sending token.
	 * Never sends a message. Verification is bound to the current configuration
	 * revision, so later provider, token, sender or DKIM changes require re-verification.
	 *
	 * @param string           $nonce Management nonce.
	 * @param PostmarkProvider $provider Postmark adapter used for a read-only server check.
	 * @return bool Whether the source is now verified.
	 * @throws \RuntimeException When denied, unconfigured or not persisted.
	 */
	public function verify( string $nonce, PostmarkProvider $provider ): bool {
		$this->authorize( $nonce );
		$old = $this->stored();
		if ( ! isset( $old['credentials'], $old['id'] ) || ! preg_match( self::UUID, (string) $old['id'] ) ) {
			throw new \RuntimeException( 'Save a webhook source before verifying it.' );
		}
		$server = null;
		if ( 'postmark' === $this->mail()->get_active_provider_id() && self::TRANSPORT_STREAM === ( $old['stream'] ?? null ) ) {
			try {
				$server = $provider->live_server_id( $this->mail()->get_provider_config( 'postmark' ) );
			} catch ( \Throwable $error ) {
				$server = null;
			}
		}
		$next = $old;
		unset( $next['verified_revision'], $next['verified_at'] );
		$expected = $old['server_id'] ?? null;
		$verified = null !== $server && $expected === $server;
		if ( $verified ) {
			$next['verified_revision'] = $this->mail()->ensure_diagnostic_revision();
			$next['verified_at']       = gmdate( 'Y-m-d H:i:s' );
		}
		if ( $next !== $old ) {
			update_option( self::OPTION, $next, false );
			if ( $this->stored() !== $next ) {
				throw new \RuntimeException( 'Webhook verification could not be saved.' );
			}
		}
		$this->audit( $verified ? 'verified' : 'verification_failed', $old['id'] );
		return $verified;
	}

	/**
	 * Explicit opt-in. Requires acknowledgement, verification, ready storage and a key.
	 *
	 * @param bool   $acknowledged The privacy notice acknowledgement was checked.
	 * @param string $nonce Management nonce.
	 * @return bool Whether collection is enabled.
	 * @throws \RuntimeException With a fixed, actionable prerequisite message.
	 */
	public function enable( bool $acknowledged, string $nonce ): bool {
		$this->authorize( $nonce );
		$old = $this->stored();
		if ( ! isset( $old['credentials'], $old['id'] ) || ! preg_match( self::UUID, (string) $old['id'] ) ) {
			throw new \RuntimeException( 'Save a webhook source before enabling collection.' );
		}
		if ( ! empty( $old['enabled'] ) ) {
			return true;
		}
		if ( ! $acknowledged ) {
			throw new \RuntimeException( 'Confirm the privacy notice before enabling collection.' );
		}
		if ( ! $this->verified_current( $old ) ) {
			throw new \RuntimeException( 'Verify the webhook source for the current Postmark configuration first.' );
		}
		if ( ! $this->storage_ready() ) {
			throw new \RuntimeException( 'Delivery evidence storage is not ready. Update the plugin database first.' );
		}
		$next = $old;
		if ( ! is_string( $old['key_version'] ?? null ) || ! preg_match( self::UUID, $old['key_version'] ) ) {
			try {
				$next['key_version'] = $this->keys()->provision();
			} catch ( \Throwable $error ) {
				throw new \RuntimeException( 'Recipient matching key could not be prepared. Encryption must be available.' );
			}
		}
		$next['enabled']    = true;
		$next['enabled_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION, $next, false );
		if ( $this->stored() !== $next ) {
			throw new \RuntimeException( 'Collection could not be enabled. Try again.' );
		}
		$this->audit( 'enabled', $old['id'] );
		return true;
	}

	/**
	 * Stops new associations and evidence writes immediately; retained history remains.
	 *
	 * @param string $nonce Management nonce.
	 * @return bool Whether collection is disabled.
	 * @throws \RuntimeException When denied or not persisted.
	 */
	public function disable( string $nonce ): bool {
		$this->authorize( $nonce );
		$old = $this->stored();
		if ( empty( $old['enabled'] ) ) {
			return true;
		}
		$next            = $old;
		$next['enabled'] = false;
		unset( $next['enabled_at'] );
		update_option( self::OPTION, $next, false );
		if ( $this->stored() !== $next ) {
			throw new \RuntimeException( 'Collection could not be disabled. Try again.' );
		}
		$this->audit( 'disabled', (string) $old['id'] );
		return true;
	}

	/**
	 * Deletes a disabled source, retires its key version and clears its budget.
	 * Retained evidence stays readable until normal retention cleanup.
	 *
	 * @param bool   $confirmed Explicit administrator confirmation.
	 * @param string $nonce Management nonce.
	 * @return bool Whether source and counter have been removed.
	 * @throws \RuntimeException For denied or active-source removal.
	 */
	public function remove( bool $confirmed, string $nonce ): bool {
		$this->authorize( $nonce );
		wp_cache_delete( self::OPTION, 'options' );
		$old = get_option( self::OPTION, array() );
		if ( ! $confirmed || ! is_array( $old ) || ! empty( $old['enabled'] ) ) {
			throw new \RuntimeException( 'Webhook removal is unavailable.' );
		}
		if ( is_string( $old['key_version'] ?? null ) && preg_match( self::UUID, $old['key_version'] ) ) {
			// Stop selecting the version first; retained attempts can still be matched.
			$this->keys()->retire( $old['key_version'] );
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
		if ( $removed && is_string( $id ) && preg_match( self::UUID, $id ) ) {
			$this->audit( 'removed', $id );
		}
		return $removed;
	}

	/**
	 * Internal send-path read: the enabled, currently verified source, or null.
	 * No capability check: called during wp_mail for any user. Returns no secrets.
	 *
	 * @return array|null id, key_version and configuration_id at dispatch.
	 */
	public function dispatch_source(): ?array {
		try {
			$stored = $this->stored();
			if ( true !== ( $stored['enabled'] ?? false ) || '' !== $this->pause_reason( $stored ) ) {
				return null;
			}
			if ( ! is_string( $stored['id'] ?? null ) || ! preg_match( self::UUID, $stored['id'] ) || ! is_string( $stored['key_version'] ?? null ) || ! preg_match( self::UUID, $stored['key_version'] ) ) {
				return null;
			}
			return array(
				'id'               => $stored['id'],
				'key_version'      => $stored['key_version'],
				'configuration_id' => $stored['verified_revision'],
			);
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Internal receiver read: trusted source configuration with decrypted credentials.
	 * Never pass the result to logs, responses, UI or exceptions.
	 *
	 * @param string $id Source UUID from the callback route.
	 * @return array|null Ingress source, or null when absent or mismatched.
	 * @throws \RuntimeException When saved credentials cannot be decrypted.
	 */
	public function ingress_source( string $id ): ?array {
		$stored = $this->stored();
		if ( ! isset( $stored['credentials'] ) || ! is_string( $stored['id'] ?? null ) || ! hash_equals( $stored['id'], strtolower( $id ) ) ) {
			return null;
		}
		$credentials = json_decode( $this->cipher->decrypt( $stored['credentials'], 'postmark-webhook' ), true );
		if ( ! is_array( $credentials ) || ! is_string( $credentials['username'] ?? null ) || ! is_string( $credentials['password'] ?? null ) ) {
			throw new \RuntimeException( 'Webhook credentials are unavailable.' );
		}
		return array(
			'id'          => $stored['id'],
			'server_id'   => (int) ( $stored['server_id'] ?? 0 ),
			'stream'      => (string) ( $stored['stream'] ?? '' ),
			'username'    => $credentials['username'],
			'password'    => $credentials['password'],
			'allowed_ips' => is_array( $stored['allowed_ips'] ?? null ) ? $stored['allowed_ips'] : array(),
			'enabled'     => true === ( $stored['enabled'] ?? false ),
		);
	}

	/**
	 * Fresh check used immediately before an evidence write.
	 *
	 * @param string $id Authenticated source UUID.
	 */
	public function is_enabled( string $id ): bool {
		$stored = $this->stored();
		return true === ( $stored['enabled'] ?? false ) && is_string( $stored['id'] ?? null ) && hash_equals( $stored['id'], $id );
	}

	/** Public, secret-free flag for read models available to log viewers. */
	public function collection_enabled(): bool {
		$stored = $this->stored();
		return isset( $stored['credentials'] ) && true === ( $stored['enabled'] ?? false );
	}

	/**
	 * Secret-free collection status for provider cards.
	 *
	 * @return string off, collecting or paused.
	 */
	public function collection_status(): string {
		if ( ! $this->collection_enabled() ) {
			return 'off';
		}
		return null !== $this->dispatch_source() ? 'collecting' : 'paused';
	}

	/** Whether any source draft exists; controls receiver route registration. */
	public function has_source(): bool {
		return isset( $this->stored()['credentials'] );
	}

	/**
	 * Why an enabled source is not collecting for new sends, or '' when collecting.
	 *
	 * @param array $stored Stored source.
	 * @return string reverify, provider, stream or storage.
	 */
	private function pause_reason( array $stored ): string {
		if ( self::TRANSPORT_STREAM !== ( $stored['stream'] ?? null ) ) {
			return 'stream';
		}
		if ( 'postmark' !== $this->mail()->get_active_provider_id() ) {
			return 'provider';
		}
		if ( ! $this->verified_current( $stored ) ) {
			return 'reverify';
		}
		return $this->storage_ready() ? '' : 'storage';
	}

	/**
	 * Verification is valid only for the configuration revision it was made against.
	 *
	 * @param array $stored Stored source.
	 */
	private function verified_current( array $stored ): bool {
		$revision = $this->mail()->get_diagnostic_revision();
		return is_string( $stored['verified_revision'] ?? null ) && '' !== $revision && hash_equals( $stored['verified_revision'], $revision );
	}

	/** Delivery tables exist at the schema version that supports key retirement. */
	private function storage_ready(): bool {
		return version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), self::STORAGE_VERSION, '>=' );
	}

	/** Fresh, uncached source read; malformed state is treated as no source. */
	private function stored(): array {
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/** Lazily constructs the key repository. */
	private function keys(): DeliveryKeyRepository {
		return $this->keys ??= new DeliveryKeyRepository( $this->cipher );
	}

	/** Lazily constructs sending settings. */
	private function mail(): SettingsRepository {
		return $this->mail ??= new SettingsRepository( $this->cipher );
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
