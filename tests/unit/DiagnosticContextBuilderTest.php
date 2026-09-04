<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;

/**
 * Tests for the credential-safe DiagnosticContextBuilder.
 *
 * The builder is the only production path from plugin settings to a
 * DiagnosticContext, so these tests pin the credential boundary and the
 * sending-domain derivation.
 */
final class DiagnosticContextBuilderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
	}

	private function store_smtp( array $smtp ): SettingsRepository {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => $smtp,
		);
		return new SettingsRepository();
	}

	private function full_smtp(): array {
		return array(
			'host'       => 'smtp.example.org',
			'port'       => '465',
			'encryption' => 'ssl',
			'username'   => 'mailer@example.org',
			'password'   => 'super-secret-password',
			'from_name'  => 'Example',
			'from_email' => 'NoReply@Example.org',
		);
	}

	public function test_context_exposes_only_host_port_and_encryption(): void {
		$context = ( new DiagnosticContextBuilder() )->build( $this->store_smtp( $this->full_smtp() ), 'site.test' );

		$this->assertSame( array( 'host', 'port', 'encryption' ), array_keys( $context->settings ) );
		$this->assertSame( 'smtp.example.org', $context->settings['host'] );
		$this->assertSame( 465, $context->settings['port'], 'Port must be cast to int for SmtpTlsCheck.' );
		$this->assertSame( 'ssl', $context->settings['encryption'] );
	}

	public function test_context_never_contains_credentials(): void {
		$context = ( new DiagnosticContextBuilder() )->build( $this->store_smtp( $this->full_smtp() ), 'site.test' );

		$this->assertArrayNotHasKey( 'username', $context->settings );
		$this->assertArrayNotHasKey( 'password', $context->settings );
		$encoded = (string) json_encode( $context->settings );
		$this->assertStringNotContainsString( 'super-secret-password', $encoded );
		$this->assertStringNotContainsString( 'mailer@example.org', $encoded );
	}

	public function test_domain_is_derived_from_from_email_lowercased(): void {
		$context = ( new DiagnosticContextBuilder() )->build( $this->store_smtp( $this->full_smtp() ), 'site.test' );

		$this->assertSame( 'example.org', $context->domain );
	}

	public function test_domain_falls_back_to_site_host_when_from_email_missing(): void {
		$smtp = $this->full_smtp();
		unset( $smtp['from_email'] );

		$context = ( new DiagnosticContextBuilder() )->build( $this->store_smtp( $smtp ), 'site.test' );

		$this->assertSame( 'site.test', $context->domain );
	}

	public function test_domain_falls_back_to_site_host_when_from_email_invalid(): void {
		$smtp               = $this->full_smtp();
		$smtp['from_email'] = 'not-an-address';

		$context = ( new DiagnosticContextBuilder() )->build( $this->store_smtp( $smtp ), 'site.test' );

		$this->assertSame( 'site.test', $context->domain );
	}

	public function test_unconfigured_smtp_yields_empty_host_and_zero_port(): void {
		// No settings stored at all: defaults apply (port 587, host '').
		$context = ( new DiagnosticContextBuilder() )->build( new SettingsRepository(), 'site.test' );

		$this->assertSame( '', $context->settings['host'] );
		$this->assertSame( 587, $context->settings['port'] );
		$this->assertSame( 'tls', $context->settings['encryption'] );
		$this->assertSame( 'site.test', $context->domain );
	}

	/**
	 * @dataProvider email_domain_provider
	 */
	public function test_domain_from_email( string $email, string $expected ): void {
		$this->assertSame( $expected, DiagnosticContextBuilder::domain_from_email( $email ) );
	}

	public static function email_domain_provider(): array {
		return array(
			'plain'              => array( 'a@example.com', 'example.com' ),
			'subdomain'          => array( 'a@mail.example.co.uk', 'mail.example.co.uk' ),
			'uppercase'          => array( 'A@EXAMPLE.COM', 'example.com' ),
			'padded'             => array( '  a@example.com  ', 'example.com' ),
			'no at'              => array( 'example.com', '' ),
			'empty'              => array( '', '' ),
			'trailing at'        => array( 'a@', '' ),
			'no dot'             => array( 'a@localhost', '' ),
			'leading hyphen'     => array( 'a@-bad.example.com', '' ),
			'spaces in domain'   => array( 'a@exa mple.com', '' ),
			'injection attempt'  => array( 'a@example.com; rm -rf', '' ),
		);
	}
}
