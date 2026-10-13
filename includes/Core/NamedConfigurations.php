<?php
/**
 * Private configuration catalogue transformations (ADR-0033).
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

defined( 'ABSPATH' ) || exit;

/** SettingsRepository owns persistence; this class never reads or writes options. */
final class NamedConfigurations {

	public const PROVIDERS = array( 'smtp', 'sendgrid', 'postmark', 'smtp2go', 'brevo' );

	/**
	 * Imports existing provider slots without rewriting historical evidence.
	 *
	 * @param array            $data Private settings.
	 * @param CredentialCipher $cipher Protector.
	 * @return array Updated private settings.
	 */
	public static function import( #[\SensitiveParameter] array $data, CredentialCipher $cipher ): array {
		if ( isset( $data['configurations'] ) ) {
			return $data;
		}
		$data['configurations'] = array(
			'version'   => 1,
			'active'    => '',
			'items'     => array(),
			'revisions' => array(),
		);
		foreach ( self::PROVIDERS as $provider ) {
			$config = $data[ $provider ] ?? array();
			$active = ( $data['provider']['active'] ?? '' ) === $provider;
			if ( ! $active && ( 'smtp' === $provider ? empty( $config['host'] ) : empty( $config['key_cipher'] ) ) ) {
				continue;
			}
			$id       = wp_generate_uuid4();
			$revision = $active && ! empty( $data['diagnostic_revision'] ) ? $data['diagnostic_revision'] : wp_generate_uuid4();
			// Legacy SMTP can keep sending without a server key. Never duplicate plaintext.
			$legacy                                 = 'smtp' === $provider && ! $cipher->is_available();
			$config                                 = $legacy ? array() : self::protect( $provider, $config, $cipher );
			$data['configurations']['items'][ $id ] = array(
				'name'     => strtoupper( $provider ) . ' — existing',
				'provider' => $provider,
				'revision' => $revision,
				'archived' => false,
			);
			$data['configurations']['revisions'][ $revision ] = array(
				'provider' => $provider,
				'config'   => $config,
				'selector' => $active ? ( $data['advanced']['dkim_selector'] ?? '' ) : '',
				'legacy'   => $legacy,
			);
			if ( $active ) {
				$data['configurations']['active'] = $id;
				$data['diagnostic_revision']      = $revision;
			}
			if ( ! $legacy ) {
				$data[ $provider ] = $config;
			}
		}
		return $data;
	}

	/**
	 * Creates an empty saved configuration without selecting it.
	 *
	 * @param array  $data Private settings.
	 * @param string $provider Registered built-in provider.
	 * @param string $name Display label.
	 * @return array Updated private settings.
	 * @throws \InvalidArgumentException For invalid or excessive configurations.
	 */
	public static function create( #[\SensitiveParameter] array $data, string $provider, string $name ): array {
		self::name( $name );
		if ( ! in_array( $provider, self::PROVIDERS, true ) || count( $data['configurations']['items'] ) >= 50 || count( $data['configurations']['revisions'] ) >= 1000 ) {
			throw new \InvalidArgumentException( 'Invalid provider or configuration limit reached.' );
		}
		$id                                     = wp_generate_uuid4();
		$revision                               = wp_generate_uuid4();
		$data['configurations']['items'][ $id ] = array(
			'name'     => trim( $name ),
			'provider' => $provider,
			'revision' => $revision,
			'archived' => false,
		);
		$data['configurations']['revisions'][ $revision ] = array(
			'provider' => $provider,
			'config'   => array(),
			'selector' => '',
			'legacy'   => false,
		);
		return $data;
	}

	/**
	 * Explicitly selects a saved revision, restoring only its own wizard progress.
	 *
	 * @param array  $data Private settings.
	 * @param string $id Stable identity.
	 * @return array Updated settings.
	 * @throws \InvalidArgumentException For missing/archived configurations.
	 */
	public static function select( #[\SensitiveParameter] array $data, string $id ): array {
		$item = self::item( $data, $id );
		if ( $item['archived'] ) {
			throw new \InvalidArgumentException( 'Archived configurations cannot be selected.' );
		}
		$old_revision = $data['diagnostic_revision'] ?? '';
		if ( '' !== $old_revision ) {
			$data['configurations']['progress'][ $old_revision ] = $data['provider'];
		}
		$snapshot = $data['configurations']['revisions'][ $item['revision'] ];
		if ( ! $snapshot['legacy'] ) {
			$data[ $item['provider'] ] = $snapshot['config'];
		}
		$data['provider']                  = array_merge(
			array(
				'verified'               => false,
				'verified_at'            => null,
				'test_email_accepted_at' => null,
			),
			$data['configurations']['progress'][ $item['revision'] ] ?? array(),
			array( 'active' => $item['provider'] )
		);
		$data['advanced']['dkim_selector'] = $snapshot['selector'];
		$data['diagnostic_revision']       = $item['revision'];
		$data['configurations']['active']  = $id;
		return $data;
	}

	/**
	 * Captures edits as immutable revisions. Provider-only selection uses its saved slot.
	 *
	 * @param array            $before Previous private settings.
	 * @param array            $next Next private settings.
	 * @param CredentialCipher $cipher Protector.
	 * @return array Updated settings.
	 * @throws \RuntimeException For unsupported selection or bounded storage exhaustion.
	 */
	public static function synchronize( #[\SensitiveParameter] array $before, #[\SensitiveParameter] array $next, CredentialCipher $cipher ): array {
		$provider = $next['provider']['active'] ?? '';
		if ( ( $before['provider']['active'] ?? '' ) !== $provider ) {
			foreach ( $before['configurations']['items'] as $id => $item ) {
				if ( $provider === $item['provider'] && ! $item['archived'] ) {
					return self::select( $next, $id );
				}
			}
			throw new \RuntimeException( 'Create a saved configuration before selecting this provider.' );
		}
		$id = $before['configurations']['active'];
		if ( '' === $id ) {
			return $next;
		}
		if ( ( $before[ $provider ] ?? array() ) === ( $next[ $provider ] ?? array() ) && ( $before['advanced']['dkim_selector'] ?? '' ) === ( $next['advanced']['dkim_selector'] ?? '' ) ) {
			return $next;
		}
		if ( count( $before['configurations']['revisions'] ) >= 1000 ) {
			throw new \RuntimeException( 'Configuration revision limit reached. No changes were saved.' );
		}
		$legacy   = ( $before['configurations']['revisions'][ $before['diagnostic_revision'] ]['legacy'] ?? false ) && ( $before[ $provider ] ?? array() ) === ( $next[ $provider ] ?? array() ) && ! $cipher->is_available();
		$config   = $legacy ? array() : self::protect( $provider, $next[ $provider ] ?? array(), $cipher );
		$revision = wp_generate_uuid4();
		if ( ! $legacy ) {
			$next[ $provider ] = $config;
		}
		$next['diagnostic_revision']                        = $revision;
		$next['configurations']['items'][ $id ]['revision'] = $revision;
		$next['configurations']['revisions'][ $revision ]   = array(
			'provider' => $provider,
			'config'   => $config,
			'selector' => $next['advanced']['dkim_selector'] ?? '',
			'legacy'   => $legacy,
		);
		$next['provider']                                   = array(
			'active'                 => $provider,
			'verified'               => false,
			'verified_at'            => null,
			'test_email_accepted_at' => null,
		);
		return $next;
	}

	/**
	 * Resolves a catalogue identity without accepting arbitrary array keys.
	 *
	 * @param array  $data Private settings.
	 * @param string $id Stable UUID.
	 * @return array Private item.
	 * @throws \InvalidArgumentException For missing identities.
	 */
	public static function item( #[\SensitiveParameter] array $data, string $id ): array {
		if ( 1 !== preg_match( '/^[0-9a-f-]{36}$/D', $id ) || ! isset( $data['configurations']['items'][ $id ] ) ) {
			throw new \InvalidArgumentException( 'Saved configuration not found.' );
		}
		return $data['configurations']['items'][ $id ];
	}

	/**
	 * Validates labels without storing free-form audit data.
	 *
	 * @param string $name Label.
	 * @throws \InvalidArgumentException For unsafe labels.
	 */
	public static function name( string $name ): void {
		if ( '' === trim( $name ) || strlen( $name ) > 80 || sanitize_text_field( $name ) !== $name || preg_match( '/[\x00-\x1f\x7f]/', $name ) ) {
			throw new \InvalidArgumentException( 'Use a plain configuration name of 1–80 bytes.' );
		}
	}

	/**
	 * Encrypts SMTP login fields; API slots already contain provider-bound envelopes.
	 *
	 * @param string           $provider Provider.
	 * @param array            $config Private settings slot.
	 * @param CredentialCipher $cipher Protector.
	 * @return array Protected storage representation.
	 * @throws \InvalidArgumentException For excessive SMTP login input.
	 */
	private static function protect( string $provider, #[\SensitiveParameter] array $config, CredentialCipher $cipher ): array {
		if ( 'smtp' === $provider && ! isset( $config['credentials_cipher'] ) ) {
			$login = wp_json_encode(
				array(
					'username' => $config['username'] ?? '',
					'password' => $config['password'] ?? '',
				)
			);
			if ( ! is_string( $login ) || strlen( $login ) > 6000 ) {
				throw new \InvalidArgumentException( 'SMTP login is too long.' );
			}
			$config['credentials_cipher'] = $cipher->encrypt( $login, 'smtp' );
			$config['has_password']       = '' !== ( $config['password'] ?? '' );
			$config['has_username']       = '' !== ( $config['username'] ?? '' );
			$config['username']           = '';
			$config['password']           = '';
		}
		return $config;
	}
}
