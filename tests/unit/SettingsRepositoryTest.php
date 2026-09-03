<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
	}

	public function test_returns_empty_string_when_no_provider_configured(): void {
		$repo = new SettingsRepository();

		$this->assertSame( '', $repo->get_active_provider_id() );
	}

	public function test_returns_configured_provider_id(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
		);

		$repo = new SettingsRepository();

		$this->assertSame( 'smtp', $repo->get_active_provider_id() );
	}

	public function test_smtp_config_returns_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$smtp = $repo->get_smtp_config();

		$this->assertSame( '', $smtp['host'] );
		$this->assertSame( 587, $smtp['port'] );
		$this->assertSame( 'tls', $smtp['encryption'] );
		$this->assertSame( '', $smtp['username'] );
		$this->assertSame( '', $smtp['password'] );
	}

	public function test_get_log_retention_days_returns_default(): void {
		$repo = new SettingsRepository();

		$this->assertSame( 30, $repo->get_log_retention_days() );
	}

	public function test_get_delete_data_on_uninstall_defaults_to_false(): void {
		$repo = new SettingsRepository();

		$this->assertFalse( $repo->get_delete_data_on_uninstall() );
	}

	public function test_save_persists_provider_id_and_is_readable_on_fresh_instance(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'provider' => array( 'active' => 'smtp' ) ) );

		$fresh = new SettingsRepository();
		$this->assertSame( 'smtp', $fresh->get_active_provider_id() );
	}

	public function test_save_rejects_invalid_encryption_and_defaults_to_tls(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'smtp' => array(
					'host'       => 'smtp.example.com',
					'port'       => 587,
					'encryption' => 'ftp',
					'username'   => 'user',
					'password'   => 'pass',
					'from_name'  => 'Test',
					'from_email' => 'test@example.com',
				),
			)
		);

		$this->assertSame( 'tls', $repo->get_smtp_config()['encryption'] );
	}

	public function test_save_accepts_valid_encryption_values(): void {
		$repo = new SettingsRepository();

		foreach ( array( 'tls', 'ssl', 'none' ) as $enc ) {
			$repo->save( array( 'smtp' => array( 'encryption' => $enc ) ) );
			$this->assertSame( $enc, $repo->get_smtp_config()['encryption'] );
		}
	}

	public function test_get_provider_config_returns_smtp_config_for_smtp_id(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array( 'host' => 'smtp.example.com' ),
		);

		$repo   = new SettingsRepository();
		$config = $repo->get_provider_config( 'smtp' );

		$this->assertSame( 'smtp.example.com', $config['host'] );
	}

	public function test_get_provider_config_returns_empty_array_for_unknown_id(): void {
		$repo = new SettingsRepository();

		$this->assertSame( array(), $repo->get_provider_config( 'unknown-provider' ) );
	}

	public function test_unknown_input_keys_are_discarded_on_save(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'injected_key' => 'bad_value' ) );

		$stored = $GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] ?? array();
		$this->assertArrayNotHasKey( 'injected_key', $stored );
	}

	public function test_stored_option_merged_with_defaults_on_load(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array( 'host' => 'mail.example.com' ),
		);

		$repo = new SettingsRepository();
		$smtp = $repo->get_smtp_config();

		// Partial stored value should be merged with defaults.
		$this->assertSame( 'mail.example.com', $smtp['host'] );
		$this->assertSame( 587, $smtp['port'] );
		$this->assertSame( 'tls', $smtp['encryption'] );
	}

	public function test_blank_password_preserves_existing_stored_password(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array(
				'host'     => 'mail.example.com',
				'password' => 'existing-secret',
			),
		);

		$repo = new SettingsRepository();
		$repo->save(
			array(
				'smtp' => array(
					'host'       => 'mail.example.com',
					'port'       => 587,
					'encryption' => 'tls',
					'username'   => 'user',
					'password'   => '',   // Intentionally blank — should preserve existing value.
					'from_name'  => '',
					'from_email' => 'from@example.com',
				),
			)
		);

		$fresh = new SettingsRepository();
		$this->assertSame( 'existing-secret', $fresh->get_smtp_config()['password'] );
	}

	public function test_non_blank_password_replaces_existing_stored_password(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array( 'password' => 'old-password' ),
		);

		$repo = new SettingsRepository();
		$repo->save(
			array(
				'smtp' => array(
					'host'       => 'mail.example.com',
					'port'       => 587,
					'encryption' => 'tls',
					'username'   => 'user',
					'password'   => 'new-password',
					'from_name'  => '',
					'from_email' => 'from@example.com',
				),
			)
		);

		$fresh = new SettingsRepository();
		$this->assertSame( 'new-password', $fresh->get_smtp_config()['password'] );
	}

	// -------------------------------------------------------------------------
	// Partial-save isolation — each top-level key must not clobber others
	// -------------------------------------------------------------------------

	public function test_save_smtp_does_not_overwrite_previously_saved_provider_active(): void {
		// First save: provider only — mirrors Step 2.
		$repo = new SettingsRepository();
		$repo->save( array( 'provider' => array( 'active' => 'smtp' ) ) );

		// Second save on the same instance: smtp only — mirrors Step 3.
		// provider.active must survive.
		$repo->save(
			array(
				'smtp' => array(
					'host'       => 'smtp.example.com',
					'port'       => 587,
					'encryption' => 'tls',
					'username'   => 'user',
					'password'   => 'pass',
					'from_name'  => '',
					'from_email' => 'from@example.com',
				),
			)
		);

		$fresh = new SettingsRepository();
		$this->assertSame(
			'smtp',
			$fresh->get_active_provider_id(),
			'provider.active must not be overwritten when only the smtp key is saved.'
		);
		$this->assertSame(
			'smtp.example.com',
			$fresh->get_smtp_config()['host'],
			'smtp.host must be persisted alongside the preserved provider.active.'
		);
	}

	public function test_save_provider_does_not_overwrite_previously_saved_smtp_host(): void {
		// Pre-store smtp settings in the DB (as if Step 3 already ran).
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array( 'host' => 'mail.example.com' ),
		);

		$repo = new SettingsRepository();
		// Save only the provider key — smtp.host must survive.
		$repo->save( array( 'provider' => array( 'active' => 'smtp' ) ) );

		$fresh = new SettingsRepository();
		$this->assertSame( 'smtp', $fresh->get_active_provider_id() );
		$this->assertSame(
			'mail.example.com',
			$fresh->get_smtp_config()['host'],
			'smtp.host must not be overwritten when only the provider key is saved.'
		);
	}
}
