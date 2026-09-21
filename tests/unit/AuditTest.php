<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Audit\AuditRecorder;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\HookNames;

final class AuditTest extends TestCase {
	private WpdbStub $db;
	protected function setUp(): void {
		$GLOBALS['_test_current_user_id'] = 0;
		$GLOBALS['_test_doing_cron'] = false;
		$this->db = new WpdbStub();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$GLOBALS['_test_wp_actions'] = array();
		(new AuditRecorder(new AuditRepository()))->register();
	}
	protected function tearDown(): void {
		$GLOBALS['_test_current_user_id'] = 1;
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$GLOBALS['_test_wp_actions'] = array();
	}
	public function test_contract_rejects_unrecognized_data(): void {
		foreach (array(
			array('password=secret','changed'),
			array('test_email','delivered'),
			array('test_email','accepted','recipient@example.com'),
			array('settings_changed','changed','',array('password' => 'secret')),
			array('settings_changed','changed','',array(array('smtp.password'))),
		) as $args) {
			try { new AuditEvent(...$args); $this->fail('Invalid event accepted'); }
			catch (InvalidArgumentException $error) { $this->assertStringNotContainsString('secret', $error->getMessage()); }
		}
	}
	public function test_append_contains_only_allowlisted_columns(): void {
		$uuid = wp_generate_uuid4();
		do_action(HookNames::AUDIT_EVENT, new AuditEvent('test_email','accepted',$uuid));
		$row = $this->db->inserts[0]['data'];
		$this->assertSame('wp_scalyn_audit_logs', $this->db->inserts[0]['table']);
		$this->assertSame($uuid,$row['resource_id']);
		$this->assertSame('', $row['ip_address']);
		$this->assertSame('', $row['user_agent']);
		$this->assertSame(0, $row['user_id']);
		$this->assertSame(array('version'=>2,'source'=>'application','outcome'=>'accepted','changed_fields'=>array()), json_decode($row['metadata'],true));
	}
	public function test_settings_emit_changes_without_values_and_skip_noops(): void {
		$repo = new SettingsRepository();
		$repo->save(array('smtp'=>array('password'=>'private-password','username'=>'private-user','host'=>'private-host')));
		$this->assertCount(1,$this->db->inserts);
		$this->assertStringNotContainsString('private-',json_encode($this->db->inserts));
		$this->assertContains('smtp.password',json_decode($this->db->inserts[0]['data']['metadata'],true)['changed_fields']);
		$repo->save(array('smtp'=>array('password'=>'','username'=>'private-user','host'=>'private-host')));
		$this->assertCount(1,$this->db->inserts);
		$repo->save(array('advanced'=>array('log_retention_days'=>60,'delete_data_on_uninstall'=>true,'confirm_delete_data'=>true)));
		$this->assertSame(array('settings_changed','retention_changed','uninstall_policy_changed'),array_column(array_column($this->db->inserts,'data'),'action'));
	}
	public function test_invalid_settings_emit_nothing(): void {
		try { (new SettingsRepository())->save(array('advanced'=>array('log_retention_days'=>0))); }
		catch (InvalidArgumentException $error) { $this->assertCount(0,$this->db->inserts); }
	}
	public function test_repository_errors_are_safe(): void {
		$this->db->throw_on_insert = true;
		$this->expectExceptionMessage('Audit persistence failed.');
		(new AuditRepository())->append(new AuditEvent('test_email','failed'));
	}
	public function test_subscriber_failure_does_not_fail_settings_save(): void {
		$this->db->return_false_on_insert = true;
		$this->assertTrue((new SettingsRepository())->save(array('advanced'=>array('log_retention_days'=>90))));
		$this->assertSame(90,(new SettingsRepository())->get_log_retention_days());
	}
	public function test_failed_save_is_not_audited_and_retry_retains_the_change(): void {
		$repo = new SettingsRepository();
		$GLOBALS['_test_wp_option_write_failures'][SettingsRepository::OPTION_KEY] = true;
		$this->assertFalse($repo->save(array('advanced'=>array('log_retention_days'=>90))));
		$this->assertSame(30,$repo->get_log_retention_days());
		$this->assertCount(0,$this->db->inserts);
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$this->assertTrue($repo->save(array('advanced'=>array('log_retention_days'=>90))));
		$this->assertCount(1,$this->db->inserts);
		$this->assertSame('retention_changed',$this->db->inserts[0]['data']['action']);
	}
}
