<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\ActivitySummaryPresenter;
use Scalyn\MailRelay\Logging\MailLogRepository;

final class ActivitySummaryPresenterTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_seven_day_window_uses_site_time_and_all_providers(): void {
		$db = new WpdbStub();
		$GLOBALS['wpdb'] = $db;
		$db->get_results_return = array( array( 'status' => 'accepted', 'row_count' => '5' ), array( 'status' => 'legacy', 'row_count' => '2' ) );
		$result = ActivitySummaryPresenter::load( new MailLogRepository(), '2026-09-23 09:30:00' );
		$this->assertTrue( $result['available'] );
		$this->assertSame( 5, $result['accepted'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 2, $result['other'] );
		$this->assertSame( 7, $result['total'] );
		$this->assertSame( array( 'wp_scalyn_mail_logs', '2026-09-16 09:30:00', '2026-09-23 09:30:00' ), $db->prepare_calls[0]['args'] );
	}

	public function test_invalid_clock_is_unavailable(): void {
		$this->assertSame( array( 'available' => false ), ActivitySummaryPresenter::load( new MailLogRepository(), 'invalid' ) );
	}

	public function test_database_error_is_unavailable_not_zero(): void {
		$GLOBALS['wpdb'] = new class() extends WpdbStub {
			public string $last_error = 'sensitive';
		};
		$this->assertSame( array( 'available' => false ), ActivitySummaryPresenter::load( new MailLogRepository(), '2026-09-23 09:30:00' ) );
	}
}
