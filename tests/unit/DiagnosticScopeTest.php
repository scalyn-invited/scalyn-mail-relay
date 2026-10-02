<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Diagnostics\CurrentDiagnostics;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;

final class DiagnosticScopeTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_wp_actions'] = [];
		$GLOBALS['_test_wp_added_actions'] = [];
		$GLOBALS['wpdb'] = new WpdbStub();
	}

	public function test_switching_back_never_revives_the_old_revision(): void {
		$s = new SettingsRepository();
		$s->save(['provider'=>['active'=>'smtp']]);
		$first = $s->get_diagnostic_revision();
		$s->save(['provider'=>['active'=>'postmark']]);
		$second = $s->get_diagnostic_revision();
		$s->save(['provider'=>['active'=>'smtp']]);
		$this->assertCount(3, array_unique([$first,$second,$s->get_diagnostic_revision()]));
		$this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/D', $first);
	}

	public function test_smtp_and_selector_changes_rotate_but_policy_and_verification_do_not(): void {
		$s = new SettingsRepository();
		$s->save(['provider'=>['active'=>'smtp']]);
		$previous = $s->get_diagnostic_revision();
		foreach (['host'=>'smtp.test.example','port'=>465,'encryption'=>'ssl','username'=>'synthetic-user','password'=>'synthetic-password','from_email'=>'new@example.org'] as $key=>$value) {
			$s->save(['smtp'=>[$key=>$value]]);
			$this->assertNotSame($previous,$s->get_diagnostic_revision(),$key);
			$previous=$s->get_diagnostic_revision();
		}
		$s->save(['advanced'=>['dkim_selector'=>'selector1']]);
		$this->assertNotSame($previous,$s->get_diagnostic_revision());
		$previous=$s->get_diagnostic_revision();
		$s->save(['advanced'=>['log_retention_days'=>90,'diagnostic_schedule'=>'hourly']]);
		$s->mark_provider_verified();
		$s->mark_test_email_accepted();
		$s->save([]);
		$this->assertSame($previous,$s->get_diagnostic_revision());
	}

	public function test_each_api_credential_and_sender_change_rotates_only_when_active(): void {
		$cipher = new CredentialCipher(base64_encode(str_repeat('s',32)));
		foreach (['postmark','sendgrid'] as $provider) {
			$s = new SettingsRepository();
			$s->save(['provider'=>['active'=>$provider]]);
			$method='save_'.$provider;
			$input=['key_action'=>'replace','api_key'=>'synthetic-test-token-0123456789','from_email'=>'sender@example.org','from_name'=>'Sender'];
			$revision=$s->get_diagnostic_revision();
			$this->assertTrue($s->$method($input,$cipher));
			$this->assertNotSame($revision,$s->get_diagnostic_revision());
			$revision=$s->get_diagnostic_revision();
			$input['key_action']='keep';
			unset($input['api_key']);
			$s->$method($input,$cipher);
			$this->assertSame($revision,$s->get_diagnostic_revision());
			$input['from_email']='changed@example.net';
			$s->$method($input,$cipher);
			$this->assertNotSame($revision,$s->get_diagnostic_revision());
			$s->save(['provider'=>['active'=>'smtp']]);
			$revision=$s->get_diagnostic_revision();
			$input['from_name']='Changed';
			$s->$method($input,$cipher);
			$this->assertSame($revision,$s->get_diagnostic_revision());
		}
	}

	public function test_failed_save_preserves_revision_and_initialization_failure_is_explicit(): void {
		$s = new SettingsRepository();
		$GLOBALS['_test_wp_option_write_failures'][SettingsRepository::OPTION_KEY]=true;
		$this->assertFalse($s->save(['provider'=>['active'=>'postmark']]));
		$this->assertSame('',$s->get_diagnostic_revision());
		$this->expectException(RuntimeException::class);
		$s->ensure_diagnostic_revision();
	}

	public function test_api_context_uses_active_sender_and_never_saved_smtp_endpoint_or_secrets(): void {
		foreach (['postmark','sendgrid'] as $provider) {
			$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=[
				'provider'=>['active'=>$provider],
				'smtp'=>['from_email'=>'old@old.example','host'=>'old.smtp.example','username'=>'private-user','password'=>'private-password'],
				$provider=>['from_email'=>'current@new.example','key_cipher'=>'private-cipher'],
				'advanced'=>['dkim_selector'=>'selector1'],
			];
			$context=(new DiagnosticContextBuilder())->build(new SettingsRepository(),'site.example');
			$this->assertSame('new.example',$context->domain);
			$this->assertSame(['dkim_selector'=>'selector1'],$context->settings);
		}
	}

	private function settings(): SettingsRepository {
		$s=new SettingsRepository();
		$s->save(['provider'=>['active'=>'smtp'],'smtp'=>['from_email'=>'sender@example.org']]);
		return $s;
	}
	private function snapshot(SettingsRepository $s): array {
		return (new CurrentDiagnostics(new DiagnosticRepository(),new HealthScoreRepository(),$s,new DiagnosticContextBuilder()))->snapshot();
	}
	private function row(SettingsRepository $s): array {
		return ['configuration_id'=>$s->get_diagnostic_revision(),'provider_id'=>'smtp','sending_domain'=>'example.org','diagnostic_uuid'=>'run-1','status'=>'warn'];
	}

	public function test_legacy_and_wrong_configuration_provider_or_domain_cannot_leak_into_current_view(): void {
		$s=$this->settings();
		foreach ([[],['configuration_id'=>'old'],['provider_id'=>'postmark'],['sending_domain'=>'other.example']] as $override) {
			$GLOBALS['wpdb']->get_results_return=[$override ? array_replace($this->row($s),$override) : ['diagnostic_uuid'=>'legacy']];
			$GLOBALS['wpdb']->get_row_return=['score_uuid'=>'run-1','overall_score'=>100];
			$snapshot=$this->snapshot($s);
			$this->assertSame([],$snapshot['results']);
			$this->assertNull($snapshot['health']);
		}
	}

	public function test_current_score_must_match_current_run_and_never_fall_back_to_previous_score(): void {
		$s=$this->settings();
		$GLOBALS['wpdb']->get_results_return=[$this->row($s)];
		$GLOBALS['wpdb']->get_row_return=['score_uuid'=>'previous','overall_score'=>100];
		$this->assertNull($this->snapshot($s)['health']);
		$GLOBALS['wpdb']->get_row_return=['score_uuid'=>'run-1','overall_score'=>75];
		$this->assertSame(75,$this->snapshot($s)['health']['overall_score']);
		$queries=$GLOBALS['wpdb']->prepare_calls;
		$this->assertStringContainsString('configuration_id = %s',$queries[0]['query']);
		$this->assertStringContainsString('LIMIT 21',$queries[0]['query']);
		$this->assertContains($s->get_diagnostic_revision(),$queries[0]['args']);
	}

	public function test_oversized_runs_are_not_presented_as_complete(): void {
		$s=$this->settings();
		$GLOBALS['wpdb']->get_results_return=array_fill(0,21,$this->row($s));
		$this->assertSame([],$this->snapshot($s)['results']);
	}
}
