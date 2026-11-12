<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Database\WebhookRateLimitRepository;

final class WebhookBudgetDb {
	public string $prefix = 'test_';
	public string $options = 'test_options';
	public string $last_error = '';
	public int $minute = 100;
	public array $rows = [];
	public array $prepared = [];
	public bool $lock = true;
	public bool $write = true;
	public int $releases = 0;
	public function prepare($sql,...$args) { $key='query'.count($this->prepared); $this->prepared[$key]=[$sql,$args]; return $key; }
	public function get_var($query) {
		$this->last_error='';
		if (str_contains($query,'UNIX_TIMESTAMP')) { return $this->minute; }
		[$sql,$args]=$this->prepared[$query];
		if (str_contains($sql,'GET_LOCK')) { return $this->lock ? '1' : '0'; }
		if (str_contains($sql,'RELEASE_LOCK')) { $this->releases++; return '1'; }
		return $this->rows[$args[1]] ?? null;
	}
	public function query($query) {
		if (!$this->write) { $this->last_error='private SQL error'; return false; }
		[$sql,$args]=$this->prepared[$query];
		$this->rows[$args[1]]=$args[2];
		return 1;
	}
}

final class WebhookRateLimitRepositoryTest extends TestCase {
	private mixed $previous;
	private WebhookBudgetDb $db;
	private const SOURCE='12345678-1234-4234-8234-123456789abc';
	protected function setUp(): void { $this->previous=$GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb']=$this->db=new WebhookBudgetDb(); }
	protected function tearDown(): void { $GLOBALS['wpdb']=$this->previous; }
	public function test_limit_is_shared_between_instances_and_resets_on_database_minute(): void {
		for ($i=0;$i<60;$i++) { $this->assertTrue((new WebhookRateLimitRepository())->consume(self::SOURCE)); }
		$this->assertFalse((new WebhookRateLimitRepository())->consume(self::SOURCE));
		$this->assertSame(61,$this->db->releases);
		$this->assertCount(1,$this->db->rows);
		$this->db->minute++;
		$this->assertTrue((new WebhookRateLimitRepository())->consume(self::SOURCE));
		$this->assertCount(1,$this->db->rows);
		$this->assertSame(['window'=>101,'count'=>1],json_decode(array_values($this->db->rows)[0],true));
	}
	public function test_separate_sources_do_not_share_a_counter(): void {
		$repository=new WebhookRateLimitRepository();
		$this->assertTrue($repository->consume(self::SOURCE));
		$this->assertTrue($repository->consume('22345678-1234-4234-8234-123456789abc'));
		$this->assertCount(2,$this->db->rows);
	}
	public function test_lock_contention_is_unavailable_not_budget_exhaustion(): void {
		$this->db->lock=false;
		$this->expectExceptionMessage('Webhook budget is unavailable.');
		try { (new WebhookRateLimitRepository())->consume(self::SOURCE); } finally { $this->assertSame(0,$this->db->releases); $this->assertSame([],$this->db->rows); }
	}
	public function test_corrupt_or_future_state_fails_closed_and_releases(): void {
		foreach (['broken','{"window":101,"count":1}','{"window":100,"count":-1}'] as $raw) {
			$this->db->rows['scalyn_webhook_budget_'.self::SOURCE]=$raw;
			try { (new WebhookRateLimitRepository())->consume(self::SOURCE); $this->fail('Must reject corrupt state'); }
			catch (RuntimeException $error) { $this->assertSame('Webhook budget is unavailable.',$error->getMessage()); }
		}
		$this->assertSame(3,$this->db->releases);
	}
	public function test_write_failure_never_returns_permission_and_hides_error(): void {
		$this->db->write=false;
		try { (new WebhookRateLimitRepository())->consume(self::SOURCE); $this->fail('Must reject write failure'); }
		catch (RuntimeException $error) { $this->assertSame('Webhook budget is unavailable.',$error->getMessage()); $this->assertNull($error->getPrevious()); }
		$this->assertSame(1,$this->db->releases);
	}
}
