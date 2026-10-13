<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\ProvidersPage;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;

final class ProvidersPageTest extends TestCase {
	public function test_catalog_preserves_full_cards_and_has_local_marks_and_accessible_dialog(): void {
		$html = $this->render_page();
		$dom = new DOMDocument();
		@$dom->loadHTML( $html );
		$xpath = new DOMXPath( $dom );
		$this->assertSame( 5, $xpath->query( '//button[@data-provider-index and @aria-haspopup="dialog"]' )->length );
		$this->assertSame( 5, $xpath->query( '//section[@class="scalyn-card scalyn-provider-card"]' )->length );
		$this->assertSame( 10, $xpath->query( '//img[@class="scalyn-provider-logo" and @alt=""]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@class="scalyn-provider-catalog" and @hidden]' )->length );
		$this->assertSame( 1, $xpath->query( '//dialog[@aria-labelledby="scalyn-provider-details-title"]' )->length );
		$this->assertStringContainsString( 'Viewing details does not change the sending provider.', $html );
		$this->assertStringNotContainsString( 'https://cdn.', $html );
		$this->assertStringNotContainsString( 'scalyn-provider-search', $html );
		$this->assertStringNotContainsString( 'scalyn-provider-filter', $html );
	}
	public function test_help_text_escapes_content_and_label(): void {
		ob_start();
		try { \Scalyn\MailRelay\Admin\Components\HelpText::render('" onmouseover="unsafe','<script>unsafe</script>'); $html=ob_get_contents(); }
		finally { ob_end_clean(); }
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
		$this->assertStringContainsString('&quot; onmouseover=&quot;unsafe', $html);
	}
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
		foreach (array('smtp'=>'SMTP', 'sendgrid'=>'SendGrid API', 'postmark'=>'Postmark API', 'smtp2go'=>'SMTP2GO API', 'brevo'=>'Brevo API') as $id=>$label) {
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
		$this->assertStringContainsString('SMTP2GO API', $html);
		$this->assertStringContainsString('Brevo API', $html);
		$this->assertStringContainsString('Live Server token', $html);
	}
	public function test_active_route_shows_current_scope_health_without_credentials(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = array(
			'provider' => array('active'=>'smtp','verified'=>true,'verified_at'=>'2026-09-30T08:00:00+00:00'),
			'smtp' => array('host'=>'smtp.example.test','from_email'=>'sender@example.test','password'=>'NEVER_RENDER_THIS'),
		);
		$html = $this->render_page();
		$this->assertStringContainsString('Active sending route', $html);
		$this->assertStringContainsString('Connection status', $html);
		$this->assertStringContainsString('Not checked for the current configuration', $html);
		$this->assertStringContainsString('Provider health', $html);
		$this->assertStringContainsString('Unknown', $html);
		$this->assertStringContainsString('class="scalyn-help-text"', $html);
		$this->assertStringContainsString('aria-label="About provider health"', $html);
		$this->assertStringContainsString('class="scalyn-help-text__content"', $html);
		$this->assertStringContainsString('Verify connection', $html);
		$this->assertStringContainsString('Settings saved', $html);
		$this->assertStringContainsString('current configuration', $html);
		$this->assertStringNotContainsString('NEVER_RENDER_THIS', $html);
		$this->assertStringContainsString('wizard&step=3">Configure in wizard', $html);
		$this->assertStringContainsString('wizard&step=2">Select in wizard', $html);
	}
	public function test_inactive_cards_do_not_reuse_active_provider_health(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = array('provider'=>array('active'=>'smtp','verified'=>false,'verified_at'=>'2099-01-01T00:00:00+00:00'));
		$html = $this->render_page();
		$this->assertStringNotContainsString('2099-01-01', $html);
		$this->assertStringContainsString('Not assessed — select this provider to assess its current configuration.', $html);
	}
	public function test_providers_page_no_longer_processes_credential_posts(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array('scalyn_sendgrid_settings'=>'1','key_action'=>'remove','confirm_remove'=>'1');
		$before = $GLOBALS['_test_wp_options'];
		$html = $this->render_page();
		$this->assertSame($before, $GLOBALS['_test_wp_options']);
		$this->assertStringNotContainsString('name="api_key"', $html);
	}
	public function test_tabs_group_existing_controls_without_hiding_no_script_content(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		$before = $GLOBALS['_test_wp_options'];
		$html = $this->render_page();
		$this->assertSame($before, $GLOBALS['_test_wp_options']);
		$dom = new DOMDocument();
		@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
		$xpath = new DOMXPath($dom);
		$this->assertSame(3, $xpath->query('//*[@data-provider-tab]')->length);
		foreach (array('providers', 'email-routing', 'saved-configurations') as $key) {
			$this->assertSame(1, $xpath->query('//a[@href="#' . $key . '"]')->length);
			$this->assertSame(1, $xpath->query('//*[@data-provider-panel="' . $key . '" and not(@hidden)]')->length);
		}
		$this->assertStringContainsString('Active sending route', $dom->getElementById('scalyn-panel-providers')->textContent);
		$this->assertStringContainsString('Email routing', $dom->getElementById('scalyn-panel-email-routing')->textContent);
		$this->assertStringContainsString('Saved configurations', $dom->getElementById('scalyn-panel-saved-configurations')->textContent);
		$this->assertSame(1, $xpath->query('//*[@id="scalyn-panel-email-routing"]//form[contains(@action,"#email-routing")]')->length);
		$this->assertSame(0, $xpath->query('//*[@id="scalyn-panel-providers"]//form')->length);
	}
	public function test_restricted_users_receive_no_configuration_tabs_or_panels(): void {
		$html = $this->render_page();
		$this->assertStringContainsString('href="#providers"', $html);
		$this->assertStringNotContainsString('href="#email-routing"', $html);
		$this->assertStringNotContainsString('href="#saved-configurations"', $html);
		$this->assertStringNotContainsString('id="scalyn-panel-email-routing"', $html);
		$this->assertStringNotContainsString('id="scalyn-panel-saved-configurations"', $html);
	}
}
