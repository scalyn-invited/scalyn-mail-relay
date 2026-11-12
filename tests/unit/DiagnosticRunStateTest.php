<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\DiagnosticRunStateRepository;

final class DiagnosticRunStateTest extends TestCase {
	private const FIRST = '72000000-0000-4000-8000-000000000001';
	private const SECOND = '72000000-0000-4000-8000-000000000002';
	private DiagnosticRunStateRepository $state;
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$this->state = new DiagnosticRunStateRepository();
	}
	protected function tearDown(): void { $GLOBALS['_test_wp_option_write_failures'] = array(); }

	public function test_start_and_completion_have_utc_times_and_preserve_scheduled_freshness(): void {
		$before = time();
		$this->assertTrue($this->state->begin(self::FIRST,'scheduled'));
		$running = $this->state->get()['latest'];
		$this->assertSame('running',$running['state']);
		$this->assertGreaterThanOrEqual($before,$running['started_at']);
		$this->assertSame(0,$running['finished_at']);
		$this->assertTrue($this->state->finish(self::FIRST));
		$scheduled = $this->state->get()['scheduled'];
		$this->assertGreaterThanOrEqual($scheduled['started_at'],$scheduled['finished_at']);
		$this->assertSame($scheduled,$this->state->get()['last_scheduled_success']);
		$this->state->begin(self::SECOND,'manual');
		$this->state->finish(self::SECOND,'publication_failed');
		$after = $this->state->get();
		$this->assertSame($scheduled,$after['scheduled']);
		$this->assertSame($scheduled,$after['last_success']);
		$this->assertSame('publication_failed',$after['latest']['failure_code']);
	}
	public function test_wrong_uuid_and_invalid_failure_cannot_finish_running_record(): void {
		$this->state->begin(self::FIRST,'rest');
		$before=$this->state->get();
		$this->assertFalse($this->state->finish(self::SECOND));
		$this->assertFalse($this->state->finish(self::FIRST,'secret exception'));
		$this->assertSame($before,$this->state->get());
	}
	public function test_unfinished_predecessor_is_preserved_without_inventing_failure(): void {
		$this->state->begin(self::FIRST,'scheduled');
		$this->state->begin(self::SECOND,'manual');
		$previous=$this->state->get()['previous_unfinished'];
		$this->assertSame(self::FIRST,$previous['uuid']);
		$this->assertSame('running',$previous['state']);
		$this->assertSame(0,$previous['finished_at']);
	}
	public function test_write_failures_do_not_invent_a_start_or_completion(): void {
		$GLOBALS['_test_wp_option_write_failures'][DiagnosticRunStateRepository::OPTION_KEY]=true;
		$this->assertFalse($this->state->begin(self::FIRST,'manual'));
		$this->assertNull($this->state->get()['latest']);
		$GLOBALS['_test_wp_option_write_failures']=array();
		$this->state->begin(self::FIRST,'manual');
		$GLOBALS['_test_wp_option_write_failures'][DiagnosticRunStateRepository::OPTION_KEY]=true;
		$this->assertFalse($this->state->finish(self::FIRST));
		$this->assertSame('running',$this->state->get()['latest']['state']);
	}
	public function test_read_projection_removes_payloads_and_rejects_invalid_records(): void {
		$this->state->begin(self::FIRST,'rest');
		$stored=$GLOBALS['_test_wp_options'][DiagnosticRunStateRepository::OPTION_KEY];
		$stored['latest']['credentials']='secret-value';
		$stored['payload']='secret-value';
		$stored['last_success']=$stored['latest'];
		$stored['scheduled']=$stored['latest'];
		$GLOBALS['_test_wp_options'][DiagnosticRunStateRepository::OPTION_KEY]=$stored;
		$this->assertStringNotContainsString('secret',json_encode($this->state->get()));
		$this->assertNull($this->state->get()['last_success']);
		$this->assertNull($this->state->get()['scheduled']);
		$stored['latest']['failure_code']='secret-value';
		$GLOBALS['_test_wp_options'][DiagnosticRunStateRepository::OPTION_KEY]=$stored;
		$this->assertNull($this->state->get()['latest']);
	}
}
