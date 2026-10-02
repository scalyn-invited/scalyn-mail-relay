<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\ProvidersPage;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;

final class ProvidersPageTest extends TestCase {
	protected function setUp(): void {
		(new ReflectionProperty(Plugin::class, 'instance'))->setValue(null, null);
		$GLOBALS['wpdb'] = new WpdbStub();
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_current_user_can'] = array(Capabilities::MANAGE_MAIL => true);
		$GLOBALS['_test_wp_actions'] = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
	protected function tearDown(): void {
		(new ReflectionProperty(Plugin::class, 'instance'))->setValue(null, null);
		$_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}
	private function render_page(): string {
		Plugin::instance()->boot();
		foreach (array('smtp'=>'SMTP', 'sendgrid'=>'SendGrid API', 'postmark'=>'Postmark API') as $id=>$label) {
			$provider = $this->createMock(\Scalyn\MailRelay\Contracts\ProviderInterface::class);
			$provider->method('get_id')->willReturn($id);
			$provider->method('get_label')->willReturn($label);
			$provider->expects($this->never())->method('test_connection');
			Plugin::instance()->container()->get(\Scalyn\MailRelay\Core\ProviderRegistry::class)->register($provider);
		}
		ob_start();
		try { (new ProvidersPage())->render(); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	public function test_unconfigured_route_and_restricted_wizard_actions(): void {
		$html = $this->render_page();
		$this->assertStringContainsString('No registered provider selected', $html);
		$this->assertStringContainsString('Not configured', $html);
		$this->assertStringNotContainsString('Change sending provider', $html);
		$this->assertStringNotContainsString('Configure in wizard', $html);
		$this->assertStringNotContainsString('Edit SendGrid settings', $html);
		$this->assertStringNotContainsString('<form', $html);
		$this->assertStringContainsString('Postmark API', $html);
		$this->assertStringContainsString('Live Server token', $html);
	}
	public function test_active_route_uses_recorded_verification_without_credentials(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = array(
			'provider' => array('active'=>'smtp','verified'=>true,'verified_at'=>'2026-09-30T08:00:00+00:00'),
			'smtp' => array('host'=>'smtp.example.test','from_email'=>'sender@example.test','password'=>'NEVER_RENDER_THIS'),
		);
		$html = $this->render_page();
		$this->assertStringContainsString('Active sending route', $html);
		$this->assertStringContainsString('Success recorded', $html);
		$this->assertStringContainsString('Verify connection', $html);
		$this->assertStringContainsString('Settings saved', $html);
		$this->assertStringContainsString('not live availability', $html);
		$this->assertStringNotContainsString('NEVER_RENDER_THIS', $html);
		$this->assertStringContainsString('wizard&step=3">Configure in wizard', $html);
		$this->assertStringContainsString('wizard&step=2">Select in wizard', $html);
	}
	public function test_unverified_route_does_not_show_success_date(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = array('provider'=>array('active'=>'smtp','verified'=>false,'verified_at'=>'2099-01-01T00:00:00+00:00'));
		$html = $this->render_page();
		$this->assertStringNotContainsString('Last success (site time)', $html);
		$this->assertStringNotContainsString('2099-01-01', $html);
		$this->assertStringContainsString('Not verified for the current selection', $html);
	}
	public function test_providers_page_no_longer_processes_credential_posts(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array('scalyn_sendgrid_settings'=>'1','key_action'=>'remove','confirm_remove'=>'1');
		$before = $GLOBALS['_test_wp_options'];
		$html = $this->render_page();
		$this->assertSame($before, $GLOBALS['_test_wp_options']);
		$this->assertStringNotContainsString('name="api_key"', $html);
	}
}
