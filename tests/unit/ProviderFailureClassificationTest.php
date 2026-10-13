<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Providers\Brevo\BrevoProvider;
use Scalyn\MailRelay\Providers\Smtp2go\Smtp2goProvider;
use Scalyn\MailRelay\Providers\Postmark\PostmarkProvider;
use Scalyn\MailRelay\Providers\SendGrid\SendGridProvider;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Core\ConnectionVerification;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Database\ConnectionEvidenceRepository;

trait CategoryHttpFixture {
 public int $code=401;
 protected function http(string $path,array $args):mixed {return ['response'=>['code'=>$this->code],'body'=>'{}'];}
}
final class ProviderFailureClassificationTest extends TestCase {
 private function config():array {return ['api_key'=>'SG.synthetic_test_credential_0123456789','from_email'=>'sender@example.com'];}
 private function message():MailMessage {return new MailMessage('11111111-1111-4111-8111-111111111111','sender@example.com',['recipient@example.com'],'Test','Body');}
 public function test_api_categories_are_observed_not_guessed():void {
  $providers=[
   new class extends BrevoProvider {use CategoryHttpFixture;},
   new class extends Smtp2goProvider {use CategoryHttpFixture;},
   new class extends PostmarkProvider {use CategoryHttpFixture;},
  ];
  foreach($providers as $p) {
   $this->assertSame('config',$p->test_connection([])->failure_category);
   foreach([401=>'auth',403=>'provider-rejection',429=>'rate-limit',500=>'unknown',200=>'unknown'] as $code=>$category) {
    $p->code=$code;
    $this->assertSame($category,$p->test_connection($this->config())->failure_category);
    $this->assertSame($category,$p->send($this->message(),$this->config())->failure_category);
   }
  }
 }
 public function test_sendgrid_401_remains_rejection_and_429_is_rate_limit():void {
  $p=new class extends SendGridProvider {
   public int $code=401;
   protected function post(array $args):mixed {return ['response'=>['code'=>$this->code],'body'=>'{}'];}
   protected function response_code(mixed $response):int {return $this->code;}
  };
  foreach([401=>'provider-rejection',403=>'provider-rejection',429=>'rate-limit',500=>'unknown'] as $code=>$category) {
   $p->code=$code;
   $this->assertSame($category,$p->test_connection($this->config())->failure_category);
   $this->assertSame($category,$p->send($this->message(),$this->config())->failure_category);
  }
 }
 public function test_verification_records_category_and_stale_read_drops_it():void {
  $rev='11111111-1111-4111-8111-111111111111';
  $GLOBALS['wpdb']=new WpdbStub();
  $GLOBALS['_test_wp_options']=['scalyn_mail_relay_db_version'=>'0.10.0',SettingsRepository::OPTION_KEY=>['provider'=>['active'=>'brevo'],'diagnostic_revision'=>$rev]];
  $GLOBALS['_test_current_user_can']=[Capabilities::MANAGE_SETTINGS=>true];
  $provider=new class extends BrevoProvider {use CategoryHttpFixture;};
  // Invalid local config is evidence of config failure, no HTTP required.
  $registry=new ProviderRegistry();$registry->register($provider);
  $service=new ConnectionVerification(new SettingsRepository(),$registry,new ConnectionEvidenceRepository());
  $this->assertSame('config',$service->verify()->failure_category);
  $call=end($GLOBALS['wpdb']->prepare_calls);$this->assertSame('config',end($call['args']));
  $GLOBALS['wpdb']->get_row_return=['status'=>'failed','started_at'=>'2026-10-06 00:00:00.000000','checked_at'=>'2026-10-06 00:00:00.000000','failure_category'=>'auth'];
  $this->assertSame('auth',$service->current(new DateTimeImmutable('2026-10-07 UTC'))['failure_category']);
  $stale=$service->current(new DateTimeImmutable('2026-10-14 UTC'));
  $this->assertSame('stale',$stale['status']);$this->assertArrayNotHasKey('failure_category',$stale);
  $GLOBALS['_test_wp_options']=[];$GLOBALS['_test_current_user_can']=[];unset($GLOBALS['wpdb']);
 }
}
