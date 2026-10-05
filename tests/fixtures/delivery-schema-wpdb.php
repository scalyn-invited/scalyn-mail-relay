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
	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
	private function definition(): ?array {
		$call=end($this->prepare_calls);
		return DeliveryEvidenceSchema::definitions()[substr($call['args'][0] ?? '',strlen($this->prefix))] ?? null;
	}
	public function get_var(string $query): mixed {
		$call=end($this->prepare_calls);
		if (str_contains($query,'SELECT ENGINE') && in_array(substr($call['args'][0] ?? '',strlen($this->prefix)), ['scalyn_diagnostics','scalyn_health_scores','scalyn_mail_logs','scalyn_mail_timeline'],true)) { return 'InnoDB'; }
		return $this->definition() ? $this->engine : parent::get_var($query);
	}
	public function get_col(string $query): array {
		$call=end($this->prepare_calls);
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_connection_evidence') { return ['configuration_id','provider','status','started_at','checked_at']; }
		$d=$this->definition();
		return $d ? ($this->missingColumn ? [] : array_merge($d['columns'], $this->missingRetirement ? [] : ['retired_at'])) : parent::get_col($query);
	}
	public function get_results(string $query,string $output=OBJECT): array {
		$call=end($this->prepare_calls);
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_connection_evidence') {
			$primary=($call['args'][1] ?? '')==='PRIMARY';
			return array_map(fn($col,$n)=>['Column_name'=>$col,'Seq_in_index'=>$n+1,'Non_unique'=>$primary ? 0 : 1,'Sub_part'=>null],$primary ? ['configuration_id','provider'] : ['checked_at'],$primary ? [0,1] : [0]);
		}
		if (($call['args'][0] ?? '') === $this->prefix.'scalyn_mail_logs') {
			if (str_contains($query,'SHOW COLUMNS')) {
				return $this->missingMailRevision ? [] : [['Field'=>'configuration_id','Type'=>'char(36)','Null'=>'YES']];
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
