<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Diagnostics\Checks\ReverseDnsCheck;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

/** Offline tests: forward and reverse resolvers are injected maps. */
final class ReverseDnsCheckTest extends TestCase {

	private function check( array $forward, array $reverse, array &$calls = array() ): ReverseDnsCheck {
		return new ReverseDnsCheck(
			static function ( string $host ) use ( $forward, &$calls ): array|false {
				$calls[] = 'A ' . $host;
				return array_key_exists( $host, $forward ) ? $forward[ $host ] : array();
			},
			static function ( string $ip ) use ( $reverse, &$calls ): array|false {
				$calls[] = 'PTR ' . $ip;
				return array_key_exists( $ip, $reverse ) ? $reverse[ $ip ] : array();
			}
		);
	}

	private function smtp( string $host ): DiagnosticContext {
		return new DiagnosticContext( 'example.com', array( 'host' => $host, 'port' => 587, 'encryption' => 'tls' ) );
	}

	public function test_identity_and_unscored_category(): void {
		$check = $this->check( array(), array() );
		$this->assertSame( 'reverse_dns', $check->get_id() );
		$this->assertSame( 'infrastructure', $check->get_category() );
	}

	public function test_api_provider_without_host_is_not_assessed_and_does_no_lookups(): void {
		$calls  = array();
		$result = $this->check( array(), array(), $calls )->run( new DiagnosticContext( 'example.com' ) );
		$this->assertSame( 'unknown', $result->status );
		$this->assertStringContainsString( 'API providers operate their own outbound servers', $result->message );
		$this->assertSame( array(), $calls );
	}

	public function test_forward_confirmed_addresses_pass_with_submission_scope(): void {
		$result = $this->check(
			array( 'smtp.example.com' => array( '198.51.100.10', '2606:4700:4700::1111' ), 'mail.example.com' => array( '198.51.100.10' ), 'mail6.example.com' => array( '2606:4700:4700:0000:0000:0000:0000:1111' ) ),
			array( '198.51.100.10' => array( 'mail.example.com.' ), '2606:4700:4700::1111' => array( 'mail6.example.com' ) )
		)->run( $this->smtp( 'smtp.example.com' ) );
		$this->assertSame( 'pass', $result->status, 'Equivalent IPv6 spellings match' );
		$this->assertStringContainsString( 'All 2 checked addresses', $result->message );
		$this->assertStringContainsString( 'submission server', $result->impact );
		$this->assertStringContainsString( '198.51.100.10 → mail.example.com (forward-confirmed)', $result->evidence );
	}

	public function test_missing_or_unconfirmed_ptr_warns(): void {
		$result = $this->check(
			array( 'smtp.example.com' => array( '198.51.100.10', '198.51.100.11' ), 'host.isp.example' => array( '203.0.113.5' ) ),
			array( '198.51.100.11' => array( 'host.isp.example' ) )
		)->run( $this->smtp( 'smtp.example.com' ) );
		$this->assertSame( 'warn', $result->status );
		$this->assertStringContainsString( '2 of 2 checked addresses for "smtp.example.com" lack', $result->message );
		$this->assertStringContainsString( 'no PTR record', $result->evidence );
		$this->assertStringContainsString( 'does not resolve back', $result->evidence );
		$this->assertSame( array( 'missing', 'unconfirmed' ), array_column( $result->raw['addresses'], 'state' ) );
	}

	public function test_private_relay_resolution_failure_and_lookup_failure_are_unknown(): void {
		$this->assertSame( 'unknown', $this->check( array( 'relay.local' => array( '10.0.0.5', '127.0.0.1' ) ), array() )->run( $this->smtp( 'relay.local' ) )->status );
		$this->assertStringContainsString( 'private or local relay', $this->check( array(), array() )->run( $this->smtp( '192.168.1.20' ) )->message );
		$this->assertStringContainsString( 'private or local relay', $this->check( array(), array() )->run( $this->smtp( 'localhost' ) )->message );
		$failing = new ReverseDnsCheck( static fn() => false, static fn() => false );
		$this->assertSame( 'unknown', $failing->run( $this->smtp( 'smtp.example.com' ) )->status );
		$ptr_fail = new ReverseDnsCheck( static fn() => array( '198.51.100.10' ), static fn() => false );
		$result   = $ptr_fail->run( $this->smtp( 'smtp.example.com' ) );
		$this->assertSame( 'unknown', $result->status );
		$this->assertStringContainsString( 'lookup failed', $result->evidence );
	}

	public function test_invalid_host_is_unknown_without_lookups(): void {
		$calls = array();
		$this->assertSame( 'unknown', $this->check( array(), array(), $calls )->run( $this->smtp( 'bad host;rm' ) )->status );
		$this->assertSame( array(), $calls );
	}

	public function test_forward_lookup_failure_is_unknown_not_a_configuration_warning(): void {
		$result = $this->check(
			array( 'mail.example.com' => false ),
			array( '8.8.8.8' => array( 'mail.example.com' ) )
		)->run( $this->smtp( '8.8.8.8' ) );
		$this->assertSame( 'unknown', $result->status );
		$this->assertSame( 'unknown', $result->raw['addresses'][0]['state'] );
		$this->assertStringContainsString( 'forward lookup failed', $result->evidence );
		$this->assertStringNotContainsString( 'does not resolve back', $result->evidence );
	}

	public function test_one_failed_ptr_target_does_not_hide_a_confirmed_alternative(): void {
		$result = $this->check(
			array( 'first.example.com' => false, 'second.example.com' => array( '8.8.8.8' ) ),
			array( '8.8.8.8' => array( 'first.example.com', 'second.example.com' ) )
		)->run( $this->smtp( '8.8.8.8' ) );
		$this->assertSame( 'pass', $result->status );
	}

	public function test_incomplete_alternative_does_not_prove_a_mismatch(): void {
		$result = $this->check(
			array( 'first.example.com' => array( '1.1.1.1' ), 'second.example.com' => false ),
			array( '8.8.8.8' => array( 'first.example.com', 'second.example.com' ) )
		)->run( $this->smtp( '8.8.8.8' ) );
		$this->assertSame( 'unknown', $result->status );
	}

	public function test_address_count_is_bounded(): void {
		$ips   = array_map( static fn( $i ) => '198.51.100.' . $i, range( 1, 10 ) );
		$calls = array();
		$this->check( array( 'smtp.example.com' => $ips ), array(), $calls )->run( $this->smtp( 'smtp.example.com' ) );
		$this->assertCount( 4, array_filter( $calls, static fn( $c ) => str_starts_with( $c, 'PTR ' ) ) );
	}
}
