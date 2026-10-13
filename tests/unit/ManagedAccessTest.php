<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Agency\AgencyRepository;
use Scalyn\MailRelay\Agency\ManagedAccess;
use Scalyn\MailRelay\Admin\Pages\AgencyPage;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Core\SettingsRepository;

final class ManagedAccessTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = [];
		$GLOBALS['_test_wp_option_write_failures'] = [];
		$GLOBALS['_test_user_meta'] = [];
		$GLOBALS['_test_user_meta_write_failure'] = false;
		$GLOBALS['_test_current_user_can'] = [];
		$GLOBALS['_test_site_users'] = [2 => (object) ['ID' => 2, 'allcaps' => ['read' => true]]];
		$GLOBALS['_test_current_user_id'] = 1;
		$GLOBALS['_test_blog_id'] = 1;
		$GLOBALS['_test_wp_added_actions'] = [];
		$GLOBALS['_test_wp_nonce_valid'] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = [];
	}

	protected function tearDown(): void {
		$this->setUp();
	}

	private function locked(): void {
		$GLOBALS['_test_wp_options'][AgencyRepository::OPTION] = ['managed' => true, 'smtp' => true, 'providers' => true, 'critical' => true];
	}

	public function test_absent_policy_does_not_change_existing_behavior(): void {
		$this->assertFalse(ManagedAccess::is_locked('smtp'));
		$this->assertFalse(ManagedAccess::is_locked('providers'));
		$this->assertFalse(ManagedAccess::is_locked('critical'));
		$this->assertTrue(ManagedAccess::is_locked('unexpected'));
	}

	public function test_malformed_policy_is_not_an_unlock(): void {
		$GLOBALS['_test_wp_options'][AgencyRepository::OPTION] = ['managed' => 'false'];
		$this->assertTrue(ManagedAccess::is_locked('smtp'));
		$this->assertTrue(ManagedAccess::is_locked('providers'));
	}

	public function test_managed_mode_off_disables_locks_without_erasing_the_policy(): void {
		$this->locked();
		$GLOBALS['_test_wp_options'][AgencyRepository::OPTION]['managed'] = false;
		$this->assertFalse(ManagedAccess::is_locked('critical'));
		$this->assertTrue((new AgencyRepository())->policy()['critical']);
	}

	#[DataProvider('overrides')]
	public function test_approved_override_can_change_locked_settings(string $cap): void {
		$this->locked();
		$GLOBALS['_test_current_user_can'][$cap] = true;
		$this->assertFalse(ManagedAccess::is_locked('smtp'));
		$this->assertTrue((new SettingsRepository())->save(['advanced' => ['log_retention_days' => 12]]));
	}
	public static function overrides(): array {
		return [['manage_options'], [Capabilities::MANAGE_AGENCY]];
	}

	#[DataProvider('setting_groups')]
	public function test_direct_repository_write_cannot_bypass_a_managed_lock(array $input): void {
		$this->locked();
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_SETTINGS => true, Capabilities::MANAGE_MAIL => true];
		$settings = new SettingsRepository();
		try {
			$settings->save($input);
			$this->fail('Locked write was accepted.');
		} catch (RuntimeException $error) {
			$this->assertStringContainsString('managed', $error->getMessage());
			$this->assertArrayNotHasKey(SettingsRepository::OPTION_KEY, $GLOBALS['_test_wp_options']);
		}
	}
	public static function setting_groups(): array {
		return [[['smtp' => ['host' => 'smtp.example.test']]], [['provider' => ['active' => 'smtp']]], [['advanced' => ['dkim_selector' => 'key1']]]];
	}

	#[DataProvider('api_savers')]
	public function test_each_api_credential_write_is_locked_before_validation(string $method): void {
		$this->locked();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('managed');
		(new SettingsRepository())->$method(['api_key' => 'never disclose this'], new CredentialCipher());
	}
	public static function api_savers(): array {
		return [['save_sendgrid'], ['save_postmark'], ['save_smtp2go'], ['save_brevo']];
	}

	public function test_unlocked_group_remains_editable_and_policy_is_read_fresh(): void {
		$settings = new SettingsRepository();
		$this->locked();
		$GLOBALS['_test_wp_options'][AgencyRepository::OPTION]['critical'] = false;
		$this->assertTrue($settings->save(['advanced' => ['log_retention_days' => 12]]));
		$this->assertTrue(ManagedAccess::is_locked('smtp'));
	}

	#[DataProvider('profile_permissions')]
	public function test_profiles_limit_only_plugin_capabilities(string $profile, array $allowed): void {
		$GLOBALS['_test_user_meta'][2][ManagedAccess::profile_key()] = $profile;
		$original = array_fill_keys(Capabilities::all(), true) + ['read' => true, 'edit_posts' => true, 'manage_options' => false];
		$actual = (new ManagedAccess())->filter_capabilities($original, [], [], (object) ['ID' => 2]);
		foreach (Capabilities::all() as $cap) {
			$this->assertSame(in_array($cap, $allowed, true), $actual[$cap], $cap);
		}
		$this->assertTrue($actual['read']);
		$this->assertTrue($actual['edit_posts']);
		$this->assertFalse($actual['manage_options']);
	}
	public static function profile_permissions(): array {
		return [
			['agency', Capabilities::all()],
			['support', [Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_LOGS, Capabilities::RUN_DIAGNOSTICS, Capabilities::VIEW_AUDIT]],
			['auditor', [Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_LOGS, Capabilities::VIEW_AUDIT]],
			['client', [Capabilities::VIEW_DASHBOARD, Capabilities::VIEW_CLIENT_REPORTS]],
			['corrupted', []],
		];
	}

	public function test_administrator_is_never_reduced_by_client_profile(): void {
		$GLOBALS['_test_user_meta'][2][ManagedAccess::profile_key()] = 'client';
		$caps = (new ManagedAccess())->filter_capabilities(['manage_options' => true], [], [], (object) ['ID' => 2]);
		foreach (Capabilities::all() as $cap) $this->assertTrue($caps[$cap]);
	}

	public function test_access_is_site_bound_and_none_restores_underlying_capabilities(): void {
		$GLOBALS['_test_user_meta'][2][ManagedAccess::profile_key()] = 'agency';
		$GLOBALS['_test_blog_id'] = 2;
		$this->assertSame(['read' => true], (new ManagedAccess())->filter_capabilities(['read' => true], [], [], (object) ['ID' => 2]));
		$GLOBALS['_test_user_meta'][2][ManagedAccess::profile_key()] = 'none';
		$this->assertSame(['read' => true], (new ManagedAccess())->filter_capabilities(['read' => true], [], [], (object) ['ID' => 2]));
	}

	public function test_policy_save_requires_authority_before_writing(): void {
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->save_policy(['managed' => true, 'smtp' => true, 'providers' => true, 'critical' => true]);
	}

	public function test_client_cannot_assign_their_own_agency_profile(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::VIEW_DASHBOARD] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->assign_profile(2, 'agency');
	}

	public function test_agency_manager_can_assign_support_but_not_edit_an_administrator(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_AGENCY] = true;
		$repository = new AgencyRepository();
		$repository->assign_profile(2, 'support');
		$this->assertSame('support', get_user_meta(2, ManagedAccess::profile_key(), true));
		$GLOBALS['_test_site_users'][2]->allcaps['manage_options'] = true;
		$this->expectException(RuntimeException::class);
		$repository->assign_profile(2, 'client');
	}

	public function test_webhook_lifecycle_is_protected_at_its_service_boundary(): void {
		$this->locked();
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_MAIL] = true;
		$services = [
			new \Scalyn\MailRelay\Core\PostmarkWebhookSettings(new CredentialCipher()),
			new \Scalyn\MailRelay\Core\ApiWebhookSettings(new CredentialCipher(), 'brevo'),
			new \Scalyn\MailRelay\Core\ApiWebhookSettings(new CredentialCipher(), 'smtp2go'),
		];
		foreach ($services as $service) {
			try { $service->save([], 'valid'); $this->fail('Locked source saved'); }
			catch (RuntimeException $error) { $this->assertStringContainsString('managed', $error->getMessage()); }
		}
	}

	public function test_denied_provider_post_uses_safe_feedback_before_parsing_secrets(): void {
		$this->locked();
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_MAIL] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = ['scalyn_sendgrid_settings' => '1', 'api_key' => 'do-not-expose'];
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('permission');
		try { (new \Scalyn\MailRelay\Admin\Components\SendGridSettingsForm(new SettingsRepository(), new CredentialCipher()))->handle(); }
		finally { $this->assertArrayNotHasKey(SettingsRepository::OPTION_KEY, $GLOBALS['_test_wp_options']); }
	}

	public function test_locked_settings_render_disabled_controls(): void {
		$this->locked();
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_SETTINGS] = true;
		ob_start();
		try {
			(new \Scalyn\MailRelay\Admin\Pages\DataControlsPage(new SettingsRepository(), new \Scalyn\MailRelay\Database\RetentionStateRepository()))->render();
			$html = ob_get_contents();
		} finally { ob_end_clean(); }
		$this->assertStringContainsString('disabled="disabled"', $html);
		$this->assertStringContainsString('managed by your administrator', $html);
		$this->assertStringNotContainsString('name="dkim_selector"', $html);
	}

	public function test_access_audit_rejects_injected_metadata(): void {
		$this->expectException(InvalidArgumentException::class);
		new AuditEvent('agency_access', 'changed', wp_generate_uuid4(), [], [], ['user_id' => 2, 'profile' => 'client', 'email' => 'must-not-be-stored']);
	}

	public function test_policy_save_emits_only_changed_field_names(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$events = [];
		add_action(HookNames::AUDIT_EVENT, function($event) use (&$events) { $events[] = $event; });
		(new AgencyRepository())->save_policy(['managed' => true, 'smtp' => true, 'providers' => false, 'critical' => false]);
		$this->assertSame(['agency.managed', 'agency.smtp'], $events[0]->changed_fields);
		$this->assertSame('agency_policy', $events[0]->action);
		$this->assertArrayNotHasKey('smtp', $events[0]->metadata());
	}

	public function test_policy_write_failure_is_not_reported_as_success(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_test_wp_option_write_failures'][AgencyRepository::OPTION] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->save_policy(['managed' => true, 'smtp' => true, 'providers' => false, 'critical' => false]);
	}

	public function test_policy_rejects_extra_or_nonboolean_fields(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->save_policy(['managed' => 'false', 'smtp' => true, 'providers' => false, 'critical' => false]);
	}

	public function test_assignment_preserves_wordpress_roles_and_audits_the_target_id(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_test_wp_roles'] = ['subscriber' => new RoleStub()];
		$roles = $GLOBALS['_test_wp_roles'];
		$events = [];
		add_action(HookNames::AUDIT_EVENT, function($event) use (&$events) { $events[] = $event; });
		(new AgencyRepository())->assign_profile(2, 'support');
		$this->assertSame('support', get_user_meta(2, ManagedAccess::profile_key(), true));
		$this->assertSame($roles, $GLOBALS['_test_wp_roles']);
		$this->assertSame(['user_id' => 2, 'profile' => 'support'], $events[0]->metadata()['access']);
		$this->assertSame(4, $events[0]->metadata()['version']);
	}

	public function test_agency_manager_cannot_delegate_agency_authority(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::MANAGE_AGENCY] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->assign_profile(2, 'agency');
	}

	public function test_profile_assignment_cannot_target_a_nonmember(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->assign_profile(999, 'agency');
	}

	public function test_profile_save_failure_is_not_reported_as_success(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_test_user_meta_write_failure'] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyRepository())->assign_profile(2, 'client');
	}

	public function test_audit_normalization_accepts_only_the_fixed_access_shape(): void {
		$GLOBALS['wpdb'] = new WpdbStub();
		$event = new AuditEvent('agency_access', 'changed', wp_generate_uuid4(), [], [], ['user_id' => 2, 'profile' => 'client']);
		$GLOBALS['wpdb']->get_results_return = [['id' => 1, 'user_id' => 1, 'action' => 'agency_access', 'resource_id' => $event->correlation_id, 'metadata' => json_encode($event->metadata()), 'created_at' => '2026-10-12 00:00:00']];
		$rows = (new AuditRepository())->page()['rows'];
		$this->assertSame($event->access, $rows[0]['access']);
	}

	public function test_agency_page_denies_a_client_before_rendering(): void {
		$GLOBALS['_test_current_user_can'][Capabilities::VIEW_DASHBOARD] = true;
		$this->expectException(RuntimeException::class);
		(new AgencyPage(new AgencyRepository()))->render();
	}

	public function test_agency_page_renders_labeled_forms_and_no_credentials(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		ob_start();
		try { (new AgencyPage(new AgencyRepository()))->render(); $html = ob_get_contents(); }
		finally { ob_end_clean(); }
		$this->assertStringContainsString('Agency Settings', $html);
		$this->assertStringContainsString('scalyn-access-user', $html);
		$this->assertStringContainsString('agency_action', $html);
		$this->assertStringNotContainsString('type="password"', $html);
	}

	public function test_post_requires_a_nonce_before_policy_mutation(): void {
		$GLOBALS['_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = ['agency_action' => 'policy', 'managed' => '1'];
		$this->expectException(RuntimeException::class);
		try { (new AgencyPage(new AgencyRepository()))->render(); }
		finally { $this->assertArrayNotHasKey(AgencyRepository::OPTION, $GLOBALS['_test_wp_options']); }
	}
}
