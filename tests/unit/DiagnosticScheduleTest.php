<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\DiagnosticSchedule;
use Scalyn\MailRelay\Core\ScheduledHooks;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRunLock;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;

final class DiagnosticScheduleTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_schedule_failure'] = false;
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_cron'] = array();
		$GLOBALS['_test_wp_recurrence'] = array();
		$GLOBALS['wpdb'] = new WpdbStub();
		$GLOBALS['wpdb']->get_var_return = '1';
	}

	protected function tearDown(): void {
		$GLOBALS['_test_wp_schedule_failure'] = false;
	}

	public function test_schedule_failure_is_reported_and_retried(): void {
		(new SettingsRepository())->save(array('advanced'=>array('diagnostic_schedule'=>'daily')));
		$GLOBALS['_test_wp_schedule_failure'] = true;
		$this->assertFalse(DiagnosticSchedule::reconcile());
		$this->assertFalse(wp_next_scheduled(ScheduledHooks::DIAGNOSTICS));
		$GLOBALS['_test_wp_schedule_failure'] = false;
		$this->assertTrue(DiagnosticSchedule::reconcile());
	}

	public function test_disabled_default_has_no_schedule_or_execution(): void {
		$this->assertTrue(DiagnosticSchedule::reconcile());
		(new DiagnosticSchedule())->run();
		$this->assertSame(array(), $GLOBALS['_test_wp_cron']);
		$this->assertSame(array(), $GLOBALS['wpdb']->prepare_calls);
	}

	public function test_schedule_changes_are_idempotent_and_disable_clears(): void {
		foreach (array('hourly', 'twicedaily', 'daily') as $cadence) {
			(new SettingsRepository())->save(array('advanced'=>array('diagnostic_schedule'=>$cadence)));
			$this->assertTrue(DiagnosticSchedule::reconcile());
			$first = $GLOBALS['_test_wp_cron'];
			$this->assertTrue(DiagnosticSchedule::reconcile());
			$this->assertSame($first, $GLOBALS['_test_wp_cron']);
			$this->assertSame($cadence, wp_get_schedule(ScheduledHooks::DIAGNOSTICS));
			$this->assertCount(1, $first);
			$this->assertGreaterThan(time(), wp_next_scheduled(ScheduledHooks::DIAGNOSTICS));
		}
		(new SettingsRepository())->save(array('advanced'=>array('diagnostic_schedule'=>'disabled')));
		$this->assertTrue(DiagnosticSchedule::reconcile());
		$this->assertFalse(wp_next_scheduled(ScheduledHooks::DIAGNOSTICS));
	}

	public function test_invalid_stored_cadence_fails_closed_and_invalid_save_is_rejected(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = array('advanced'=>array('diagnostic_schedule'=>'every-second'));
		$this->assertSame('disabled', (new SettingsRepository())->get_diagnostic_schedule());
		$this->expectException(InvalidArgumentException::class);
		(new SettingsRepository())->save(array('advanced'=>array('diagnostic_schedule'=>array('daily'))));
	}

	public function test_lock_is_nonblocking_and_reentrant_acquisition_is_rejected(): void {
		$one = new DiagnosticRunLock();
		$two = new DiagnosticRunLock();
		$this->assertTrue($one->acquire());
		$this->assertFalse($two->acquire());
		$two->release();
		$one->release();
		$this->assertTrue($two->acquire());
		$two->release();
		$GLOBALS['wpdb']->get_var_return = '0';
		$this->assertFalse($one->acquire());
		$GLOBALS['wpdb']->get_var_return = null;
		$this->assertFalse($one->acquire());
	}

	public function test_run_rejects_excessive_check_count_before_execution(): void {
		$this->expectException(RuntimeException::class);
		(new DiagnosticRunner())->run(array_fill(0, 21, null), new DiagnosticContext('example.com'));
	}

	public function test_elapsed_budget_is_checked_without_sleeping(): void {
		$times = array(0.0, 20.0);
		$runner = new DiagnosticRunner(static function() use (&$times): float { return array_shift($times); });
		$this->expectException(RuntimeException::class);
		$runner->run(array(), new DiagnosticContext('example.com'));
	}

	public function test_lifecycle_clears_monitoring_without_removing_policy(): void {
		(new SettingsRepository())->save(array('advanced'=>array('diagnostic_schedule'=>'daily')));
		DiagnosticSchedule::reconcile();
		ScheduledHooks::clear();
		$this->assertFalse(wp_next_scheduled(ScheduledHooks::DIAGNOSTICS));
		$this->assertSame('daily',(new SettingsRepository())->get_diagnostic_schedule());
	}
}
