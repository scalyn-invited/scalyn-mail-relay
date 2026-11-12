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
	 * Optional explicit key/store are for isolated testing; configured external keys
	 * preserve v1 while automatic site-local storage uses source-bound v2 envelopes.
	 *
	 * @param string|null                  $encoded_key Base64-encoded 32-byte external key.
	 * @param LocalCredentialKeyStore|null $local_keys Optional isolated local store.
	 */
	public function __construct(
		#[\SensitiveParameter] private ?string $encoded_key = null,
		private readonly ?LocalCredentialKeyStore $local_keys = null
	) {}

	/** Reports existing usable encryption without creating files or database state. */
	public function is_available(): bool {
		try {
			$this->key_for( $this->write_version() );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Reports read-only first-use setup readiness separately from key availability. */
	public function can_initialize(): bool {
		if ( ! extension_loaded( 'openssl' ) ) {
			return false;
		}
		if ( 'v1' === $this->write_version() ) {
			return $this->is_available();
		}
		return $this->local_store()->can_initialize();
	}

	/**
	 * Explicit write-boundary initialization; never called by encrypt/decrypt/status.
	 *
	 * @throws \RuntimeException If secure credential storage is unavailable.
	 */
	public function initialize_for_write(): void {
		if ( ! extension_loaded( 'openssl' ) ) {
			throw new \RuntimeException( 'Secure credential storage is unavailable.' );
		}
		if ( 'v1' === $this->write_version() ) {
			$this->key_for( 'v1' );
			return;
		}
		$this->local_store()->initialize_for_write();
	}

	/** Resolves the current site's store without caching a switched-blog identity. */
	private function local_store(): LocalCredentialKeyStore {
		return $this->local_keys ?? new LocalCredentialKeyStore();
	}

	/** Selects the source for new writes, never for old envelope reads. */
	private function write_version(): string {
		return null !== $this->encoded_key || defined( 'SCALYN_MAIL_RELAY_ENCRYPTION_KEY' ) ? 'v1' : 'v2';
	}

	/**
	 * Loads only the key source identified by an envelope.
	 *
	 * @param string $version Source-bound envelope version.
	 * @return string Raw key, never persisted in the database.
	 * @throws \RuntimeException When secure storage is unavailable.
	 */
	private function key_for( string $version ): string {
		if ( ! extension_loaded( 'openssl' ) ) {
			throw new \RuntimeException( 'Secure credential storage is unavailable.' );
		}
		if ( 'v2' === $version ) {
			return $this->local_store()->read();
		}
		$value = $this->encoded_key ?? ( defined( 'SCALYN_MAIL_RELAY_ENCRYPTION_KEY' ) ? constant( 'SCALYN_MAIL_RELAY_ENCRYPTION_KEY' ) : '' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Decode key material, never executable code.
		$key = is_string( $value ) ? base64_decode( $value, true ) : false;
		if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
			throw new \RuntimeException( 'Secure credential storage is unavailable.' );
		}
		return $key;
	}

	/**
	 * Authenticates local format/source and site while preserving every v1 context.
	 *
	 * @param string $provider Provider/payload context.
	 * @param string $version Envelope format/source.
	 * @return string Authenticated additional data.
	 * @throws \RuntimeException When the site identity is invalid.
	 */
	private function authenticated_context( string $provider, string $version ): string {
		$context = $this->context( $provider );
		if ( 'v1' === $version ) {
			return $context;
		}
		$blog = get_current_blog_id();
		if ( ! is_int( $blog ) || $blog < 1 ) {
			throw new \RuntimeException( 'Secure credential storage is unavailable.' );
		}
		return 'scalyn:local:v2:blog:' . $blog . ':' . $context;
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
		$version = $this->write_version();
		$key     = $this->key_for( $version );
		$nonce   = random_bytes( 12 );
		$tag     = '';
		$data    = openssl_encrypt( $secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $this->authenticated_context( $provider, $version ), 16 );
		if ( false === $data ) {
			throw new \RuntimeException( 'Credential could not be protected.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Binary authenticated envelope encoding, not code obfuscation.
		return $version . ':' . base64_encode( $nonce . $tag . $data );
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
		$version = substr( $envelope, 0, 2 );
		$maximum = 'recovery-payload' === $provider ? 12000000 : ( 'ai-session' === $provider ? 1500000 : ( 'smtp' === $provider ? 8192 : 1024 ) );
		if ( ! in_array( $version, array( 'v1', 'v2' ), true ) || ':' !== substr( $envelope, 2, 1 ) || strlen( $envelope ) > $maximum ) {
			throw new \RuntimeException( 'Stored credential is unavailable. Replace or remove it.' );
		}
		$key = $this->key_for( $version );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Decode bounded ciphertext, never executable code.
		$data = base64_decode( substr( $envelope, 3 ), true );
		if ( false === $data || strlen( $data ) < 29 ) {
			throw new \RuntimeException( 'Stored credential is unavailable. Replace or remove it.' );
		}
		$secret = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ), $this->authenticated_context( $provider, $version ) );
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
		if ( in_array( $provider, array( 'ai-openai', 'ai-gemini', 'ai-claude', 'ai-session' ), true ) ) {
			return 'scalyn:ai:' . $provider . ':v1';
		}
		if ( 'recovery-payload' === $provider ) {
			return 'scalyn:recovery:payload:v1';
		}
		if ( 'smtp' === $provider ) {
			return 'scalyn:smtp:login:v1';
		}
		if ( 'delivery-matching' === $provider ) {
			return 'scalyn:delivery:recipient-matching:v1';
		}
		if ( in_array( $provider, array( 'postmark-webhook', 'smtp2go-webhook', 'brevo-webhook' ), true ) ) {
			return 'scalyn:' . str_replace( '-webhook', '', $provider ) . ':webhook-credentials:v1';
		}
		if ( ! in_array( $provider, array( 'sendgrid', 'postmark', 'smtp2go', 'brevo' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported credential context.' );
		}
		return 'scalyn:' . $provider . ':api-key:v1';
	}
}
