<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\ProviderDeliveryRepository;

final class ProviderDeliveryRepositoryTest extends TestCase {
 private const ID='11111111-1111-4111-8111-111111111111';
 protected function setUp():void {$GLOBALS['wpdb']=new WpdbStub();}
 protected function tearDown():void {unset($GLOBALS['wpdb']);}
 private function counts(string $provider='brevo',int $days=7):?array {return (new ProviderDeliveryRepository())->counts(self::ID,$provider,self::ID,$days,new DateTimeImmutable('2026-10-06T08:00:00+08:00'));}
 public function test_count_only_projection_is_scoped_bounded_and_utc():void {
  $GLOBALS['wpdb']->get_row_return=['attempts'=>'10','tracked'=>'30','observed'=>'25','hard_bounced'=>'2'];
  $result=$this->counts();
  $this->assertSame(['tracked'=>30,'observed'=>25,'hard_bounced'=>2,'window_start'=>'2026-09-29 00:00:00.000000','window_end'=>'2026-10-06 00:00:00.000000','days'=>7],$result);
  $call=$GLOBALS['wpdb']->prepare_calls[0];$sql=$call['query'];
  foreach(['configuration_id=%s','provider=%s','source_id=%s','LIMIT 1001','r.message_uuid=a.message_uuid','e.recipient_token=r.recipient_token','e.source_id=a.source_id','e.provider=a.provider','e.received_at >= %s','e.occurred_at <= %s','GROUP BY a.message_uuid, r.recipient_token'] as $guard){$this->assertStringContainsString($guard,$sql);}
  $this->assertSame([self::ID,'brevo',self::ID],array_slice($call['args'],1,3));
  $this->assertStringNotContainsString(self::ID,json_encode($result));
  $this->assertFalse($GLOBALS['wpdb']->errors_suppressed);
 }
 public function test_missing_invalid_or_overflow_rows_are_unavailable_not_zero():void {
  foreach([null,['attempts'=>1001,'tracked'=>1001,'observed'=>1000,'hard_bounced'=>0],['attempts'=>1,'tracked'=>0,'observed'=>0,'hard_bounced'=>0],['attempts'=>1,'tracked'=>1,'observed'=>2,'hard_bounced'=>0],['attempts'=>1,'tracked'=>1,'observed'=>1,'hard_bounced'=>2],['attempts'=>1,'tracked'=>1,'observed'=>1],['attempts'=>'bad','tracked'=>1,'observed'=>1,'hard_bounced'=>0]] as $row) {
   $GLOBALS['wpdb']->get_row_return=$row;$this->assertNull($this->counts());
  }
  $GLOBALS['wpdb']->get_row_return=['attempts'=>0,'tracked'=>0,'observed'=>0,'hard_bounced'=>0];
  $this->assertSame(0,$this->counts()['tracked']);
 }
 public function test_invalid_scope_never_queries_and_short_retention_changes_cutoff():void {
  $this->assertNull($this->counts('smtp'));$this->assertNull($this->counts('brevo',0));$this->assertNull($this->counts('brevo',8));
  $this->assertSame([],$GLOBALS['wpdb']->prepare_calls);
  $GLOBALS['wpdb']->get_row_return=['attempts'=>0,'tracked'=>0,'observed'=>0,'hard_bounced'=>0];
  $this->assertSame('2026-10-05 00:00:00.000000',$this->counts('smtp2go',1)['window_start']);
 }
 public function test_storage_exception_is_safe_and_restores_error_mode():void {
  $GLOBALS['wpdb']=new class extends WpdbStub {public function get_row(string $q,string $out=OBJECT):array|object|null {throw new RuntimeException('private SQL');}};
  $this->assertNull($this->counts());$this->assertFalse($GLOBALS['wpdb']->errors_suppressed);
 }
}
