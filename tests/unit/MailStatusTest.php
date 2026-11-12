<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Mail\MailStatus;

final class MailStatusTest extends TestCase {

	public function test_all_returns_non_empty_array(): void {
		$this->assertNotEmpty( MailStatus::all() );
	}

	public function test_all_constants_are_strings(): void {
		foreach ( MailStatus::all() as $status ) {
			$this->assertIsString( $status );
		}
	}

	public function test_all_constants_are_non_empty(): void {
		foreach ( MailStatus::all() as $status ) {
			$this->assertNotEmpty( $status );
		}
	}

	public function test_all_constants_are_unique(): void {
		$statuses = MailStatus::all();
		$this->assertSame( count( $statuses ), count( array_unique( $statuses ) ) );
	}

	public function test_expected_send_path_statuses_exist(): void {
		$statuses = MailStatus::all();

		$this->assertContains( 'generated', $statuses );
		$this->assertContains( 'prepared', $statuses );
		$this->assertContains( 'connected', $statuses );
		$this->assertContains( 'authenticated', $statuses );
		$this->assertContains( 'sent', $statuses );
		$this->assertContains( 'accepted', $statuses );
	}

	public function test_failure_statuses_exist(): void {
		$statuses = MailStatus::all();

		$this->assertContains( 'failed', $statuses );
		$this->assertContains( 'retried', $statuses );
	}

	public function test_delivered_is_not_in_send_path(): void {
		$this->assertNotContains( 'delivered', MailStatus::all() );
	}

	public function test_constants_match_all_array_values(): void {
		$this->assertSame( MailStatus::GENERATED, 'generated' );
		$this->assertSame( MailStatus::PREPARED, 'prepared' );
		$this->assertSame( MailStatus::CONNECTED, 'connected' );
		$this->assertSame( MailStatus::AUTHENTICATED, 'authenticated' );
		$this->assertSame( MailStatus::SENT, 'sent' );
		$this->assertSame( MailStatus::ACCEPTED, 'accepted' );
		$this->assertSame( MailStatus::FAILED, 'failed' );
		$this->assertSame( MailStatus::RETRIED, 'retried' );
	}
}
