<?php
/**
 * Authenticated credential encryption using a separately managed server key.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/** Versioned AES-256-GCM envelopes; never falls back to plaintext. */
final class CredentialCipher {

	/**
	 * Optional explicit key is for isolated testing; production uses server configuration.
	 *
	 * @param string|null $encoded_key Base64-encoded 32-byte key.
	 */
	public function __construct( #[\SensitiveParameter] private ?string $encoded_key = null ) {}

	/** Reports whether server encryption is available without exposing key material. */
	public function is_available(): bool {
		try {
			$this->key();
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Resolves the server key without persisting it.
	 *
	 * @return string Raw key.
	 * @throws \RuntimeException When secure storage is unavailable.
	 */
	private function key(): string {
		$value = $this->encoded_key ?? ( defined( 'SCALYN_MAIL_RELAY_ENCRYPTION_KEY' ) ? constant( 'SCALYN_MAIL_RELAY_ENCRYPTION_KEY' ) : '' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Decode key material, never executable code.
		$key = is_string( $value ) ? base64_decode( $value, true ) : false;
		if ( ! is_string( $key ) || 32 !== strlen( $key ) || ! extension_loaded( 'openssl' ) ) {
			throw new \RuntimeException( 'Secure credential storage is unavailable.' );
		}
		return $key;
	}

	/**
	 * Encrypts a credential with a unique nonce and provider-bound authenticated data.
	 *
	 * @param string $secret Credential.
	 * @param string $provider Provider-bound encryption context; defaults preserve existing envelopes.
	 * @return string Versioned envelope.
	 * @throws \RuntimeException When encryption fails.
	 */
	public function encrypt( #[\SensitiveParameter] string $secret, string $provider = 'sendgrid' ): string {
		$key   = $this->key();
		$nonce = random_bytes( 12 );
		$tag   = '';
		$data  = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $this->context( $provider ), 16 );
		if ( false === $data ) {
			throw new \RuntimeException( 'Credential could not be protected.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Binary authenticated envelope encoding, not code obfuscation.
		return 'v1:' . base64_encode( $nonce . $tag . $data );
	}

	/**
	 * Authenticates before releasing plaintext; corrupted/rotated keys fail closed.
	 *
	 * @param string $envelope Stored encrypted value.
	 * @param string $provider Expected provider-bound encryption context.
	 * @return string Credential for transport use only.
	 * @throws \RuntimeException When authentication or format validation fails.
	 */
	public function decrypt( #[\SensitiveParameter] string $envelope, string $provider = 'sendgrid' ): string {
		$key = $this->key();
		if ( ! str_starts_with( $envelope, 'v1:' ) || strlen( $envelope ) > 1024 ) {
			throw new \RuntimeException( 'Stored credential is unavailable. Replace or remove it.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Decode bounded ciphertext, never executable code.
		$data = base64_decode( substr( $envelope, 3 ), true );
		if ( false === $data || strlen( $data ) < 29 ) {
			throw new \RuntimeException( 'Stored credential is unavailable. Replace or remove it.' );
		}
		$secret = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ), $this->context( $provider ) );
		if ( false === $secret ) {
			throw new \RuntimeException( 'Stored credential is unavailable. Replace or remove it.' );
		}
		return $secret;
	}

	/**
	 * Restricts authenticated context to supported encrypted providers.
	 *
	 * @param string $provider Provider identifier.
	 * @return string Authenticated context.
	 * @throws \InvalidArgumentException When the context is unsupported.
	 */
	private function context( string $provider ): string {
		if ( 'delivery-matching' === $provider ) {
			return 'scalyn:delivery:recipient-matching:v1';
		}
		if ( 'postmark-webhook' === $provider ) {
			return 'scalyn:postmark:webhook-credentials:v1';
		}
		if ( ! in_array( $provider, array( 'sendgrid', 'postmark', 'smtp2go' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported credential context.' );
		}
		return 'scalyn:' . $provider . ':api-key:v1';
	}
}
