<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Mail\SendResult;

final class SendResultTest extends TestCase {
	public function test_normalized_result_keeps_failure_category(): void {
		$result = new SendResult( false, 'smtp', null, '535', 'Authentication failed', false, 'auth' );
		$this->assertFalse( $result->success );
		$this->assertSame( 'auth', $result->failure_category );
	}
}
