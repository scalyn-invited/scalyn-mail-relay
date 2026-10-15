<?php

use Scalyn\MailRelay\Database\DeliveryEvidenceSchema;

/** Simulates introspection only; real dbDelta integration is tested separately. */
class DeliverySchemaWpdbStub extends WpdbStub {
	public string $engine = 'InnoDB';
	public bool $brokenIndex = false;
	public bool $missingColumn = false;
	public bool $missingRetirement = false;
	public bool $missingMailRevision = false;
	public bool $brokenMailIndex = false;
	public bool $missingFailureCategory = false;
	public bool $missingDeliverability = false;
	public bool $brokenDeliverabilityIndex = false;
	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
	private function definition(): ?array {
		$call=end($this->prepare_calls);
		return DeliveryEvidenceSchema::definitions()[substr($call['args'][0] ?? '',strlen($this->prefix))] ?? null;
	}
	public function get_var(string $query): mixed {
		$call=end($this->prepare_calls);
		if (str_contains($query,'SELECT ENGINE') && in_array($call['args'][0] ?? '', [$this->prefix.'scalyn_ai_sessions',$this->prefix.'scalyn_ai_usage'],true)) { return $this->engine; }
		if (str_contains($query,'SELECT ENGINE') && ($call['args'][0] ?? '') === $this->prefix.'scalyn_recovery_queue') { return $this->engine; }
		if (str_contains($query,'SELECT ENGINE') && ($call['args'][0] ?? '') === $this->prefix.'scalyn_submission_attempts') { return $this->engine; }
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_deliverability_evidence') { return $this->engine; }
		if (str_contains($query,'SELECT ENGINE') && in_array(substr($call['args'][0] ?? '',strlen($this->prefix)), ['scalyn_diagnostics','scalyn_health_scores','scalyn_mail_logs','scalyn_mail_timeline'],true)) { return 'InnoDB'; }
		return $this->definition() ? $this->engine : parent::get_var($query);
	}
	public function get_col(string $query): array {
		$call=end($this->prepare_calls);
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_ai_sessions') { return ['uuid','owner','payload','claim','claim_until','expires_at','updated_at']; }
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_ai_usage') { return ['scope','used','expires_at']; }
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_recovery_queue') { return ['logical_uuid','payload','state','attempt_count','claim_token','claimed_at','next_at','expires_at','created_at','updated_at','reason']; }
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_submission_attempts') { return ['attempt_uuid','logical_uuid','configuration_id','provider','origin','attempt_number','status','failure_category','safe_to_retry','created_at','finished_at']; }
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_deliverability_evidence') { return $this->missingDeliverability ? [] : ['id','computation_uuid','configuration_id','provider','diagnostic_uuid','kind','weights_version','payload','created_at']; }
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_connection_evidence') { return ['configuration_id','provider','status','started_at','checked_at']; }
		$d=$this->definition();
		return $d ? ($this->missingColumn ? [] : array_merge($d['columns'], $this->missingRetirement ? [] : ['retired_at'])) : parent::get_col($query);
	}
	public function get_results(string $query,string $output=OBJECT): array {
		$call=end($this->prepare_calls);
		if (in_array($call['args'][0] ?? '', [$this->prefix.'scalyn_ai_sessions',$this->prefix.'scalyn_ai_usage'],true)) {
			$session=($call['args'][0] ?? '') === $this->prefix.'scalyn_ai_sessions';
			$indexes=$session ? ['PRIMARY'=>[0,['uuid']],'owner_updated'=>[1,['owner','updated_at','uuid']],'expiry'=>[1,['expires_at','uuid']]] : ['PRIMARY'=>[0,['scope']],'expiry'=>[1,['expires_at','scope']]];
			$index=$indexes[$call['args'][1]];
			return array_map(fn($col,$n)=>['Column_name'=>$col,'Seq_in_index'=>$n+1,'Non_unique'=>$index[0],'Sub_part'=>null],$index[1],array_keys($index[1]));
		}
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_recovery_queue') {
			$indexes=['PRIMARY'=>[0,['logical_uuid']],'due_work'=>[1,['state','next_at','logical_uuid']],'expiry'=>[1,['expires_at','logical_uuid']],'created_at'=>[1,['created_at','logical_uuid']]];
			$index=$indexes[$call['args'][1]];
			return array_map(fn($col,$n)=>['Column_name'=>$col,'Seq_in_index'=>$n+1,'Non_unique'=>$index[0],'Sub_part'=>null],$index[1],array_keys($index[1]));
		}
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_submission_attempts') {
			$indexes=['PRIMARY'=>[0,['attempt_uuid']],'logical_number'=>[0,['logical_uuid','attempt_number']],'created_at'=>[1,['created_at','attempt_uuid']]];
			$index=$indexes[$call['args'][1]];
			return array_map(fn($col,$n)=>['Column_name'=>$col,'Seq_in_index'=>$n+1,'Non_unique'=>$index[0],'Sub_part'=>null],$index[1],array_keys($index[1]));
		}
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_deliverability_evidence') {
			$name=$call['args'][1] ?? '';
			$indexes=['PRIMARY'=>[0,['id']],'computation_uuid'=>[0,['computation_uuid']],'configuration_kind'=>[1,['configuration_id','provider','kind','id']],'created_at'=>[1,['created_at','id']]];
			$index=$indexes[$name];
			return array_map(fn($col,$n)=>['Column_name'=>$col,'Seq_in_index'=>$n+1,'Non_unique'=>$this->brokenDeliverabilityIndex ? 0 : $index[0],'Sub_part'=>null],$index[1],array_keys($index[1]));
		}
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_connection_evidence') {
			if (str_contains($query,'SHOW COLUMNS')) { return $this->missingFailureCategory ? [] : [['Field'=>'failure_category','Type'=>'varchar(32)','Null'=>'YES']]; }
			$primary=($call['args'][1] ?? '')==='PRIMARY';
			return array_map(fn($col,$n)=>['Column_name'=>$col,'Seq_in_index'=>$n+1,'Non_unique'=>$primary ? 0 : 1,'Sub_part'=>null],$primary ? ['configuration_id','provider'] : ['checked_at'],$primary ? [0,1] : [0]);
		}
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_mail_logs') {
			if (str_contains($query,'SHOW COLUMNS')) {
				return $this->missingMailRevision ? [] : array_merge([['Field'=>'configuration_id','Type'=>'char(36)','Null'=>'YES']], $this->missingFailureCategory ? [] : [['Field'=>'failure_category','Type'=>'varchar(32)','Null'=>'YES']]);
			}
			if (str_contains($query,'SHOW INDEX') && ($call['args'][1] ?? '') === 'configuration_created') {
				return array_map(fn($column,$n)=>['Column_name'=>$column,'Seq_in_index'=>$n+1,'Non_unique'=>$this->brokenMailIndex ? 0 : 1,'Sub_part'=>null],['configuration_id','created_at','id'],[0,1,2]);
			}
		}
		$d=$this->definition();
		if (!$d) { return parent::get_results($query,$output); }
		$call=end($this->prepare_calls); $index=$d['indexes'][$call['args'][1]] ?? [1,['retired_at','key_version']];
		$rows=[];
		foreach ($index[1] as $n=>$col) { $rows[]=['Non_unique'=>$this->brokenIndex ? 1 : $index[0], 'Seq_in_index'=>$n+1,'Column_name'=>$col,'Sub_part'=>null]; }
		return $rows;
	}
}
