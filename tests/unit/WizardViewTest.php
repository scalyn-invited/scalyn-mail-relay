<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;

/** Presentation regressions; controller tests own state and submission rules. */
final class WizardViewTest extends TestCase {
	private function render_view( int $step, string $provider = 'smtp', bool $ready = false, ?array $result = null ): string {
		$current_step = $step;
		$total_steps = 7;
		$step_labels = array( 1 => 'Welcome', 2 => 'Choose Provider', 3 => 'Configure Provider', 4 => 'Verify Connection', 5 => 'Send Test Email', 6 => 'Health Check', 7 => 'Completion' );
		$wizard_health = \Scalyn\MailRelay\Admin\HealthScorePresenter::present( null );
		$wizard_freshness = '';
		$registered_providers = array( 'smtp' => 'SMTP', 'sendgrid' => 'SendGrid API', 'postmark' => 'Postmark API', 'custom' => '<script>unsafe</script>' );
		$active_provider_id = $provider;
		$smtp_config = array( 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'example', 'from_name' => 'Example', 'from_email' => 'sender@example.test' );
		$smtp_has_password = true;
		$sendgrid_settings = array( 'has_key' => $ready, 'from_email' => $ready ? 'sender@example.test' : '' );
		$step3_errors = null;
		$conn_result = $result;
		$email_result = $result;
		$prior_options = $GLOBALS['_test_wp_options'] ?? array();
		if (in_array($provider,['smtp2go','brevo'],true)) {
			$GLOBALS['_test_wp_options']=[];
			$settings=new \Scalyn\MailRelay\Core\SettingsRepository();
			$cipher=new \Scalyn\MailRelay\Core\CredentialCipher(base64_encode(str_repeat('a',32)));
			$settings->save(['provider'=>['active'=>$provider]]);
			if ($ready) { $settings->{'save_'.$provider}(['from_email'=>'sender@example.test','from_name'=>'Sender','key_action'=>'replace','api_key'=>'synthetic_credential_0123456789'],$cipher); }
			$smtp2go_form=new \Scalyn\MailRelay\Admin\Components\Smtp2goSettingsForm($settings,$cipher);
			$brevo_form=new \Scalyn\MailRelay\Admin\Components\BrevoSettingsForm($settings,$cipher);
		}
		if ( 'postmark' === $provider ) {
			$GLOBALS['_test_wp_options'] = array();
			$settings = new \Scalyn\MailRelay\Core\SettingsRepository();
			$cipher = new \Scalyn\MailRelay\Core\CredentialCipher(base64_encode(str_repeat('a',32)));
			$settings->save(['provider'=>['active'=>'postmark']]);
			if ($ready) { $settings->save_postmark(['from_email'=>'sender@example.test','from_name'=>'Sender','key_action'=>'replace','api_key'=>'PM.synthetic_test_credential_0123456789'],$cipher); }
			$postmark_form = new \Scalyn\MailRelay\Admin\Components\PostmarkSettingsForm($settings,$cipher);
		}
		if ( 'sendgrid' === $provider ) {
			$GLOBALS['_test_wp_options'] = array();
			$settings = new \Scalyn\MailRelay\Core\SettingsRepository();
			$cipher = new \Scalyn\MailRelay\Core\CredentialCipher( base64_encode( str_repeat( 'a', 32 ) ) );
			$settings->save( array( 'provider' => array( 'active' => 'sendgrid' ) ) );
			if ( $ready ) {
				$settings->save_sendgrid( array( 'from_email' => 'sender@example.test', 'from_name' => 'Sender', 'key_action' => 'replace', 'api_key' => 'SG.synthetic_test_credential_0123456789' ), $cipher );
			}
			$sendgrid_form = new \Scalyn\MailRelay\Admin\Components\SendGridSettingsForm( $settings, $cipher );
		}
		ob_start();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/wizard.php';
		$GLOBALS['_test_wp_options'] = $prior_options;
		return (string) ob_get_clean();
	}

	public function test_all_steps_have_accessible_progress_and_contextual_help(): void {
		for ( $step = 1; $step <= 7; ++$step ) {
			$output = $this->render_view( $step );
			$this->assertStringContainsString( "Step $step of 7", $output );
			$this->assertSame( 1, substr_count( $output, 'aria-current="step"' ) );
			$this->assertStringContainsString( 'aria-labelledby="scalyn-wizard-help-title"', $output );
			$this->assertStringContainsString( 'Acceptance is not delivery', $output );
			$this->assertStringNotContainsString( 'verifying delivery', $output );
		}
	}

	public function test_expanded_providers_configure_inside_wizard_and_gate_continue(): void {
		$GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true,Capabilities::MANAGE_MAIL=>true];
		$_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
		foreach (['smtp2go'=>'SMTP2GO','brevo'=>'Brevo'] as $id=>$label) {
			$html=$this->render_view(3,$id,false);
			$this->assertStringContainsString('Configure '.$label,$html);
			$this->assertStringContainsString('name="scalyn_'.$id.'_settings"',$html);
			$this->assertStringNotContainsString('Continue to verification',$html);
			$this->assertStringNotContainsString('Configure SMTP<',$html);
			$html=$this->render_view(3,$id,true);
			$this->assertStringContainsString('Continue to verification',$html);
			$this->assertStringNotContainsString('synthetic_credential',$html);
			$this->assertStringContainsString('without sending mail',$this->render_view(4,$id,true));
		}
	}

	public function test_postmark_has_inline_configuration_and_truthful_verification(): void {
		$GLOBALS['_test_current_user_can'] = [Capabilities::MANAGE_SETTINGS=>true,Capabilities::MANAGE_MAIL=>true];
		$_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
		$html=$this->render_view(3,'postmark',false);
		$this->assertStringContainsString('Configure Postmark',$html);
		$this->assertStringContainsString('name="scalyn_postmark_settings"',$html);
		$this->assertStringNotContainsString('Continue to verification',$html);
		$this->assertStringNotContainsString('Configure SMTP',$html);
		$html=$this->render_view(3,'postmark',true);
		$this->assertStringContainsString('Continue to verification',$html);
		$this->assertStringContainsString('wizard&step=4',$html);
		$this->assertStringNotContainsString('PM.synthetic_test_credential',$html);
		$html=$this->render_view(4,'postmark',true);
		$this->assertStringContainsString('without sending email',$html);
		$this->assertStringContainsString('Sandbox servers are not supported',$html);
		$this->assertStringNotContainsString('Test the connection to your SMTP server',$html);
	}

	public function test_provider_cards_preserve_selection_fields_and_escape_labels(): void {
		$output = $this->render_view( 2 );
		$this->assertStringContainsString( 'name="provider_id"', $output );
		$this->assertStringContainsString( 'name="wizard_step" value="2"', $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		$this->assertStringContainsString( '&lt;script&gt;unsafe&lt;/script&gt;', $output );
		$this->assertStringNotContainsString( '<script>unsafe</script>', $output );
		$this->assertStringContainsString( 'Saving changes the active provider immediately', $output );
	}

	public function test_sendgrid_continue_requires_saved_configuration(): void {
		$blocked = $this->render_view( 3, 'sendgrid' );
		$this->assertStringNotContainsString( '>Continue to verification</a>', $blocked );
		$this->assertStringNotContainsString( 'name="wizard_step"', $blocked );
		$ready = $this->render_view( 3, 'sendgrid', true );
		$this->assertStringContainsString( '>Continue to verification</a>', $ready );
		$this->assertStringNotContainsString( 'Open SendGrid Settings', $ready );
		$this->assertStringNotContainsString( 'name="smtp[host]"', $ready );
	}

	public function test_smtp_form_keeps_password_empty_and_test_actions_explicit(): void {
		$output = $this->render_view( 3 );
		$this->assertMatchesRegularExpression( '/id="smtp_password"\s+name="smtp\[password\]"\s+value=""/', $output );
		foreach ( array( 3, 4, 5 ) as $step ) {
			$output = $this->render_view( $step );
			$this->assertStringContainsString( 'method="post"', $output );
			$this->assertStringContainsString( 'name="_wpnonce"', $output );
			$this->assertStringContainsString( 'name="wizard_step" value="' . $step . '"', $output );
		}
	}

	public function test_results_are_escaped_and_delivery_limitations_remain(): void {
		foreach ( array( 4, 5 ) as $step ) {
			$output = $this->render_view( $step, 'sendgrid', true, array( 'success' => false, 'message' => '<script>bad</script>' ) );
			$this->assertStringContainsString( '&lt;script&gt;bad&lt;/script&gt;', $output );
			$this->assertStringNotContainsString( '<script>bad</script>', $output );
		}
		$this->assertStringContainsString( 'does not prove real-send permission', $this->render_view( 4, 'sendgrid' ) );
		$this->assertStringContainsString( 'This action sends a real email', $this->render_view( 5 ) );
	}

	public function test_diagnostics_next_action_requires_capability(): void {
		$previous = $GLOBALS['_test_current_user_can'] ?? array();
		try {
			$GLOBALS['_test_current_user_can'] = array();
			$this->assertStringNotContainsString( '>Open Diagnostics</a>', $this->render_view( 7 ) );
			$this->assertStringNotContainsString( 'id="scalyn-run-diagnostics"', $this->render_view( 6 ) );
			$GLOBALS['_test_current_user_can'] = array( Capabilities::RUN_DIAGNOSTICS => true );
			$this->assertStringContainsString( '>Open Diagnostics</a>', $this->render_view( 7 ) );
			$health_step = $this->render_view( 6 );
			$this->assertStringContainsString( 'id="scalyn-run-diagnostics"', $health_step );
			$this->assertStringContainsString( 'data-scalyn-action="run-diagnostics"', $health_step );
			$this->assertStringContainsString( 'No health snapshot is recorded', $health_step );
			$this->assertStringContainsString( 'Unknown', $health_step );
			$this->assertStringNotContainsString( 'Provider Configuration Complete', $health_step );
		} finally {
			$GLOBALS['_test_current_user_can'] = $previous;
		}
	}
}
