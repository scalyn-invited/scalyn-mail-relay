<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\RecoveryController;
use Scalyn\MailRelay\Admin\Components\RecoveryPanel;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Database\RecoveryQueueRepository;

final class RecoveryControlsTest extends TestCase {
    protected function setUp():void {
        $GLOBALS['wpdb']=new WpdbStub();$GLOBALS['_test_wp_options']=[];
        $GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
        $GLOBALS['_test_wp_nonce_valid']=true;$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='POST';
    }
    protected function tearDown():void {$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';$GLOBALS['_test_current_user_can']=[];}
    private function html():string {ob_start();try{RecoveryPanel::render();return ob_get_contents();}finally{ob_end_clean();}}
    public function test_recovery_controls_are_hidden_without_settings_permission():void {
        $GLOBALS['_test_current_user_can']=[];$this->assertSame('',$this->html());
    }
    public function test_storage_unavailable_is_not_an_empty_queue_or_success():void {
        $html=$this->html();$this->assertStringContainsString('Recovery storage is unavailable',$html);
        $this->assertStringNotContainsString('No retained recovery work.',$html);
        $this->assertStringContainsString('Queued is not Accepted or Delivered.',$html);
        $this->assertStringNotContainsString('name="storage_consent" value="1" checked',$html);
    }
    public function test_feedback_never_reflects_arbitrary_input():void {
        $_GET['recovery_notice']='private-input<script>';
        $this->assertStringNotContainsString('private-input',$this->html());
    }
    public function test_cancel_uses_claim_safe_state_guard_and_erases_payload():void {
        $id='11111111-1111-4111-8111-111111111111';
        (new RecoveryQueueRepository())->cancel($id);
        $sql=end($GLOBALS['wpdb']->queries);
        $this->assertStringContainsString("payload=''",$sql);
		$args=end($GLOBALS['wpdb']->prepare_calls)['args'];
		$this->assertSame(['pending','running','review'],array_slice($args,-3));
		$this->assertNotContains('submitting',$args);
    }
    public function test_cancel_invalid_identifier_never_queries_storage():void {
        $this->assertFalse((new RecoveryQueueRepository())->cancel('invalid'));
        $this->assertSame([],$GLOBALS['wpdb']->queries);
    }
    public function test_controller_denies_missing_capability_before_action():void {
        $GLOBALS['_test_current_user_can']=[];$this->expectException(RuntimeException::class);
        (new RecoveryController())->handle();
    }
    public function test_controller_denies_invalid_nonce_before_action():void {
        $GLOBALS['_test_wp_nonce_valid']=false;$this->expectException(RuntimeException::class);
        (new RecoveryController())->handle();
    }
    public function test_controller_denies_get_before_action():void {
        $_SERVER['REQUEST_METHOD']='GET';$this->expectException(RuntimeException::class);
        (new RecoveryController())->handle();
    }
}
