<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\ProviderDeliveryRepository;
use Scalyn\MailRelay\Health\ProviderDeliveryEvidence;

final class ProviderDeliveryEvidenceTest extends TestCase {
 private const ID='11111111-1111-4111-8111-111111111111';
 protected function setUp():void {
  $GLOBALS['wpdb']=new WpdbStub();
  $GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
  $GLOBALS['_test_wp_options']=[SettingsRepository::OPTION_KEY=>['provider'=>['active'=>'brevo'],'diagnostic_revision'=>self::ID]];
 }
 private function service(string $status,?array $source):ProviderDeliveryEvidence {
  $gate=new class($status,$source) {
   public function __construct(private string $status,private ?array $source){}
   public function collection_status():string {return $this->status;}
   public function dispatch_source():?array {return $this->source;}
  };
  return new ProviderDeliveryEvidence(new SettingsRepository(),new ProviderDeliveryRepository(),['brevo'=>$gate]);
 }
 public function test_source_lifecycle_and_revision_gate_prevent_queries():void {
  foreach(['off','paused','invalid'] as $state) {
   $this->assertSame(in_array($state,['off','paused'])?$state:'unavailable',$this->service($state,null)->current()['status']);
  }
  foreach([null,['id'=>self::ID,'configuration_id'=>'other']] as $source) {
   $this->assertSame('unavailable',$this->service('collecting',$source)->current()['status']);
  }
  $GLOBALS['_test_current_user_can']=[];
  $this->assertSame('unavailable',$this->service('collecting',['id'=>self::ID,'configuration_id'=>self::ID])->current()['status']);
  $this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
 }
 public function test_supported_current_source_returns_counts_without_identifiers():void {
  $GLOBALS['wpdb']->get_row_return=['attempts'=>1,'tracked'=>1,'observed'=>1,'hard_bounced'=>0];
  $result=$this->service('collecting',['id'=>self::ID,'configuration_id'=>self::ID])->current();
  $this->assertSame('available',$result['status']);$this->assertSame(1,$result['observed']);
  $this->assertStringNotContainsString(self::ID,json_encode($result));
  $GLOBALS['wpdb']->get_row_return=null;
  $this->assertSame(['status'=>'unavailable'],$this->service('collecting',['id'=>self::ID,'configuration_id'=>self::ID])->current());
 }
 public function test_unsupported_provider_does_not_query():void {
  $GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]['provider']['active']='smtp';
  $this->assertSame(['status'=>'unsupported'],$this->service('collecting',null)->current());
  $this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
 }
}
