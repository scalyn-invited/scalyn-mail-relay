<?php
/** Encrypted opt-in AI configuration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Core\CredentialCipher;

defined( 'ABSPATH' ) || exit;

/** Credential-free summaries and explicit key replacement/removal. */
final class AiSettings {

	public const OPTION = 'scalyn_mail_relay_ai';
	public const MODELS = array( 'gpt-4.1-mini', 'gpt-4.1', 'gpt-5-mini', 'gpt-5' );

	/** Creates settings with existing server-key protection.
	 *
	 * @param CredentialCipher $cipher Secret protection.
	 */
	public function __construct( private CredentialCipher $cipher ) {}

	/** Returns internal configuration; never used as an admin read model. */
	private function stored(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/** Returns only fixed fields; no envelope or key is disclosed. */
	public function summary(): array {
		AiAccess::require_admin();
		$data = $this->stored();
		return array(
			'revision'   => is_string( $data['revision'] ?? null ) ? $data['revision'] : '',
			'enabled'    => ! empty( $data['enabled'] ),
			'consent'    => ! empty( $data['consent'] ),
			'mcp'        => ! empty( $data['mcp'] ),
			'model'      => in_array( $data['model'] ?? '', self::MODELS, true ) ? $data['model'] : '',
			'has_key'    => ! empty( $data['key'] ),
			'encryption' => $this->cipher->is_available(),
			'models'     => array_values( array_intersect( self::MODELS, $data['models'] ?? array() ) ),
		);
	}

	/** Credentials are available only to the server-side adapter.
	 *
	 * @throws \RuntimeException When no key is stored.
	 */
	public function credential(): string {
		AiAccess::require_admin();
		$data = $this->stored();
		if ( empty( $data['key'] ) ) {
			throw new \RuntimeException( 'Save an OpenAI API key first.' );
		}
		return $this->cipher->decrypt( $data['key'], 'ai-openai' );
	}

	/** Stores an explicit administrator decision.
	 *
	 * @param array $input Validated by this repository, never trusted form data.
	 * @throws \InvalidArgumentException For invalid opt-in or credentials.
	 * @throws \RuntimeException For changed settings or unavailable writes.
	 */
	public function save( array $input ): void {
		AiAccess::require_admin();
		$token = $this->lock();
		try {
			$data = $this->stored();
			if ( isset( $input['revision'] ) && ( ! is_string( $input['revision'] ) || ! hash_equals( (string) ( $data['revision'] ?? '' ), $input['revision'] ) ) ) {
				throw new \RuntimeException( 'AI settings changed. Reload before saving.' );
			}
			$key = $input['key'] ?? '';
			if ( ! is_string( $key ) || strlen( $key ) > 512 || ! is_string( $input['model'] ?? '' ) ) {
				throw new \InvalidArgumentException( 'Invalid AI settings.' );
			}
			if ( '' !== $key ) {
				if ( ! preg_match( '/^sk-[A-Za-z0-9_-]{16,509}$/D', $key ) ) {
					throw new \InvalidArgumentException( 'Enter a valid OpenAI API key.' );
				}
				$data['key']      = $this->cipher->encrypt( $key, 'ai-openai' );
				$data['models']   = array();
				$input['model']   = '';
				$input['enabled'] = false;
			}
			if ( ! empty( $input['remove_key'] ) ) {
				unset( $data['key'] );
				$data['models']   = array();
				$input['model']   = '';
				$input['enabled'] = false;
			}
			$model = $input['model'] ?? '';
			if ( '' !== $model && ! in_array( $model, array_intersect( self::MODELS, $data['models'] ?? array() ), true ) ) {
				throw new \InvalidArgumentException( 'Refresh models and select an available supported model.' );
			}
			$data['model']   = $model;
			$data['consent'] = ! empty( $input['consent'] );
			$data['mcp']     = ! empty( $input['mcp'] );
			$data['enabled'] = ! empty( $input['enabled'] );
			if ( $data['enabled'] && ( ! $data['consent'] || empty( $data['key'] ) || '' === $model || ! $this->cipher->is_available() ) ) {
				throw new \InvalidArgumentException( 'AI needs encryption, a key, a selected available model and data-sharing consent.' );
			}
			$this->persist( $data );
			AiAudit::record( 'ai_settings', 'saved' );
		} finally {
			$this->unlock( $token );
		}
	}

	/** Records account-visible supported choices without selecting one.
	 *
	 * @param array       $models Account-visible model identifiers.
	 * @param string|null $revision Revision captured before the network request.
	 * @throws \RuntimeException For changed credentials or busy settings.
	 */
	public function record_models( array $models, ?string $revision = null ): void {
		AiAccess::require_admin();
		$token = $this->lock();
		try {
			$data = $this->stored();
			if ( null !== $revision && ! hash_equals( (string) ( $data['revision'] ?? '' ), $revision ) ) {
				throw new \RuntimeException( 'AI settings changed. Refresh models again.' );
			}
			$data['models'] = array_values( array_intersect( self::MODELS, $models ) );
			if ( ! in_array( $data['model'] ?? '', $data['models'], true ) ) {
				$data['enabled'] = false;
				$data['model']   = '';
			}
			$this->persist( $data );
			AiAudit::record( 'ai_settings', 'models_refreshed' );
		} finally {
			$this->unlock( $token );
		}
	}

	/** Verifies a settings write without disclosing stored values.
	 *
	 * @param array $data Protected configuration.
	 * @throws \RuntimeException When persistence fails.
	 */
	private function persist( array $data ): void {
		$data['revision'] = wp_generate_uuid4();
		update_option( self::OPTION, $data, false );
		if ( get_option( self::OPTION ) !== $data ) {
			throw new \RuntimeException( 'AI settings could not be saved.' );
		}
	}

	/** Serializes short settings writes; never holds a lock over a remote request.
	 *
	 * @throws \RuntimeException For busy settings.
	 */
	private function lock(): string {
		$token = wp_generate_uuid4();
		if ( ! add_option( self::OPTION . '_write_lock', $token, '', false ) ) {
			throw new \RuntimeException( 'AI settings are busy. Try again after the current save.' );
		}
		return $token;
	}

	/** Releases only this operation's option lock.
	 *
	 * @param string $token Write claim.
	 */
	private function unlock( string $token ): void {
		if ( get_option( self::OPTION . '_write_lock' ) === $token ) {
			delete_option( self::OPTION . '_write_lock' );
		}
	}
}
