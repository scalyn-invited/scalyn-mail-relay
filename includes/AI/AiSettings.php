<?php
/** Encrypted, independently consented AI profiles.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Core\CredentialCipher;
defined( 'ABSPATH' ) || exit;
/** Versioned option repository; reads never mutate legacy settings. */
final class AiSettings {
	public const OPTION = 'scalyn_mail_relay_ai';
	public const MODELS = array( 'gpt-4.1-mini', 'gpt-4.1', 'gpt-5-mini', 'gpt-5' );
	/** Creates the encrypted repository.
	 *
	 * @param CredentialCipher $cipher Server-key protection.
	 */
	public function __construct( private CredentialCipher $cipher ) {}
	/** Normalizes legacy OpenAI settings without writing or re-encrypting. */
	private function stored(): array {
		$value = get_option( self::OPTION, array() );
		$data  = is_array( $value ) ? $value : array();
		if ( 2 !== ( $data['version'] ?? null ) ) {
			$data = array(
				'version'  => 2,
				'revision' => $data['revision'] ?? '',
				'enabled'  => ! empty( $data['enabled'] ),
				'mcp'      => ! empty( $data['mcp'] ),
				'provider' => 'openai',
				'profiles' => array( 'openai' => array_intersect_key( $data, array_flip( array( 'key', 'model', 'models', 'consent' ) ) ) ),
			);
		}
		return $data;
	}
	/** Returns only safe fields for all profiles, never encrypted credentials. */
	public function summary(): array {
		AiAccess::require_admin();
		$data     = $this->stored();
		$profiles = array();
		foreach ( AiCatalog::PROVIDERS as $provider => $label ) {
			$profile               = $data['profiles'][ $provider ] ?? array();
			$model                 = is_string( $profile['model'] ?? null ) && isset( AiCatalog::MODELS[ $provider ][ $profile['model'] ] ) ? $profile['model'] : '';
			$models                = array_values( array_intersect( array_keys( AiCatalog::MODELS[ $provider ] ), is_array( $profile['models'] ?? null ) ? $profile['models'] : array() ) );
			$profiles[ $provider ] = array(
				'model'    => $model,
				'models'   => $models,
				'has_key'  => ! empty( $profile['key'] ),
				'consent'  => ! empty( $profile['consent'] ),
				'verified' => '' !== $model && in_array( $model, $models, true ),
			);
		}
		$provider = isset( AiCatalog::PROVIDERS[ $data['provider'] ?? '' ] ) ? $data['provider'] : 'openai';
		$active   = $profiles[ $provider ];
		return array_merge(
			$active,
			array(
				'revision'   => (string) ( $data['revision'] ?? '' ),
				'provider'   => $provider,
				'profiles'   => $profiles,
				'enabled'    => ! empty( $data['enabled'] ) && $active['verified'] && $active['has_key'] && $active['consent'] && $this->cipher->is_available(),
				'mcp'        => ! empty( $data['mcp'] ),
				'encryption' => $this->cipher->is_available(),
			)
		);
	}
	/** Decrypts only the named adapter's credential.
	 *
	 * @param string $provider Exact provider ID.
	 * @throws \RuntimeException For missing credentials.
	 * @throws \InvalidArgumentException For unknown providers.
	 */
	public function credential( string $provider = 'openai' ): string {
		AiAccess::require_admin();
		if ( ! isset( AiCatalog::PROVIDERS[ $provider ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported AI provider.' );}
		$data = $this->stored();
		$key  = $data['profiles'][ $provider ]['key'] ?? '';
		if ( ! is_string( $key ) || '' === $key ) {
			throw new \RuntimeException( 'Save this provider API key first.' );}
		return $this->cipher->decrypt( $key, 'ai-' . $provider );
	}
	/** Saves one profile and independent MCP opt-in.
	 *
	 * @param array $input Protected form values.
	 * @throws \InvalidArgumentException For invalid setup.
	 */
	public function save( array $input ): void {
		AiAccess::require_admin();
		$token = $this->lock();try {
			$data = $this->stored();
			$this->revision( $data, $input['revision'] ?? null );
			$model = $input['model'] ?? '';
			$key   = $input['key'] ?? '';
			if ( ! is_string( $model ) || ! is_string( $key ) || strlen( $key ) > 512 ) {
				throw new \InvalidArgumentException( 'Invalid AI settings.' );}
			$provider = '' !== $model ? AiCatalog::provider( $model ) : ( $data['provider'] ?? 'openai' );
			$profile  = $data['profiles'][ $provider ] ?? array();
			$replaced = '' !== $key || ! empty( $input['remove_key'] );
			if ( '' !== $key && ! empty( $input['remove_key'] ) ) {
				throw new \InvalidArgumentException( 'Enter a new key or remove the saved key, not both.' );
			}
			if ( 'claude' === $provider && '' !== $key && ( str_contains( $key, '...' ) || str_contains( $key, '…' ) ) ) {
				throw new \InvalidArgumentException( 'The Claude key appears shortened. Use the full secret copied when the key was created.' );
			}
			if ( '' !== $key ) {
				$valid = match ( $provider ) {
					'openai'=>preg_match( '/^sk-[A-Za-z0-9_-]{16,509}$/D', $key ), 'gemini'=>preg_match( '/^AIza[A-Za-z0-9_-]{20,508}$/D', $key ), 'claude'=>preg_match( '/^sk-ant-[A-Za-z0-9_-]{16,505}$/D', $key )};
				if ( ! $valid ) {
					throw new \InvalidArgumentException( 'Enter a valid key for the selected provider.' );}
				$profile['key'] = $this->cipher->encrypt( $key, 'ai-' . $provider );
			}
			if ( ! empty( $input['remove_key'] ) ) {
				unset( $profile['key'] );
			}if ( $replaced ) {
				$profile['models'] = array();}
			$profile['model']   = $model;
			$profile['consent'] = ! empty( $input['consent'] );
			$enabled            = ! empty( $input['enabled'] ) && ! $replaced;
			if ( $enabled && ( ! $profile['consent'] || empty( $profile['key'] ) || ! in_array( $model, $profile['models'] ?? array(), true ) || ! $this->cipher->is_available() ) ) {
				throw new \InvalidArgumentException( 'Verify this provider key, selected model and consent before enabling chat.' );}
			$data['profiles'][ $provider ] = $profile;
			$data['provider']              = $provider;
			$data['enabled']               = $enabled;
			$data['mcp']                   = ! empty( $input['mcp'] );
			$this->persist( $data );
			AiAudit::record( 'ai_settings', 'saved' );
		} finally {
			$this->unlock( $token );}
	}
	/** Records account-visible models for one unchanged profile.
	 *
	 * @param array       $models Exact account-visible IDs.
	 * @param string|null $revision Revision before network access.
	 * @param string      $provider Key's provider.
	 * @throws \InvalidArgumentException For unknown providers.
	 */
	public function record_models( array $models, ?string $revision = null, string $provider = 'openai' ): void {
		AiAccess::require_admin();
		if ( ! isset( AiCatalog::MODELS[ $provider ] ) ) {
			throw new \InvalidArgumentException( 'Unsupported AI provider.' );}
		$token = $this->lock();try {
			$data = $this->stored();
			$this->revision( $data, $revision );
			$data['profiles'][ $provider ]['models'] = array_values( array_intersect( array_keys( AiCatalog::MODELS[ $provider ] ), $models ) );
			if ( ( $data['provider'] ?? 'openai' ) === $provider && ! in_array( $data['profiles'][ $provider ]['model'] ?? '', $data['profiles'][ $provider ]['models'], true ) ) {
				$data['enabled'] = false;}
			$this->persist( $data );
			AiAudit::record( 'ai_settings', 'models_refreshed' );
		} finally {
			$this->unlock( $token );}
	}
	/** Checks the option revision.
	 *
	 * @param array $data Stored option.
	 * @param mixed $revision Expected token.
	 * @throws \RuntimeException For stale or malformed tokens.
	 */
	private function revision( array $data, mixed $revision ): void {
		if ( null !== $revision && ( ! is_string( $revision ) || ! hash_equals( (string) ( $data['revision'] ?? '' ), $revision ) ) ) {
			throw new \RuntimeException( 'AI settings changed. Reload before saving.' );}}
	/** Persists and verifies a versioned option.
	 *
	 * @param array $data Encrypted profiles.
	 * @throws \RuntimeException For failed writes.
	 */
	private function persist( array $data ): void {
		$data['revision'] = wp_generate_uuid4();
		update_option( self::OPTION, $data, false );
		if ( get_option( self::OPTION ) !== $data ) {
			throw new \RuntimeException( 'AI settings could not be saved.' );}}
	/** Serializes short writes, never network requests.
	 *
	 * @throws \RuntimeException For busy settings.
	 */
	private function lock(): string {
		$token = wp_generate_uuid4();
		if ( ! add_option( self::OPTION . '_write_lock', $token, '', false ) ) {
			throw new \RuntimeException( 'AI settings are busy.' );
		}return $token;}
	/** Releases only its own claim.
	 *
	 * @param string $token Claim.
	 */
	private function unlock( string $token ): void {
		if ( get_option( self::OPTION . '_write_lock' ) === $token ) {
			delete_option( self::OPTION . '_write_lock' );}}
}
