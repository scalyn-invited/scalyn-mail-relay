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
	 */
	public function __construct() {
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

		return array();
	}

	/**
	 * Returns the number of days mail log records are retained before cleanup.
	 */
	public function get_log_retention_days(): int {
		$value = $this->data['advanced']['log_retention_days'] ?? 30;
		return self::valid_retention_days( $value ) ? (int) $value : 30;
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
