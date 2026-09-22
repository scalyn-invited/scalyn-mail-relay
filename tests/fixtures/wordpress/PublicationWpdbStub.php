<?php

/** Stateful publication fixture; rolls back pending inserts without changing earlier rows. */
class PublicationWpdbStub extends WpdbStub {
	public string $last_error = '';
	public int $engines = 2;
	public int $fail_insert_at = 0;
	public int $attempts = 0;
	public bool $fail_read = false;
	public bool $fail_counts = false;
	private int $savepoint = 0;
	private string $read_uuid = '';

	public function get_var(string $query): mixed {
		if (str_contains($query, 'information_schema.TABLES')) { return $this->engines; }
		return parent::get_var($query);
	}
	public function query(string $sql): int|false {
		$result = parent::query($sql);
		if ($sql === 'START TRANSACTION' && $result !== false) { $this->savepoint = count($this->inserts); }
		if ($sql === 'ROLLBACK') { $this->inserts = array_slice($this->inserts, 0, $this->savepoint); }
		return $result;
	}
	public function insert(string $table,array $data,mixed $format=null): int|false {
		++$this->attempts;
		if ($this->attempts === $this->fail_insert_at) { return false; }
		return parent::insert($table,$data,$format);
	}
	public function prepare(string $query,mixed ...$args): string {
		if (str_contains($query,'WHERE diagnostic_uuid =')) { $this->read_uuid = $args[0]; }
		return parent::prepare($query,...$args);
	}
	public function get_results(string $query,string $output=OBJECT): array {
		$this->last_error = '';
		if (str_contains($query,'WHERE diagnostic_uuid =')) {
			if ($this->fail_read) { $this->last_error = 'secret database error'; return array(); }
			return array_values(array_map(static fn($row)=>$row['data'],array_filter($this->inserts,fn($row)=>str_ends_with($row['table'],'scalyn_diagnostics') && $row['data']['diagnostic_uuid']===$this->read_uuid)));
		}
		if ($this->fail_counts) { $this->last_error = 'secret database error'; }
		return array();
	}
}
