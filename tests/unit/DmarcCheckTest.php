<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\Checks\DmarcCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/**
 * Unit tests for DmarcCheck.
 *
 * All DNS lookups are stubbed via constructor injection; no real network
 * calls are ever made. Covers valid/missing/multiple/malformed DMARC, policy
 * strength (none/quarantine/reject), DNS failure, and invalid domain input.
 */
final class DmarcCheckTest extends TestCase {

	private function make_check( array|false $txt_records ): DmarcCheck {
		return new DmarcCheck( static fn( string $domain ): array|false => $txt_records );
	}

	private function make_context( string $domain ): DiagnosticContext {
		return new DiagnosticContext( $domain );
	}

	private function txt( string $value ): array {
		return array( 'txt' => $value );
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_get_id_and_category(): void {
		$check = $this->make_check( array() );

		$this->assertSame( 'dmarc_policy', $check->get_id() );
		$this->assertSame( 'dns', $check->get_category() );
	}

	// -------------------------------------------------------------------------
	// Domain validation
	// -------------------------------------------------------------------------

	public function test_returns_unknown_for_empty_domain(): void {
		$result = $this->make_check( array() )->run( $this->make_context( '' ) );

		$this->assertSame( 'unknown', $result->status );
	}

	/** @dataProvider invalidDomainProvider */
	public function test_returns_unknown_for_invalid_domain( string $domain ): void {
		$result = $this->make_check( array() )->run( $this->make_context( $domain ) );

		$this->assertSame( 'unknown', $result->status );
	}

	public static function invalidDomainProvider(): array {
		return array(
			'ip literal'    => array( '192.168.1.1' ),
			'no tld'        => array( 'example' ),
			'wildcard'      => array( '*.example.com' ),
			'control chars' => array( "example.com\r\nInjected" ),
		);
	}

	public function test_does_not_perform_dns_lookup_for_invalid_domain(): void {
		$lookup_called = false;
		$check         = new DmarcCheck(
			static function ( string $domain ) use ( &$lookup_called ): array|false {
				$lookup_called = true;
				return array();
			}
		);

		$check->run( $this->make_context( 'not a domain' ) );

		$this->assertFalse( $lookup_called );
	}

	// -------------------------------------------------------------------------
	// DNS failure
	// -------------------------------------------------------------------------

	public function test_returns_unknown_when_dns_lookup_fails(): void {
		$result = $this->make_check( false )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'unknown', $result->status );
	}

	// -------------------------------------------------------------------------
	// Missing DMARC
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_no_txt_records_exist(): void {
		$result = $this->make_check( array() )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
	}

	public function test_returns_fail_when_txt_records_exist_but_none_are_dmarc(): void {
		$records = array( $this->txt( 'google-site-verification=abc123' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
	}

	// -------------------------------------------------------------------------
	// Multiple DMARC
	// -------------------------------------------------------------------------

	public function test_returns_fail_when_multiple_dmarc_records_exist(): void {
		$records = array(
			$this->txt( 'v=DMARC1; p=reject' ),
			$this->txt( 'v=DMARC1; p=none' ),
		);

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'fail', $result->status );
		$this->assertStringContainsString( 'Multiple DMARC', $result->message );
	}

	// -------------------------------------------------------------------------
	// Malformed DMARC
	// -------------------------------------------------------------------------

	public function test_returns_warn_when_policy_tag_is_missing(): void {
		$records = array( $this->txt( 'v=DMARC1; rua=mailto:reports@example.com' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	public function test_returns_warn_when_policy_value_is_unrecognized(): void {
		$records = array( $this->txt( 'v=DMARC1; p=drop' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	// -------------------------------------------------------------------------
	// Policy strength
	// -------------------------------------------------------------------------

	public function test_returns_warn_for_monitor_only_policy(): void {
		$records = array( $this->txt( 'v=DMARC1; p=none' ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'warn', $result->status );
	}

	/** @dataProvider enforcingPolicyProvider */
	public function test_returns_pass_for_enforcing_policy( string $policy ): void {
		$records = array( $this->txt( "v=DMARC1; p={$policy}" ) );

		$result = $this->make_check( $records )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( 'pass', $result->status );
	}

	public static function enforcingPolicyProvider(): array {
		return array(
			'quarantine' => array( 'quarantine' ),
			'reject'     => array( 'reject' ),
		);
	}

	// -------------------------------------------------------------------------
	// Safe evidence
	// -------------------------------------------------------------------------

	public function test_evidence_contains_only_the_dmarc_record_text(): void {
		$dmarc = 'v=DMARC1; p=reject; rua=mailto:reports@example.com';

		$result = $this->make_check( array( $this->txt( $dmarc ) ) )->run( $this->make_context( 'example.com' ) );

		$this->assertSame( $dmarc, $result->evidence );
	}

	// -------------------------------------------------------------------------
	// Deeper analysis: inheritance, enforcement tags and alignment assessment
	// -------------------------------------------------------------------------

	private function map_check( array $map ): DmarcCheck {
		return new DmarcCheck( static fn( string $name ): array|false => array_key_exists( $name, $map ) ? $map[ $name ] : array() );
	}

	public function test_subdomain_inherits_organizational_policy_using_sp(): void {
		$check  = $this->map_check( array( '_dmarc.example.com' => array( $this->txt( 'v=DMARC1; p=reject; sp=none; rua=mailto:r@example.com' ) ) ) );
		$result = $check->run( $this->make_context( 'mail.example.com' ) );
		$this->assertSame( 'warn', $result->status, 'sp=none applies to the subdomain' );
		$this->assertStringContainsString( 'inherited from "example.com"', $result->message );
		$this->assertTrue( $result->raw['inherited'] );
		$this->assertSame( 'example.com', $result->raw['policy_from'] );
		$this->assertStringContainsString( 'Inherited from: _dmarc.example.com', $result->evidence );
		$enforced = $this->map_check( array( '_dmarc.example.com' => array( $this->txt( 'v=DMARC1; p=quarantine; rua=mailto:r@example.com' ) ) ) )->run( $this->make_context( 'mail.example.com' ) );
		$this->assertSame( 'pass', $enforced->status );
		$this->assertSame( 'quarantine', $enforced->raw['policy'] );
	}

	public function test_exact_record_takes_precedence_and_parent_failure_is_unknown(): void {
		$check = new DmarcCheck( static fn( string $name ): array|false => '_dmarc.mail.example.com' === $name ? array() : false );
		$this->assertSame( 'unknown', $check->run( $this->make_context( 'mail.example.com' ) )->status );
		$exact = $this->map_check( array( '_dmarc.mail.example.com' => array( $this->txt( 'v=DMARC1; p=reject' ) ), '_dmarc.example.com' => array( $this->txt( 'v=DMARC1; p=none' ) ) ) )->run( $this->make_context( 'mail.example.com' ) );
		$this->assertSame( 'pass', $exact->status );
		$this->assertFalse( $exact->raw['inherited'] );
	}

	/** @dataProvider limitedPolicyProvider */
	public function test_enforcing_policy_with_limitations_warns( string $record, string $text ): void {
		$result = $this->make_check( array( $this->txt( $record ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( 'warn', $result->status );
		$this->assertStringContainsString( $text, $result->message );
	}

	public static function limitedPolicyProvider(): array {
		return array(
			'partial pct'  => array( 'v=DMARC1; p=reject; pct=25', 'only 25%' ),
			'invalid pct'  => array( 'v=DMARC1; p=reject; pct=150', 'pct= value is invalid' ),
			'invalid mode' => array( 'v=DMARC1; p=quarantine; adkim=x', 'adkim=' ),
			'invalid sp'   => array( 'v=DMARC1; p=reject; sp=block', 'sp= value' ),
		);
	}

	public function test_alignment_is_a_configuration_assessment_never_a_claim(): void {
		$record = 'v=DMARC1; p=reject; adkim=s';
		$none   = $this->make_check( array( $this->txt( $record ) ) )->run( $this->make_context( 'example.com' ) );
		$this->assertSame( array( 'dkim_mode' => 'strict', 'spf_mode' => 'relaxed', 'dkim' => 'not_assessed', 'spf' => 'not_assessed', 'reporting' => false, 'subdomain' => false ), $none->raw['alignment'] );
		$this->assertStringContainsString( 'rua=', $none->recommended_action );
		$with = $this->make_check( array( $this->txt( $record ) ) )->run( new DiagnosticContext( 'example.com', array( 'dkim_selector' => 'pm' ) ) );
		$this->assertSame( 'possible_with_configured_selector', $with->raw['alignment']['dkim'] );
		$this->assertStringContainsString( 'confirm with message headers', $with->recommended_action );
		$this->assertStringContainsString( 'have not been verified', $with->message );
	}
}
