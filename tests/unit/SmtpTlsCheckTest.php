<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Providers\Mail\SmtpTlsCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/**
 * Unit tests for SmtpTlsCheck.
 *
 * All socket/TLS probing is stubbed via constructor injection; no real
 * network calls are ever made. Covers reachability, STARTTLS/TLS
 * negotiation and certificate evidence per the S1 ticket's test list.
 */
final class SmtpTlsCheckTest extends TestCase {

	private function make_check( array|false $probe_result ): SmtpTlsCheck {
		return new SmtpTlsCheck( static fn( string $host, int $port, string $encryption ): array|false => $probe_result );
	}

	private function make_context( string $host = 'smtp.example.com', int $port = 587, string $encryption = 'tls', array $extra = array() ): DiagnosticContext {
		return new DiagnosticContext(
			'example.com',
			array_merge(
				array(
					'host'       => $host,
					'port'       => $port,
					'encryption' => $encryption,
				),
				$extra
			)
		);
	}

	private function reachable( array $overrides = array() ): array {
		return array_merge(
			array(
				'reachable'        => true,
				'error'            => null,
				'starttls_offered' => true,
				'auth_offered'     => true,
				'tls_negotiated'   => true,
				'protocol'         => 'TLSv1.3',
				'cipher'           => 'TLS_AES_256_GCM_SHA384',
				'cert'             => $this->healthy_cert(),
			),
			$overrides
		);
	}

	private function healthy_cert( array $overrides = array() ): array {
		return array_merge(
			array(
				'subject_cn'      => 'smtp.example.com',
				'issuer_cn'       => 'Example CA',
				'expired'         => false,
				'expires_in_days' => 90,
				'hostname_match'  => true,
			),
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_get_id_and_category(): void {
		$check = $this->make_check( false );

		$this->assertSame( 'smtp_tls', $check->get_id() );
		$this->assertSame( 'smtp', $check->get_category() );
	}

	// -------------------------------------------------------------------------
	// Config validation
	// -------------------------------------------------------------------------

	public function test_returns_unknown_for_missing_host(): void {
		$result = $this->make_check( false )->run( $this->make_context( host: '' ) );

		$this->assertSame( 'unknown', $result->status );
	}

	/** @dataProvider invalidHostProvider */
	public function test_returns_unknown_for_invalid_host( string $host ): void {
		$result = $this->make_check( false )->run( $this->make_context( host: $host ) );

		$this->assertSame( 'unknown', $result->status );
	}

	public static function invalidHostProvider(): array {
		return array(
			'control chars'  => array( "smtp.example.com\r\nInjected" ),
			'space injected' => array( 'smtp example.com' ),
			'url scheme'     => array( 'smtp://smtp.example.com' ),
		);
	}

	public function test_returns_unknown_for_invalid_port(): void {
		$result = $this->make_check( false )->run( $this->make_context( port: 0 ) );

		$this->assertSame( 'unknown', $result->status );
	}

	public function test_does_not_perform_probe_for_invalid_config(): void {
		$probe_called = false;
		$check        = new SmtpTlsCheck(
			static function () use ( &$probe_called ): array|false {
				$probe_called = true;
				return false;
			}
		);

		$check->run( $this->make_context( host: '' ) );

		$this->assertFalse( $probe_called );
	}

	// -------------------------------------------------------------------------
	// Probe could not be completed
	// -------------------------------------------------------------------------

	public function test_returns_unknown_when_probe_returns_false(): void {
		$result = $this->make_check( false )->run( $this->make_context() );

		$this->assertSame( 'unknown', $result->status );
	}

	// -------------------------------------------------------------------------
	// Connection failure
	// -------------------------------------------------------------------------

	public function test_returns_unknown_for_connection_timeout(): void {
		$result = $this->make_check( array( 'reachable' => false, 'error' => 'timeout' ) )->run( $this->make_context() );

		$this->assertSame( 'unknown', $result->status );
	}

	public function test_returns_unknown_for_dns_failure(): void {
		$result = $this->make_check( array( 'reachable' => false, 'error' => 'dns' ) )->run( $this->make_context() );

		$this->assertSame( 'unknown', $result->status );
	}

	public function test_returns_fail_for_connection_refused(): void {
		$result = $this->make_check( array( 'reachable' => false, 'error' => 'refused' ) )->run( $this->make_context() );

		$this->assertSame( 'fail', $result->status );
	}

	// -------------------------------------------------------------------------
	// Encryption: none
	// -------------------------------------------------------------------------

	public function test_returns_pass_when_none_configured_and_starttls_not_offered(): void {
		$probe  = $this->reachable( array( 'starttls_offered' => false, 'tls_negotiated' => false, 'cert' => null ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'none' ) );

		$this->assertSame( 'pass', $result->status );
	}

	public function test_returns_warn_when_none_configured_but_starttls_available(): void {
		$probe  = $this->reachable( array( 'starttls_offered' => true, 'tls_negotiated' => false, 'cert' => null ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'none' ) );

		$this->assertSame( 'warn', $result->status );
	}

	// -------------------------------------------------------------------------
	// Encryption: tls
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_tls_configured_but_starttls_not_offered(): void {
		$probe  = $this->reachable( array( 'starttls_offered' => false, 'tls_negotiated' => false, 'cert' => null ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'fail', $result->status );
	}

	public function test_returns_fail_when_tls_negotiation_fails(): void {
		$probe  = $this->reachable( array( 'tls_negotiated' => false, 'cert' => null ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'fail', $result->status );
	}

	public function test_returns_warn_when_tls_negotiated_but_certificate_unavailable(): void {
		$probe  = $this->reachable( array( 'cert' => null ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'warn', $result->status );
	}

	// -------------------------------------------------------------------------
	// Certificate evidence
	// -------------------------------------------------------------------------

	public function test_returns_fail_for_expired_certificate(): void {
		$probe  = $this->reachable( array( 'cert' => $this->healthy_cert( array( 'expired' => true ) ) ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'fail', $result->status );
	}

	public function test_returns_warn_for_hostname_mismatch(): void {
		$probe  = $this->reachable( array( 'cert' => $this->healthy_cert( array( 'hostname_match' => false ) ) ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'warn', $result->status );
	}

	public function test_returns_warn_for_certificate_expiring_soon(): void {
		$probe  = $this->reachable( array( 'cert' => $this->healthy_cert( array( 'expires_in_days' => 3 ) ) ) );
		$result = $this->make_check( $probe )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'warn', $result->status );
	}

	public function test_returns_pass_for_healthy_certificate(): void {
		$result = $this->make_check( $this->reachable() )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertSame( 'pass', $result->status );
	}

	public function test_returns_pass_for_ssl_encryption_with_healthy_certificate(): void {
		$result = $this->make_check( $this->reachable() )->run( $this->make_context( encryption: 'ssl' ) );

		$this->assertSame( 'pass', $result->status );
	}

	// -------------------------------------------------------------------------
	// Safe evidence
	// -------------------------------------------------------------------------

	public function test_evidence_never_contains_credentials(): void {
		$context = $this->make_context(
			encryption: 'tls',
			extra: array(
				'username' => 'should-not-be-used',
				'password' => 'super-secret',
			)
		);

		$result = $this->make_check( $this->reachable() )->run( $context );

		$this->assertStringNotContainsString( 'super-secret', $result->evidence );
		$this->assertStringNotContainsString( 'should-not-be-used', $result->evidence );
		$this->assertStringNotContainsString( 'super-secret', $result->message );
		$this->assertStringNotContainsString( 'super-secret', wp_json_encode( $result->raw ) );
	}

	public function test_evidence_contains_certificate_details_on_pass(): void {
		$result = $this->make_check( $this->reachable() )->run( $this->make_context( encryption: 'tls' ) );

		$this->assertStringContainsString( 'smtp.example.com', $result->evidence );
		$this->assertStringContainsString( 'Example CA', $result->evidence );
	}
}
