<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\LogFilters;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Admin\Pages\LogsPage;
use Scalyn\MailRelay\Logging\TimelineRepository;
use Scalyn\MailRelay\Core\Capabilities;

final class OptionalMailMetadataTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array( 'scalyn_mail_relay_db_version' => '0.3.0' );
		$GLOBALS['wpdb'] = new WpdbStub();
		$_GET = array();
	}
	protected function tearDown(): void {
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_current_user_can'] = array();
		$_GET = array();
		unset( $GLOBALS['wpdb'] );
	}
	private function record(): void {
		(new MailLogRepository())->upsert(
			new MailMessage('00000000-0000-4000-8000-000000000000', 'sender@example.com', array('Person <to@example.com>', 'invalid', 'to@example.com'), str_repeat('S', 300), 'DO NOT STORE BODY', headers: array('Bcc: hidden@example.com')),
			new SendResult(true, 'smtp'), 'accepted'
		);
	}
	public function test_capture_requires_explicit_boolean_consent_and_schema(): void {
		$this->assertFalse((new SettingsRepository())->get_log_message_metadata());
		$this->record();
		$this->assertArrayNotHasKey('logged_subject', $GLOBALS['wpdb']->inserts[0]['data']);
		(new SettingsRepository())->save(array('advanced'=>array('log_message_metadata'=>true)));
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.2.0';
		$this->record();
		$this->assertArrayNotHasKey('logged_subject', $GLOBALS['wpdb']->inserts[1]['data']);
	}
	public function test_opt_in_captures_bounded_metadata_only_once(): void {
		(new SettingsRepository())->save(array('advanced'=>array('log_message_metadata'=>true)));
		$this->record();
		$data=$GLOBALS['wpdb']->inserts[0]['data'];
		$this->assertSame('to@example.com', $data['logged_recipients']);
		$this->assertSame(str_repeat('S',255), $data['logged_subject']);
		$this->assertStringNotContainsString('hidden@example.com', json_encode($data));
		$this->assertStringNotContainsString('DO NOT STORE BODY', json_encode($data));
		$GLOBALS['wpdb']->get_var_return=1;
		$this->record();
		$this->assertArrayNotHasKey('logged_subject', $GLOBALS['wpdb']->updates[0]['data']);
		(new SettingsRepository())->save(array('advanced'=>array('log_message_metadata'=>false)));
		$GLOBALS['wpdb']->get_var_return=null;
		$this->record();
		$this->assertArrayNotHasKey('logged_subject', $GLOBALS['wpdb']->inserts[1]['data']);
	}
	public function test_invalid_consent_is_rejected(): void {
		$this->expectException(InvalidArgumentException::class);
		(new SettingsRepository())->save(array('advanced'=>array('log_message_metadata'=>'yes')));
	}
	public function test_search_prepares_combined_filters_and_bounds(): void {
		(new MailLogRepository())->search(array('start'=>'2026-09-01','end'=>'2026-09-30','provider'=>'smtp','source'=>'plugin','recipient'=>'person@example.com','search'=>'invoice'), 'failed', 999, -10);
		$call=end($GLOBALS['wpdb']->prepare_calls);
		$this->assertStringContainsString('ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', $call['query']);
		$this->assertContains('2026-09-01 00:00:00', $call['args']);
		$this->assertContains('2026-09-30 23:59:59', $call['args']);
		$this->assertContains('%person@example.com%', $call['args']);
		$this->assertSame(array(250,0),array_slice($call['args'],-2));
	}
	public function test_impossible_date_rejected(): void {
		$this->expectException(InvalidArgumentException::class);
		LogFilters::validate(array('start'=>'2026-02-30'));
	}
	public function test_like_metacharacters_are_literal_and_not_sql(): void {
		(new MailLogRepository())->search(array('source'=>"a%_\\' OR 1=1"));
		$call=end($GLOBALS['wpdb']->prepare_calls);
		$this->assertStringNotContainsString('OR 1=1', $call['query']);
		$this->assertContains("%a\\%\\_\\\\' OR 1=1%", $call['args']);
	}
	public function test_metadata_search_before_upgrade_is_safe(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version']='0.2.0';
		$this->expectException(RuntimeException::class);
		(new MailLogRepository())->search(array('search'=>'hello'));
	}
	public function test_reversed_dates_rejected(): void {
		$this->expectException(InvalidArgumentException::class);
		LogFilters::validate(array('start'=>'2026-09-30','end'=>'2026-09-01'));
	}
	public function test_array_and_oversized_inputs_rejected(): void {
		foreach(array(array('source'=>array()),array('search'=>str_repeat('x',256))) as $input){
			try {LogFilters::validate($input);$this->fail('Expected validation failure');} catch(InvalidArgumentException $error){$this->assertSame('Invalid log filter.',$error->getMessage());}
		}
	}
	public function test_view_escapes_opt_in_fields_and_retains_pagination_filters(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::VIEW_LOGS]=true;
		$GLOBALS['wpdb']->get_results_return=array_fill(0,26,array('logged_subject'=>'<script>private</script>','logged_recipients'=>'"<unsafe>','status'=>'accepted'));
		$_GET=array('provider'=>'smtp','source'=>'plugin');
		ob_start();
		(new LogsPage(new MailLogRepository(),new TimelineRepository()))->render();
		$html=ob_get_clean();
		$this->assertStringNotContainsString('<script>private</script>',$html);
		$this->assertStringContainsString('&lt;script&gt;private&lt;/script&gt;',$html);
		$this->assertStringContainsString('provider=smtp',$html);
		$this->assertStringContainsString('source=plugin',$html);
		$this->assertStringContainsString('paged=2',$html);
	}
	public function test_invalid_range_makes_no_repository_query(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::VIEW_LOGS]=true;
		$_GET=array('start'=>'2026-02-30');
		ob_start();
		(new LogsPage(new MailLogRepository(),new TimelineRepository()))->render();
		$html=ob_get_clean();
		$this->assertStringContainsString('Unable to apply these filters',$html);
		$this->assertSame(array(),$GLOBALS['wpdb']->prepare_calls);
	}
}
