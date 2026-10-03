<?php

use Scalyn\MailRelay\Database\DeliveryEvidenceSchema;

/** Simulates introspection only; real dbDelta integration is tested separately. */
class DeliverySchemaWpdbStub extends WpdbStub {
	public string $engine = 'InnoDB';
	public bool $brokenIndex = false;
	public bool $missingColumn = false;
	public bool $missingRetirement = false;
	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
	private function definition(): ?array {
		$call=end($this->prepare_calls);
		return DeliveryEvidenceSchema::definitions()[substr($call['args'][0] ?? '',strlen($this->prefix))] ?? null;
	}
	public function get_var(string $query): mixed {
		return $this->definition() ? $this->engine : parent::get_var($query);
	}
	public function get_col(string $query): array {
		$d=$this->definition();
		return $d ? ($this->missingColumn ? [] : array_merge($d['columns'], $this->missingRetirement ? [] : ['retired_at'])) : parent::get_col($query);
	}
	public function get_results(string $query,string $output=OBJECT): array {
		$d=$this->definition();
		if (!$d) { return parent::get_results($query,$output); }
		$call=end($this->prepare_calls); $index=$d['indexes'][$call['args'][1]] ?? [1,['retired_at','key_version']];
		$rows=[];
		foreach ($index[1] as $n=>$col) { $rows[]=['Non_unique'=>$this->brokenIndex ? 1 : $index[0], 'Seq_in_index'=>$n+1,'Column_name'=>$col,'Sub_part'=>null]; }
		return $rows;
	}
}
