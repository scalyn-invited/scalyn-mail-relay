<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Agency\BrandingRepository;
use Scalyn\MailRelay\Admin\AgencyBranding;
use Scalyn\MailRelay\Admin\Pages\AgencyPage;
use Scalyn\MailRelay\Agency\AgencyRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;

final class AgencyBrandingTest extends TestCase {
	public function test_agency_tabs_preserve_three_independent_protected_forms(): void {
		ob_start();
		try { (new AgencyPage(new AgencyRepository()))->render(); $html=ob_get_contents(); }
		finally { ob_end_clean(); }
		$dom=new DOMDocument(); @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
		$this->assertSame(3,$xpath->query('//*[@data-provider-tab]')->length);
		$this->assertSame('branding-support',$xpath->query('//*[@data-provider-tab]')->item(0)->getAttribute('data-provider-tab'));
		$this->assertSame(2,$xpath->query('//p[@class="scalyn-branding-feedback" and @role="status" and @aria-live="polite"]')->length);
		foreach(array('managed-configuration'=>'policy','access-profiles'=>'profile','branding-support'=>'branding') as $key=>$action){
			$this->assertSame(1,$xpath->query('//*[@id="scalyn-panel-'.$key.'"]//form//input[@name="agency_action" and @value="'.$action.'"]')->length);
		}
		$this->assertSame(0,$xpath->query('//*[@data-provider-panel and @hidden]')->length);
	}
	private array $events = [];
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_current_user_can'] = ['manage_options' => true];
		$GLOBALS['_test_wp_added_actions'] = [];
		$this->events = [];
		$GLOBALS['_test_wp_actions'] = [HookNames::AUDIT_EVENT=>function($event) { $this->events[]=$event; }];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_attachments'] = [];
		$GLOBALS['_test_screen'] = null;
		$GLOBALS['_test_wp_nonce_valid'] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = [];
	}
	protected function tearDown(): void { $this->setUp(); $GLOBALS['_test_wp_actions']=[]; }

	public function test_defaults_and_scoped_css(): void {
		$this->assertSame(BrandingRepository::defaults(), (new BrandingRepository())->get());
		$this->assertSame('.scalyn-mail-relay{--scalyn-accent:#08766b;--scalyn-ink:#17292e;}', AgencyBranding::css());
	}
	public function test_menu_icon_css_is_bounded_and_scoped_independent_of_branding(): void {
		$css=AgencyBranding::menu_icon_css();
		$this->assertStringStartsWith('#adminmenu #toplevel_page_scalyn-mail-relay .wp-menu-image img{',$css);
		foreach(['width:20px!important','height:20px!important','max-width:20px!important','max-height:20px!important','object-fit:contain','padding:0!important'] as $rule){$this->assertStringContainsString($rule,$css);}
		$GLOBALS['_test_wp_options'][BrandingRepository::OPTION]=['name'=>'Other name'];
		$this->assertSame($css,AgencyBranding::menu_icon_css());
	}
	public function test_menu_label_uses_custom_brand_and_preserves_default_on_reset(): void {
		$this->assertSame('Mail Relay',AgencyBranding::menu_label());
		$input=BrandingRepository::defaults();$input['name']='Agency & Co';
		$repository=new BrandingRepository();$repository->save($input);
		$this->assertSame('Agency & Co',AgencyBranding::menu_label());
		$repository->save(BrandingRepository::defaults());
		$this->assertSame('Mail Relay',AgencyBranding::menu_label());
	}
	public function test_invalid_stored_brand_never_becomes_menu_markup(): void {
		foreach(['',str_repeat('a',81),'<img src=x onerror=alert(1)>',[],"Brand\nName"] as $invalid){
			$GLOBALS['_test_wp_options'][BrandingRepository::OPTION]=['name'=>$invalid];
			$this->assertSame('Mail Relay',AgencyBranding::menu_label());
		}
	}
	public function test_only_owned_menu_label_changes_with_safe_escaping(): void {
		$GLOBALS['_test_wp_options'][BrandingRepository::OPTION]=['name'=>'Agency & Co'];
		$original=[2=>['Dashboard','read','index.php'],56=>['Mail Relay',Capabilities::VIEW_DASHBOARD,'scalyn-mail-relay','Scalyn Mail Relay','menu-top','toplevel_page_scalyn-mail-relay','dashicons-email-alt2']];
		$GLOBALS['menu']=$original;
		try {
			AgencyBranding::apply_menu_label();
			$this->assertSame('Agency &amp; Co',$GLOBALS['menu'][56][0]);
			$this->assertSame($original[2],$GLOBALS['menu'][2]);
			$this->assertSame(array_slice($original[56],1),array_slice($GLOBALS['menu'][56],1));
		} finally {unset($GLOBALS['menu']);}
	}

	#[DataProvider('invalid_fields')]
	public function test_invalid_values_do_not_write_or_audit(string $field, mixed $value): void {
		$input = BrandingRepository::defaults(); $input[$field] = $value;
		try { (new BrandingRepository())->save($input); $this->fail('Invalid branding accepted.'); }
		catch (InvalidArgumentException $error) {
			$this->assertArrayNotHasKey(BrandingRepository::OPTION, $GLOBALS['_test_wp_options']);
			$this->assertEmpty($this->events);
		}
	}
	public static function invalid_fields(): array {
		return [['name', '<script>alert(1)</script>'], ['name', "Brand\nName"], ['name', str_repeat('a',81)], ['name', []],
			['primary', '#ffffff'], ['secondary', '#777777'], ['primary', '#000000;}body{display:none'],
			['support_url','javascript:alert(1)'], ['docs_url','http://example.test/docs'], ['support_url','https://user:secret@example.test/'],
			['docs_url','https://example.test/?token=secret'], ['support_url','https://example.test/#secret'],
			['support_email','not-an-email'], ['logo_id', '1'], ['logo_id', -1], ['logo_id', 999],
			['menu_icon_id', '1'], ['menu_icon_id', -1], ['menu_icon_id', 999]];
	}
	public function test_separate_menu_icon_and_deleted_or_active_image_fallback(): void {
		$GLOBALS['_test_attachments'][4]=['type'=>'attachment','mime'=>'image/png','url'=>'https://example.test/icon.png'];
		$input=BrandingRepository::defaults();$input['menu_icon_id']=4;
		$repo=new BrandingRepository();$repo->save($input);
		$this->assertSame('', $repo->logo_url());
		$this->assertSame('https://example.test/icon.png',$repo->menu_icon_url());
		$this->assertContains('branding.menu_icon_id',$this->events[0]->changed_fields);
		$GLOBALS['menu']=[56=>['Mail Relay','read','scalyn-mail-relay','Title','menu-top','stable-hook','dashicons-email-alt2']];
		try {
			AgencyBranding::apply_menu_label();
			$this->assertSame('https://example.test/icon.png',$GLOBALS['menu'][56][6]);
			$this->assertSame('stable-hook',$GLOBALS['menu'][56][5]);
			foreach(['image/svg+xml', 'text/html', null] as $mime){
				if(null===$mime){unset($GLOBALS['_test_attachments'][4]);}else{$GLOBALS['_test_attachments'][4]['mime']=$mime;}
				AgencyBranding::apply_menu_label();
				$this->assertSame('dashicons-email-alt2',$GLOBALS['menu'][56][6]);
			}
		}finally{unset($GLOBALS['menu']);}
	}
	public function test_legacy_branding_without_icon_keeps_envelope_and_other_fields(): void {
		$GLOBALS['_test_wp_options'][BrandingRepository::OPTION]=['name'=>'Old brand'];
		$brand=(new BrandingRepository())->get();
		$this->assertSame('Old brand',$brand['name']);
		$this->assertSame(0,$brand['menu_icon_id']);
	}
	public function test_raster_logo_and_identity_saved_with_value_free_audit(): void {
		$GLOBALS['_test_attachments'][4] = ['type'=>'attachment','mime'=>'image/png','url'=>'https://example.test/logo.png'];
		$input = BrandingRepository::defaults();
		$input['logo_id'] = 4; $input['name'] = 'Agency & Co'; $input['primary'] = '#123456';
		$input['support_email'] = 'help@example.test'; $input['support_url'] = 'https://example.test/support';
		$repo = new BrandingRepository(); $repo->save($input);
		$this->assertSame($input, $repo->get());
		$this->assertSame('https://example.test/logo.png', $repo->logo_url());
		$event = $this->events[0];
		$this->assertSame('agency_branding', $event->action);
		$this->assertContains('branding.support_email', $event->changed_fields);
		$this->assertStringNotContainsString('help@example.test', json_encode($event->metadata()));
		$repo->save($input);
		$this->assertCount(1,$this->events);
	}
	public function test_persistence_failure_is_not_saved_or_audited(): void {
		$GLOBALS['_test_wp_option_write_failures'][BrandingRepository::OPTION]=true;
		$input=BrandingRepository::defaults(); $input['name']='Changed';
		try { (new BrandingRepository())->save($input); $this->fail('Failed write accepted.'); }
		catch(RuntimeException $error) { $this->assertSame('Scalyn Mail Relay',(new BrandingRepository())->get()['name']); $this->assertEmpty($this->events); }
	}
	public function test_svg_and_deleted_logo_are_not_rendered(): void {
		$GLOBALS['_test_attachments'][4] = ['type'=>'attachment','mime'=>'image/svg+xml','url'=>'https://example.test/logo.svg'];
		$GLOBALS['_test_wp_options'][BrandingRepository::OPTION] = ['logo_id'=>4,'primary'=>'#fff; bad'];
		$repo = new BrandingRepository();
		$this->assertSame('', $repo->logo_url());
		$this->assertSame('#08766b', $repo->get()['primary']);
	}
	public function test_denied_actor_cannot_write(): void {
		$GLOBALS['_test_current_user_can'] = [Capabilities::VIEW_DASHBOARD=>true];
		$this->expectException(RuntimeException::class);
		(new BrandingRepository())->save(BrandingRepository::defaults());
	}
	public function test_page_requires_nonce_before_branding_write(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = ['agency_action'=>'branding'];
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$this->expectException(RuntimeException::class);
		(new AgencyPage(new AgencyRepository()))->render();
	}
	public function test_identity_is_only_rendered_on_plugin_screens_and_escaped(): void {
		$GLOBALS['_test_wp_options'][BrandingRepository::OPTION] = ['name'=>'Agency & Co'];
		$GLOBALS['_test_screen'] = (object)['id'=>'dashboard'];
		ob_start(); AgencyBranding::render(); $this->assertSame('',ob_get_clean());
		$GLOBALS['_test_screen'] = (object)['id'=>'toplevel_page_scalyn-mail-relay'];
		ob_start(); AgencyBranding::render(); $html=ob_get_clean();
		$this->assertStringContainsString('Agency &amp; Co',$html);
		$this->assertStringNotContainsString('<script',$html);
	}
	public function test_branding_event_survives_safe_audit_projection_without_values(): void {
		$input=BrandingRepository::defaults(); $input['support_email']='help@example.test';
		(new BrandingRepository())->save($input);
		$event=$this->events[0];
		$GLOBALS['wpdb']=new WpdbStub();
		$GLOBALS['wpdb']->get_results_return=[['id'=>1,'user_id'=>1,'action'=>'agency_branding','resource_id'=>$event->correlation_id,'metadata'=>json_encode($event->metadata()),'created_at'=>'2026-10-12 00:00:00']];
		try {
			$rows=(new \Scalyn\MailRelay\Audit\AuditRepository())->page()['rows'];
			$this->assertSame('agency_branding',$rows[0]['action']);
			$this->assertSame(['branding.support_email'],$rows[0]['changed_fields']);
			$this->assertStringNotContainsString('help@example.test',json_encode($rows));
		} finally { unset($GLOBALS['wpdb']); }
	}
}
