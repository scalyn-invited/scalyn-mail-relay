<?php
/**
 * Centralized plugin settings repository.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Audit\AuditEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Single access point for reading and writing all plugin settings.
 *
 * All plugin code must read settings through this class.
 * Direct calls to get_option( 'scalyn_mail_relay_settings' ) are prohibited
 * outside of this class.
 *
 * Stored option structure:
 *
 *   array(
 *       'provider' => array(
 *           'active'                 => string,      // Registered provider ID, e.g. 'smtp'.
 *           'verified'               => bool,        // True if connection/send verified after config.
 *           'verified_at'            => string|null, // ISO 8601 timestamp of last successful verification.
 *           'test_email_accepted_at' => string|null, // ISO 8601 timestamp of the last accepted wizard test email.
 *       ),
 *       'smtp' => array(
 *           'host'       => string,
 *           'port'       => int,
 *           'encryption' => string,  // 'tls' | 'ssl' | 'none'
 *           'username'   => string,
 *           'password'   => string,  // @see security note below
 *           'from_name'  => string,
 *           'from_email' => string,
 *       ),
 *       'advanced' => array(
 *           'log_retention_days'       => int,
 *           'delete_data_on_uninstall' => bool,
 *       ),
 *   )
 *
 * @security SMTP credentials (smtp.password, smtp.username) and any future
 * provider API keys or OAuth tokens must NEVER appear in:
 *   - PHP error logs or debug output
 *   - REST API responses
 *   - Exported reports
 *   - Diagnostic evidence or raw diagnostic results
 *   - Exception messages
 *
 * The get_smtp_config() and get_provider_config() return values contain the
 * password field. Callers must treat the returned array as sensitive and must
 * not log, serialize, or expose it.
 */
final class SettingsRepository {

	public const OPTION_KEY           = 'scalyn_mail_relay_settings';
	public const DIAGNOSTIC_SCHEDULES = array( 'disabled', 'hourly', 'twicedaily', 'daily' );

	private const DEFAULTS = array(
		'provider' => array(
			'active'                 => '',
			'verified'               => false,
			'verified_at'            => null,
			'test_email_accepted_at' => null,
		),
		'smtp'     => array(
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'username'   => '',
			'password'   => '',
			'from_name'  => '',
			'from_email' => '',
		),
		'advanced' => array(
			'dkim_selector'            => '',
			'alert_webhook_enabled'    => false,
			'diagnostic_schedule'      => 'disabled',
			'log_retention_days'       => 30,
			'log_message_metadata'     => false,
			'delete_data_on_uninstall' => false,
		),
	);

	/**
	 * Merged settings loaded from the database option.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Loads settings from the database and merges with defaults.
	 *
	 * @param CredentialCipher|null $cipher Optional credential service for transport use.
	 */
	public function __construct( private readonly ?CredentialCipher $cipher = null ) {
		$stored     = get_option( self::OPTION_KEY, array() );
		$this->data = is_array( $stored )
			? array_replace_recursive( self::DEFAULTS, $stored )
			: self::DEFAULTS;
	}

	/**
	 * Returns the ID of the currently configured active provider.
	 * Returns an empty string when no provider has been configured.
	 */
	public function get_active_provider_id(): string {
		return (string) ( $this->data['provider']['active'] ?? '' );
	}

	/**
	 * Returns whether the currently configured provider has been verified.
	 *
	 * Verified means the provider's connection has been tested and succeeded,
	 * or a test email has been sent successfully, or a real send succeeded.
	 *
	 * @return bool True if verified; false if not yet verified or no provider configured.
	 */
	public function is_provider_verified(): bool {
		return (bool) ( $this->data['provider']['verified'] ?? false );
	}

	/**
	 * Returns the ISO 8601 timestamp of the last successful provider verification.
	 *
	 * @return string|null ISO 8601 timestamp, or null if never verified.
	 */
	public function get_provider_verified_at(): ?string {
		return $this->data['provider']['verified_at'] ?? null;
	}

	/**
	 * Marks the provider as verified with a timestamp.
	 *
	 * Called after a successful connection test, test email send, or real send.
	 *
	 * @return bool True on success; false if the option was not updated.
	 */
	public function mark_provider_verified(): bool {
		$this->data['provider']['verified']    = true;
		$this->data['provider']['verified_at'] = current_time( 'c' );
		return update_option( self::OPTION_KEY, $this->data );
	}

	/**
	 * Returns whether the setup wizard has recorded an accepted test email.
	 *
	 * Provider verification alone is insufficient because a connection test can
	 * verify the provider without sending a message.
	 */
	public function has_accepted_test_email(): bool {
		return null !== ( $this->data['provider']['test_email_accepted_at'] ?? null );
	}

	/**
	 * Records an accepted setup-wizard test email and verifies the provider.
	 *
	 * Accepted means the configured provider acknowledged the message. It does
	 * not assert inbox delivery.
	 *
	 * @return bool True on success; false if the option was not updated.
	 */
	public function mark_test_email_accepted(): bool {
		$now = current_time( 'c' );

		$this->data['provider']['verified']               = true;
		$this->data['provider']['verified_at']            = $now;
		$this->data['provider']['test_email_accepted_at'] = $now;

		return update_option( self::OPTION_KEY, $this->data );
	}

	/**
	 * Returns the full SMTP transport configuration array.
	 *
	 * @security The returned array contains the SMTP password. Do not log,
	 * serialize, or expose this value.
	 *
	 * @return array{host:string,port:int,encryption:string,username:string,password:string,from_name:string,from_email:string}
	 */
	public function get_smtp_config(): array {
		return $this->data['smtp'] ?? self::DEFAULTS['smtp'];
	}

	/**
	 * Returns the provider-specific configuration array for the given provider ID.
	 * Returns an empty array when the provider ID is not recognized.
	 *
	 * @security The returned array may contain credentials. Do not log,
	 * serialize, or expose it.
	 *
	 * @param string $provider_id The registered provider ID.
	 * @return array<string, mixed>
	 */
	public function get_provider_config( string $provider_id ): array {
		if ( 'smtp' === $provider_id ) {
			return $this->get_smtp_config();
		}
		if ( in_array( $provider_id, array( 'sendgrid', 'postmark', 'smtp2go', 'brevo' ), true ) ) {
			$config = $this->data[ $provider_id ] ?? array();
			$stored = $config['key_cipher'] ?? '';
			if ( ! is_string( $stored ) || '' === $stored ) {
				return array();
			}
			return array(
				'api_key'    => ( $this->cipher ?? new CredentialCipher() )->decrypt( $stored, $provider_id ),
				'from_email' => $config['from_email'] ?? '',
				'from_name'  => $config['from_name'] ?? '',
			);
		}

		return array();
	}

	/**
	 * Credential-free SendGrid configuration for Admin.
	 *
	 * @return array Public configuration and credential presence only.
	 */
	public function get_sendgrid_settings(): array {
		$config = $this->data['sendgrid'] ?? array();
		return array(
			'from_email' => is_string( $config['from_email'] ?? null ) ? sanitize_email( $config['from_email'] ) : '',
			'from_name'  => is_string( $config['from_name'] ?? null ) ? sanitize_text_field( $config['from_name'] ) : '',
			'has_key'    => ! empty( $config['key_cipher'] ),
		);
	}

	/**
	 * Saves SendGrid configuration, with explicit credential intent.
	 *
	 * @param array            $input Strictly validated configuration and action.
	 * @param CredentialCipher $cipher Credential protection service.
	 * @return bool Whether the desired settings were persisted (including no-op).
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function save_sendgrid( #[\SensitiveParameter] array $input, CredentialCipher $cipher ): bool {
		$action = $input['key_action'] ?? null;
		$secret = $input['api_key'] ?? '';
		$email  = $input['from_email'] ?? null;
		$name   = $input['from_name'] ?? null;
		if ( ! in_array( $action, array( 'keep', 'replace', 'remove' ), true ) || ! is_string( $secret )
			|| ! is_string( $email ) || strlen( $email ) > 254 || ! filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name )
			|| ( 'replace' !== $action && '' !== $secret )
			|| ( 'replace' === $action && ( strlen( $secret ) < 16 || strlen( $secret ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $secret ) ) )
			|| ( 'remove' === $action && true !== ( $input['confirm_remove'] ?? false ) ) ) {
			throw new \InvalidArgumentException( 'Invalid SendGrid settings. No changes were saved.' );
		}
		$before = $this->data;
		$old    = $before['sendgrid'] ?? array();
		$stored = $old['key_cipher'] ?? '';
		if ( 'replace' === $action ) {
			$stored = $cipher->encrypt( $secret );
		} elseif ( 'remove' === $action ) {
			$stored = '';
		} elseif ( ! is_string( $stored ) || '' === $stored ) {
			throw new \InvalidArgumentException( 'Provide a new API key using Replace.' );
		} else {
			$cipher->decrypt( $stored );
		}
		$config = array(
			'from_email' => $email,
			'from_name'  => sanitize_text_field( $name ),
			'key_cipher' => $stored,
		);
		if ( $config === $old ) {
			return true;
		}
		$next             = $before;
		$next['sendgrid'] = $config;
		if ( 'sendgrid' === $this->get_active_provider_id() ) {
			$next['provider']['verified']               = false;
			$next['provider']['verified_at']            = null;
			$next['provider']['test_email_accepted_at'] = null;
		}
		$next = self::with_diagnostic_revision( $before, $next );
		if ( ! update_option( self::OPTION_KEY, $next ) ) {
			return false;
		}
		$this->data = $next;
		$fields     = array();
		foreach ( array( 'from_email', 'from_name', 'key_cipher' ) as $field ) {
			if ( ( $old[ $field ] ?? null ) !== $config[ $field ] ) {
				$fields[] = 'sendgrid.' . ( 'key_cipher' === $field ? 'api_key' : $field );
			}
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'settings_changed', 'changed', '', $fields ) );
		} catch ( \Throwable $error ) {
			// Audit observer failure must not repeat or undo an already saved credential.
			return true;
		}
		return true;
	}

	/**
	 * Credential-free Postmark configuration for Admin.
	 *
	 * @return array Public configuration and credential presence only.
	 */
	public function get_postmark_settings(): array {
		$config = $this->data['postmark'] ?? array();
		return array(
			'from_email' => is_string( $config['from_email'] ?? null ) ? sanitize_email( $config['from_email'] ) : '',
			'from_name'  => is_string( $config['from_name'] ?? null ) ? sanitize_text_field( $config['from_name'] ) : '',
			'has_key'    => ! empty( $config['key_cipher'] ),
		);
	}

	/**
	 * Saves Postmark configuration, with explicit credential intent.
	 *
	 * @param array            $input Strictly validated configuration and action.
	 * @param CredentialCipher $cipher Credential protection service.
	 * @return bool Whether the desired settings were persisted (including no-op).
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function save_postmark( #[\SensitiveParameter] array $input, CredentialCipher $cipher ): bool {
		$action = $input['key_action'] ?? null;
		$secret = $input['api_key'] ?? '';
		$email  = $input['from_email'] ?? null;
		$name   = $input['from_name'] ?? null;
		if ( ! in_array( $action, array( 'keep', 'replace', 'remove' ), true ) || ! is_string( $secret )
			|| ! is_string( $email ) || strlen( $email ) > 254 || ! filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name )
			|| ( 'replace' !== $action && '' !== $secret )
			|| ( 'replace' === $action && ( 'POSTMARK_API_TEST' === $secret || strlen( $secret ) < 16 || strlen( $secret ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $secret ) ) )
			|| ( 'remove' === $action && true !== ( $input['confirm_remove'] ?? false ) ) ) {
			throw new \InvalidArgumentException( 'Invalid Postmark settings. No changes were saved.' );
		}
		$before = $this->data;
		$old    = $before['postmark'] ?? array();
		$stored = $old['key_cipher'] ?? '';
		if ( 'replace' === $action ) {
			$stored = $cipher->encrypt( $secret, 'postmark' );
		} elseif ( 'remove' === $action ) {
			$stored = '';
		} elseif ( ! is_string( $stored ) || '' === $stored ) {
			throw new \InvalidArgumentException( 'Provide a new API key using Replace.' );
		} else {
			$cipher->decrypt( $stored, 'postmark' );
		}
		$config = array(
			'from_email' => $email,
			'from_name'  => sanitize_text_field( $name ),
			'key_cipher' => $stored,
		);
		if ( $config === $old ) {
			return true;
		}
		$next             = $before;
		$next['postmark'] = $config;
		if ( 'postmark' === $this->get_active_provider_id() ) {
			$next['provider']['verified']               = false;
			$next['provider']['verified_at']            = null;
			$next['provider']['test_email_accepted_at'] = null;
		}
		$next = self::with_diagnostic_revision( $before, $next );
		if ( ! update_option( self::OPTION_KEY, $next ) ) {
			return false;
		}
		$this->data = $next;
		$fields     = array();
		foreach ( array( 'from_email', 'from_name', 'key_cipher' ) as $field ) {
			if ( ( $old[ $field ] ?? null ) !== $config[ $field ] ) {
				$fields[] = 'postmark.' . ( 'key_cipher' === $field ? 'api_key' : $field );
			}
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'settings_changed', 'changed', '', $fields ) );
		} catch ( \Throwable $error ) {
			// Audit observer failure must not repeat or undo an already saved credential.
			return true;
		}
		return true;
	}

	/**
	 * Credential-free SMTP2GO configuration for Admin.
	 *
	 * @return array Public configuration and credential presence only.
	 */
	public function get_smtp2go_settings(): array {
		$config = $this->data['smtp2go'] ?? array();
		return array(
			'from_email' => is_string( $config['from_email'] ?? null ) ? sanitize_email( $config['from_email'] ) : '',
			'from_name'  => is_string( $config['from_name'] ?? null ) ? sanitize_text_field( $config['from_name'] ) : '',
			'has_key'    => ! empty( $config['key_cipher'] ),
		);
	}

	/**
	 * Saves SMTP2GO configuration, with explicit credential intent.
	 *
	 * @param array            $input Strictly validated configuration and action.
	 * @param CredentialCipher $cipher Credential protection service.
	 * @return bool Whether the desired settings were persisted (including no-op).
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function save_smtp2go( #[\SensitiveParameter] array $input, CredentialCipher $cipher ): bool {
		$action = $input['key_action'] ?? null;
		$secret = $input['api_key'] ?? '';
		$email  = $input['from_email'] ?? null;
		$name   = $input['from_name'] ?? null;
		if ( ! in_array( $action, array( 'keep', 'replace', 'remove' ), true ) || ! is_string( $secret )
			|| ! is_string( $email ) || strlen( $email ) > 254 || ! filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name )
			|| ( 'replace' !== $action && '' !== $secret )
			|| ( 'replace' === $action && ( strlen( $secret ) < 16 || strlen( $secret ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $secret ) ) )
			|| ( 'remove' === $action && true !== ( $input['confirm_remove'] ?? false ) ) ) {
			throw new \InvalidArgumentException( 'Invalid SMTP2GO settings. No changes were saved.' );
		}
		$before = $this->data;
		$old    = $before['smtp2go'] ?? array();
		$stored = $old['key_cipher'] ?? '';
		if ( 'replace' === $action ) {
			$stored = $cipher->encrypt( $secret, 'smtp2go' );
		} elseif ( 'remove' === $action ) {
			$stored = '';
		} elseif ( ! is_string( $stored ) || '' === $stored ) {
			throw new \InvalidArgumentException( 'Provide a new API key using Replace.' );
		} else {
			$cipher->decrypt( $stored, 'smtp2go' );
		}
		$config = array(
			'from_email' => $email,
			'from_name'  => sanitize_text_field( $name ),
			'key_cipher' => $stored,
		);
		if ( $config === $old ) {
			return true;
		}
		$next            = $before;
		$next['smtp2go'] = $config;
		if ( 'smtp2go' === $this->get_active_provider_id() ) {
			$next['provider']['verified']               = false;
			$next['provider']['verified_at']            = null;
			$next['provider']['test_email_accepted_at'] = null;
		}
		$next = self::with_diagnostic_revision( $before, $next );
		if ( ! update_option( self::OPTION_KEY, $next ) ) {
			return false;
		}
		$this->data = $next;
		$fields     = array();
		foreach ( array( 'from_email', 'from_name', 'key_cipher' ) as $field ) {
			if ( ( $old[ $field ] ?? null ) !== $config[ $field ] ) {
				$fields[] = 'smtp2go.' . ( 'key_cipher' === $field ? 'api_key' : $field );
			}
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'settings_changed', 'changed', '', $fields ) );
		} catch ( \Throwable $error ) {
			// Audit observer failure must not repeat or undo an already saved credential.
			return true;
		}
		return true;
	}

	/**
	 * Credential-free Brevo configuration for Admin.
	 *
	 * @return array Public configuration and credential presence only.
	 */
	public function get_brevo_settings(): array {
		$config = $this->data['brevo'] ?? array();
		return array(
			'from_email' => is_string( $config['from_email'] ?? null ) ? sanitize_email( $config['from_email'] ) : '',
			'from_name'  => is_string( $config['from_name'] ?? null ) ? sanitize_text_field( $config['from_name'] ) : '',
			'has_key'    => ! empty( $config['key_cipher'] ),
		);
	}

	/**
	 * Saves Brevo configuration, with explicit credential intent.
	 *
	 * @param array            $input Strictly validated configuration and action.
	 * @param CredentialCipher $cipher Credential protection service.
	 * @return bool Whether the desired settings were persisted (including no-op).
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function save_brevo( #[\SensitiveParameter] array $input, CredentialCipher $cipher ): bool {
		$action = $input['key_action'] ?? null;
		$secret = $input['api_key'] ?? '';
		$email  = $input['from_email'] ?? null;
		$name   = $input['from_name'] ?? null;
		if ( ! in_array( $action, array( 'keep', 'replace', 'remove' ), true ) || ! is_string( $secret )
			|| ! is_string( $email ) || strlen( $email ) > 254 || ! filter_var( $email, FILTER_VALIDATE_EMAIL )
			|| ! is_string( $name ) || strlen( $name ) > 200 || preg_match( '/[\r\n\x00]/', $name )
			|| ( 'replace' !== $action && '' !== $secret )
			|| ( 'replace' === $action && ( strlen( $secret ) < 16 || strlen( $secret ) > 512 || ! preg_match( '/^[A-Za-z0-9._-]+$/D', $secret ) ) )
			|| ( 'remove' === $action && true !== ( $input['confirm_remove'] ?? false ) ) ) {
			throw new \InvalidArgumentException( 'Invalid Brevo settings. No changes were saved.' );
		}
		$before = $this->data;
		$old    = $before['brevo'] ?? array();
		$stored = $old['key_cipher'] ?? '';
		if ( 'replace' === $action ) {
			$stored = $cipher->encrypt( $secret, 'brevo' );
		} elseif ( 'remove' === $action ) {
			$stored = '';
		} elseif ( ! is_string( $stored ) || '' === $stored ) {
			throw new \InvalidArgumentException( 'Provide a new API key using Replace.' );
		} else {
			$cipher->decrypt( $stored, 'brevo' );
		}
		$config = array(
			'from_email' => $email,
			'from_name'  => sanitize_text_field( $name ),
			'key_cipher' => $stored,
		);
		if ( $config === $old ) {
			return true;
		}
		$next          = $before;
		$next['brevo'] = $config;
		if ( 'brevo' === $this->get_active_provider_id() ) {
			$next['provider']['verified']               = false;
			$next['provider']['verified_at']            = null;
			$next['provider']['test_email_accepted_at'] = null;
		}
		$next = self::with_diagnostic_revision( $before, $next );
		if ( ! update_option( self::OPTION_KEY, $next ) ) {
			return false;
		}
		$this->data = $next;
		$fields     = array();
		foreach ( array( 'from_email', 'from_name', 'key_cipher' ) as $field ) {
			if ( ( $old[ $field ] ?? null ) !== $config[ $field ] ) {
				$fields[] = 'brevo.' . ( 'key_cipher' === $field ? 'api_key' : $field );
			}
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'settings_changed', 'changed', '', $fields ) );
		} catch ( \Throwable $error ) {
			// Audit observer failure must not repeat or undo an already saved credential.
			return true;
		}
		return true;
	}

	/**
	 * Returns the number of days mail log records are retained before cleanup.
	 */
	public function get_log_retention_days(): int {
		$value = $this->data['advanced']['log_retention_days'] ?? 30;
		return self::valid_retention_days( $value ) ? (int) $value : 30;
	}

	/** Returns explicit consent to retain recipient and subject metadata. */
	public function get_log_message_metadata(): bool {
		return true === ( $this->data['advanced']['log_message_metadata'] ?? false );
	}

	/** Returns the validated opt-in monitoring cadence. */
	public function get_diagnostic_schedule(): string {
		$value = $this->data['advanced']['diagnostic_schedule'] ?? 'disabled';
		return in_array( $value, self::DIAGNOSTIC_SCHEDULES, true ) ? $value : 'disabled';
	}

	/** Returns strict opt-in; endpoint credentials live only in server configuration. */
	public function get_alert_webhook_enabled(): bool {
		return true === ( $this->data['advanced']['alert_webhook_enabled'] ?? false );
	}

	/** Returns only a validated, explicitly configured public DNS selector. */
	public function get_dkim_selector(): string {
		$value = $this->data['advanced']['dkim_selector'] ?? '';
		return self::valid_dkim_selector( $value ) ? trim( $value ) : '';
	}

	/**
	 * Validates the single-label selector supported by the DKIM check; blank clears it.
	 *
	 * @param mixed $value Submitted selector, never a public/private key.
	 */
	public static function valid_dkim_selector( mixed $value ): bool {
		return is_string( $value ) && ( '' === trim( $value ) || 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9])?$/D', trim( $value ) ) );
	}

	/**
	 * Accepts only whole days between one day and ten years. Zero is not unlimited.
	 *
	 * @param mixed $value Submitted or stored value.
	 */
	public static function valid_retention_days( mixed $value ): bool {
		return ( is_int( $value ) || is_string( $value ) )
			&& 1 === preg_match( '/^[1-9][0-9]{0,3}$/D', (string) $value )
			&& (int) $value <= 3650;
	}

	/**
	 * Returns whether all plugin data should be removed on uninstall.
	 */
	public function get_delete_data_on_uninstall(): bool {
		return true === ( $this->data['advanced']['delete_data_on_uninstall'] ?? false );
	}

	/**
	 * Persists settings after sanitization. Only recognized keys are accepted.
	 * Unknown keys in the input are silently discarded.
	 *
	 * @param array<string, mixed> $new_settings Unsanitized input, typically from a form POST.
	 * @return bool True on success; false if the option was not updated.
	 */
	public function save( array $new_settings ): bool {
		$sanitized  = $this->sanitize( $new_settings );
		$before     = $this->data;
		$this->data = array_replace_recursive( $this->data, $sanitized );
		if ( isset( $sanitized['provider']['active'] ) && ( $before['provider']['active'] ?? '' ) !== $sanitized['provider']['active'] ) {
			$this->data['provider']['verified']               = false;
			$this->data['provider']['verified_at']            = null;
			$this->data['provider']['test_email_accepted_at'] = null;
		}
		$this->data = self::with_diagnostic_revision( $before, $this->data );
		$saved      = update_option( self::OPTION_KEY, $this->data );
		if ( ! $saved ) {
			$this->data = $before;
		}
		if ( $saved ) {
			$changes = array();
			foreach ( AuditEvent::FIELDS as $field ) {
				list( $group, $key ) = explode( '.', $field );
				if ( ( $before[ $group ][ $key ] ?? null ) !== ( $this->data[ $group ][ $key ] ?? null ) ) {
					$action = match ( $field ) {
						'advanced.log_retention_days' => 'retention_changed',
						'advanced.delete_data_on_uninstall' => 'uninstall_policy_changed',
						default => 'settings_changed',
					};
					$changes[ $action ][] = $field;
				}
			}
			foreach ( $changes as $action => $fields ) {
				do_action( HookNames::AUDIT_EVENT, new AuditEvent( $action, 'changed', '', $fields ) );
			}
		}
		return $saved;
	}

	/** Returns the opaque current diagnostic configuration revision. */
	public function get_diagnostic_revision(): string {
		return (string) ( $this->data['diagnostic_revision'] ?? '' );
	}

	/** Initializes upgraded installations before their first scoped run.
	 *
	 * @throws \RuntimeException When the revision cannot be saved.
	 */
	public function ensure_diagnostic_revision(): string {
		if ( '' === $this->get_diagnostic_revision() && ! $this->save( array() ) ) {
			throw new \RuntimeException( 'Diagnostic configuration could not be saved.' );
		}
		return $this->get_diagnostic_revision();
	}

	/** Rotates an opaque revision, never a credential-derived fingerprint.
	 *
	 * @param array $before Previous private settings.
	 * @param array $next New private settings.
	 * @return array Settings with their diagnostic revision.
	 */
	private static function with_diagnostic_revision( array $before, array $next ): array {
		$provider = (string) ( $next['provider']['active'] ?? '' );
		if ( empty( $before['diagnostic_revision'] )
			|| ( $before['provider']['active'] ?? '' ) !== $provider
			|| ( $before[ $provider ] ?? array() ) !== ( $next[ $provider ] ?? array() )
			|| ( $before['advanced']['dkim_selector'] ?? '' ) !== ( $next['advanced']['dkim_selector'] ?? '' ) ) {
			$next['diagnostic_revision'] = wp_generate_uuid4();
		}
		return $next;
	}

	/**
	 * Sanitizes recognized fields from a raw input array.
	 *
	 * @security The SMTP password is stored verbatim and is intentionally not
	 * passed through sanitize_text_field(). That function strips tags and trims
	 * whitespace, which can corrupt complex passwords containing special characters.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized subset.
	 * @throws \InvalidArgumentException When retention or deletion confirmation is invalid.
	 */
	private function sanitize( array $input ): array {
		$output = array();

		if ( isset( $input['provider']['active'] ) ) {
			$output['provider']['active'] = sanitize_text_field( (string) $input['provider']['active'] );
		}

		if ( isset( $input['provider']['verified'] ) ) {
			$output['provider']['verified'] = (bool) $input['provider']['verified'];
		}

		if ( isset( $input['provider']['verified_at'] ) ) {
			$output['provider']['verified_at'] = sanitize_text_field( (string) $input['provider']['verified_at'] );
		}

		if ( isset( $input['provider']['test_email_accepted_at'] ) ) {
			$output['provider']['test_email_accepted_at'] = sanitize_text_field( (string) $input['provider']['test_email_accepted_at'] );
		}

		if ( isset( $input['smtp'] ) && is_array( $input['smtp'] ) ) {
			$smtp = $input['smtp'];

			$output['smtp']['host']       = sanitize_text_field( (string) ( $smtp['host'] ?? '' ) );
			$output['smtp']['port']       = absint( $smtp['port'] ?? 587 );
			$output['smtp']['username']   = sanitize_text_field( (string) ( $smtp['username'] ?? '' ) );
			$output['smtp']['from_name']  = sanitize_text_field( (string) ( $smtp['from_name'] ?? '' ) );
			$output['smtp']['from_email'] = sanitize_email( (string) ( $smtp['from_email'] ?? '' ) );

			$output['smtp']['encryption'] = in_array(
				(string) ( $smtp['encryption'] ?? '' ),
				array( 'tls', 'ssl', 'none' ),
				true
			) ? (string) $smtp['encryption'] : 'tls';

			// @security Stored verbatim — see method docblock.
			// A blank submitted password preserves the currently stored value rather
			// than overwriting it with an empty string. This allows the edit form to
			// render without exposing the stored password in an HTML value attribute.
			$submitted_password         = (string) ( $smtp['password'] ?? '' );
			$output['smtp']['password'] = '' !== $submitted_password
				? $submitted_password
				: (string) ( $this->data['smtp']['password'] ?? '' );
		}

		if ( isset( $input['advanced'] ) && is_array( $input['advanced'] ) ) {
			$adv = $input['advanced'];
			if ( array_key_exists( 'dkim_selector', $adv ) ) {
				if ( ! self::valid_dkim_selector( $adv['dkim_selector'] ) ) {
					throw new \InvalidArgumentException( 'Invalid DKIM selector.' );
				}
				$output['advanced']['dkim_selector'] = trim( $adv['dkim_selector'] );
			}
			if ( array_key_exists( 'alert_webhook_enabled', $adv ) ) {
				if ( ! is_bool( $adv['alert_webhook_enabled'] ) ) {
					throw new \InvalidArgumentException( 'Invalid webhook enable setting.' );
				}
				$output['advanced']['alert_webhook_enabled'] = $adv['alert_webhook_enabled'];
			}
			if ( array_key_exists( 'diagnostic_schedule', $adv ) ) {
				if ( ! in_array( $adv['diagnostic_schedule'], self::DIAGNOSTIC_SCHEDULES, true ) ) {
					throw new \InvalidArgumentException( 'Invalid diagnostic schedule.' );
				}
				$output['advanced']['diagnostic_schedule'] = $adv['diagnostic_schedule'];
			}
			if ( array_key_exists( 'log_message_metadata', $adv ) ) {
				if ( ! is_bool( $adv['log_message_metadata'] ) ) {
					throw new \InvalidArgumentException( 'Invalid mail metadata setting.' );
				}
				$output['advanced']['log_message_metadata'] = $adv['log_message_metadata'];
			}
			if ( array_key_exists( 'log_retention_days', $adv ) ) {
				if ( ! self::valid_retention_days( $adv['log_retention_days'] ) ) {
					throw new \InvalidArgumentException( 'Retention must be a whole number from 1 to 3650 days.' );
				}
				$output['advanced']['log_retention_days'] = (int) $adv['log_retention_days'];
			}
			if ( array_key_exists( 'delete_data_on_uninstall', $adv ) ) {
				$delete = true === $adv['delete_data_on_uninstall'];
				if ( $delete && ! $this->get_delete_data_on_uninstall() && true !== ( $adv['confirm_delete_data'] ?? false ) ) {
					throw new \InvalidArgumentException( 'Explicit confirmation is required to enable uninstall deletion.' );
				}
				$output['advanced']['delete_data_on_uninstall'] = $delete;
			}
		}

		return $output;
	}
}
