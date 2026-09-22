<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\DataControlsPage;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\RetentionStateRepository;

final class DataControlsPageTest extends TestCase {
	public function test_settings_page_contains_dkim_and_separate_forms(): void {
		$html=$this->render();
		$this->assertStringContainsString('<h1>Settings</h1>',$html);
		$this->assertStringContainsString('DKIM configuration',$html);
		$this->assertSame(2,substr_count($html,'<form '));
		$this->assertStringNotContainsString('Scheduled health monitoring',$html);
	}
	public function test_dkim_save_does_not_change_data_controls(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['advanced'=>['log_retention_days'=>60,'diagnostic_schedule'=>'hourly','delete_data_on_uninstall'=>true]];
		$_SERVER['REQUEST_METHOD']='POST';
		$_POST=['scalyn_dkim_settings'=>'1','dkim_selector'=>'selector1'];
		$this->assertStringContainsString('DKIM selector saved',$this->render());
		$settings=new SettingsRepository();
		$this->assertSame('selector1',$settings->get_dkim_selector());
		$this->assertSame(60,$settings->get_log_retention_days());
		$this->assertSame('hourly',$settings->get_diagnostic_schedule());
		$this->assertTrue($settings->get_delete_data_on_uninstall());
	}
	public function test_data_controls_save_does_not_clear_dkim(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=['advanced'=>['dkim_selector'=>'selector1']];
		$_SERVER['REQUEST_METHOD']='POST';$_POST=['retention_days'=>'45'];
		$this->assertStringContainsString('Data controls saved',$this->render());
		$this->assertSame('selector1',(new SettingsRepository())->get_dkim_selector());
	}
	protected function setUp(): void {
		$GLOBALS['_test_wp_options'] = array();
		$GLOBALS['_test_wp_cron'] = array();
		$GLOBALS['_test_current_user_can'] = array( Capabilities::MANAGE_SETTINGS => true );
		$GLOBALS['_test_wp_nonce_valid'] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = array();
	}
	protected function tearDown(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = array();
	}
	private function render(): string {
		ob_start();
		try {
			( new DataControlsPage( new SettingsRepository(), new RetentionStateRepository() ) )->render();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}
	public function test_unauthorized_user_cannot_render_or_save(): void {
		$GLOBALS['_test_current_user_can'] = array();
		$this->expectException( RuntimeException::class );
		$this->render();
	}
	public function test_invalid_nonce_cannot_save(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['_test_wp_nonce_valid'] = false;
		$this->expectException( RuntimeException::class );
		$this->render();
	}
	public function test_invalid_days_change_nothing(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( array( '0', '-1', '1.5', '3651', 'text', array( 30 ) ) as $days ) {
			$_POST = array( 'retention_days' => $days );
			$this->assertStringContainsString( 'No settings were changed', $this->render() );
			$this->assertSame( array(), $GLOBALS['_test_wp_options'] );
		}
	}
	public function test_enable_requires_confirmation_then_can_be_disabled(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'retention_days' => '60', 'delete_on_uninstall' => '1' );
		$this->assertStringContainsString( 'Confirm permanent deletion', $this->render() );
		$this->assertFalse( ( new SettingsRepository() )->get_delete_data_on_uninstall() );
		$_POST['confirm_delete'] = '1';
		$this->assertStringContainsString( 'Data controls saved', $this->render() );
		$this->assertTrue( ( new SettingsRepository() )->get_delete_data_on_uninstall() );
		$this->assertSame( 60, ( new SettingsRepository() )->get_log_retention_days() );
		unset( $_POST['delete_on_uninstall'] );
		$this->render();
		$this->assertFalse( ( new SettingsRepository() )->get_delete_data_on_uninstall() );
	}
	public function test_page_explains_policy_without_exposing_credentials(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY] = array( 'smtp' => array( 'password' => 'secret-value' ) );
		$html = $this->render();
		$this->assertStringContainsString( '100 messages', $html );
		$this->assertStringContainsString( 'WP-Cron', $html );
		$this->assertStringNotContainsString( 'secret-value', $html );
		$this->assertStringContainsString( 'scalyn-mail-relay-data-controls', $html );
	}

	public function test_schedule_can_be_saved_and_invalid_cadence_changes_nothing(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array('retention_days'=>'30','diagnostic_schedule'=>'hourly');
		$this->assertStringContainsString('Data controls saved', $this->render());
		$this->assertSame('hourly', wp_get_schedule(\Scalyn\MailRelay\Core\ScheduledHooks::DIAGNOSTICS));
		$_POST['diagnostic_schedule'] = array('invalid');
		$this->assertStringContainsString('No settings were changed', $this->render());
		$this->assertSame('hourly',(new SettingsRepository())->get_diagnostic_schedule());
	}
}
