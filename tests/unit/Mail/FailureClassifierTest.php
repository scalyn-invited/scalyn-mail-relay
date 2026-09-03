<?php
/**
 * Tests for FailureClassifier.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Mail\FailureClassifier;
use Scalyn\MailRelay\Mail\RemediationSuggestion;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\TransportFailureCategory;

/**
 * Unit tests for FailureClassifier.
 *
 * @coversDefaultClass \Scalyn\MailRelay\Mail\FailureClassifier
 */
class FailureClassifierTest extends TestCase {

	/**
	 * The FailureClassifier instance under test.
	 *
	 * @var FailureClassifier
	 */
	private FailureClassifier $classifier;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		$this->classifier = new FailureClassifier();
	}

	/**
	 * Tests auth failure classification by SMTP code 535.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_auth_failure_by_code_535(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '535',
			response_message: 'Authentication failed'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertInstanceOf( RemediationSuggestion::class, $suggestion );
		$this->assertEqual( TransportFailureCategory::AUTH, $suggestion->category );
		$this->assertStringContainsString( 'credentials', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_connectivity_failure_by_code_502(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '502',
			response_message: 'Service unavailable'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::CONNECTIVITY, $suggestion->category );
		$this->assertStringContainsString( 'reach the mail server', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_timeout_by_code_421(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '421',
			response_message: 'Service not available'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::TIMEOUT, $suggestion->category );
		$this->assertStringContainsString( 'timed out', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_timeout_by_code_451(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '451',
			response_message: 'Try again later'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::TIMEOUT, $suggestion->category );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_tls_failure_by_message(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: null,
			response_message: 'TLS negotiation failed'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::TLS, $suggestion->category );
		$this->assertStringContainsString( 'tls encryption', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_certificate_failure_by_message(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: null,
			response_message: 'Certificate verification failed'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::CERTIFICATE, $suggestion->category );
		$this->assertStringContainsString( 'certificate', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_provider_rejection_by_code_550(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '550',
			response_message: 'User unknown'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::PROVIDER_REJECTION, $suggestion->category );
		$this->assertStringContainsString( 'rejected', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_provider_rejection_by_codes_551_554(): void {
		foreach ( array( '551', '554' ) as $code ) {
			$result = new SendResult(
				success: false,
				provider: 'smtp',
				response_code: $code,
				response_message: 'Message rejected'
			);

			$suggestion = $this->classifier->classify( $result );

			$this->assertEqual( TransportFailureCategory::PROVIDER_REJECTION, $suggestion->category, "Code $code should classify as provider rejection" );
		}
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_unknown_when_no_evidence(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: null,
			response_message: null
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::UNKNOWN, $suggestion->category );
	}

	/**
	 * Tests that explicit failure category is respected.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function respects_explicit_failure_category(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '999',
			response_message: 'Custom error',
			failure_category: TransportFailureCategory::AUTH
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::AUTH, $suggestion->category );
	}

	/**
	 * Tests that evidence is included in suggestion.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function includes_evidence_in_suggestion(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '535',
			response_message: 'Authentication failed'
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertNotNull( $suggestion->evidence );
		$this->assertStringContainsString( '535', $suggestion->evidence );
		$this->assertStringContainsString( 'Authentication failed', $suggestion->evidence );
	}

	/**
	 * Tests that long response messages are truncated in evidence.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function truncates_long_response_messages_in_evidence(): void {
		$long_message = str_repeat( 'x', 150 );
		$result       = new SendResult(
			success: false,
			provider: 'smtp',
			response_code: '535',
			response_message: $long_message
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertNotNull( $suggestion->evidence );
		$this->assertStringContainsString( '...', $suggestion->evidence );
		// Ensure evidence doesn't contain the full long message.
		$this->assertStringNotContainsString( $long_message, $suggestion->evidence );
	}

	/**
	 * Tests failure classification.
	 *
	 * @test
	 * @covers ::classify
	 */
	public function classifies_config_category(): void {
		$result = new SendResult(
			success: false,
			provider: 'smtp',
			failure_category: TransportFailureCategory::CONFIG,
			response_code: null,
			response_message: null
		);

		$suggestion = $this->classifier->classify( $result );

		$this->assertEqual( TransportFailureCategory::CONFIG, $suggestion->category );
		$this->assertStringContainsString( 'configuration', strtolower( $suggestion->suggestion ) );
	}

	/**
	 * Helper to assert two values are equal.
	 *
	 * @param mixed  $expected The expected value.
	 * @param mixed  $actual   The actual value.
	 * @param string $message  Optional message.
	 */
	private function assertEqual( $expected, $actual, $message = '' ): void {
		$this->assertEquals( $expected, $actual, $message );
	}
}
