<?php
/**
 * Centralized plugin settings repository.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

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
 *           'active' => string,  // Registered provider ID, e.g. 'smtp'.
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

	public const OPTION_KEY = 'scalyn_mail_relay_settings';

	private const DEFAULTS = array(
		'provider' => array(
			'active' => '',
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
		return absint( $this->data['advanced']['log_retention_days'] ?? 30 );
	}

	/**
	 * Returns whether all plugin data should be removed on uninstall.
	 */
	public function get_delete_data_on_uninstall(): bool {
		return (bool) ( $this->data['advanced']['delete_data_on_uninstall'] ?? false );
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
		$this->data = array_replace_recursive( $this->data, $sanitized );
		return update_option( self::OPTION_KEY, $this->data );
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
	 */
	private function sanitize( array $input ): array {
		$output = array();

		if ( isset( $input['provider']['active'] ) ) {
			$output['provider']['active'] = sanitize_text_field( (string) $input['provider']['active'] );
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
			$adv                                      = $input['advanced'];
			$output['advanced']['log_retention_days'] = absint( $adv['log_retention_days'] ?? 30 );
			$output['advanced']['delete_data_on_uninstall'] = (bool) ( $adv['delete_data_on_uninstall'] ?? false );
		}

		return $output;
	}
}
