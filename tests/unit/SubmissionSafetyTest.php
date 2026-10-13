<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Mail\SubmissionSafety;
use Scalyn\MailRelay\Database\SubmissionAttemptRepository;

final class SubmissionSafetyTest extends TestCase {
	public function test_legacy_retryable_flag_alone_never_authorizes_a_retry(): void {
		$this->assertFalse(SubmissionSafety::retryable(new SendResult(false,'smtp',retryable:true,failure_category:'connectivity')));
	}
	public function test_only_proven_transient_nonacceptance_permits_retry(): void {
		foreach (['connectivity','timeout','rate-limit'] as $category) {
			$this->assertTrue(SubmissionSafety::retryable(new SendResult(false,'smtp',retryable:true,failure_category:$category,safe_to_retry:true)));
		}
		foreach (['auth','config','provider-rejection','unknown','certificate','tls',null] as $category) {
			$this->assertFalse(SubmissionSafety::retryable(new SendResult(false,'smtp',retryable:true,failure_category:$category,safe_to_retry:true)));
		}
		$this->assertFalse(SubmissionSafety::retryable(new SendResult(true,'smtp',retryable:true,failure_category:'connectivity',safe_to_retry:true)));
		$this->assertFalse(SubmissionSafety::retryable(new SendResult(false,'smtp',retryable:true,failure_category:'timeout',acceptance_unconfirmed:true,safe_to_retry:true)));
	}
	public function test_delays_respect_the_longer_provider_wait(): void {
		$this->assertSame(60,SubmissionSafety::delay(new SendResult(false,'smtp'),1));
		$this->assertSame(300,SubmissionSafety::delay(new SendResult(false,'smtp'),2));
		$this->assertSame(800,SubmissionSafety::delay(new SendResult(false,'smtp',retry_after_seconds:800),1));
	}
	public function test_attempt_metadata_contains_no_content_and_finishes_by_compare_and_set(): void {
		$GLOBALS['wpdb']=new WpdbStub();
		$repo=new SubmissionAttemptRepository();
		$id='11111111-1111-4111-8111-111111111111';
		$repo->begin($id,$id,1,'postmark',null,'unknown/default');
		$this->assertSame('Prepared',$GLOBALS['wpdb']->inserts[0]['data']['status']);
		$this->assertArrayNotHasKey('body',$GLOBALS['wpdb']->inserts[0]['data']);
		$repo->finish($id,new SendResult(false,'postmark',response_message:'must never be stored',acceptance_unconfirmed:true));
		$this->assertStringNotContainsString('must never be stored',wp_json_encode($GLOBALS['wpdb']->updates));
		$this->assertSame('Prepared',$GLOBALS['wpdb']->updates[0]['where']['status']);
		$this->assertNull($GLOBALS['wpdb']->updates[0]['where']['finished_at']);
	}
	public function test_invalid_attempt_numbers_fail_before_persistence(): void {
		$this->expectException(RuntimeException::class);
		(new SubmissionAttemptRepository())->begin('11111111-1111-4111-8111-111111111111','11111111-1111-4111-8111-111111111111',4,'postmark',null,'unknown/default');
	}
}
