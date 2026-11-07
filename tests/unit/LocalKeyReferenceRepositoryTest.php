<?php
use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\LocalKeyReferenceRepository;

final class LocalReferenceWpdb extends WpdbStub {
	public string $last_error = '';
	public string $mode       = 'empty';
	public string $hit        = '';
	public string $options = 'wp_options';
	public array $stored = [];
	public function prepare( string $query, ...$args ): string {
		$this->prepare_calls[] = array(
			'query' => $query,
			'args'  => $args,
		);
		foreach ( $args as $arg ) {
			$query = preg_replace_callback( '/%[is]/', static fn( $m )=>$m[0] === '%i' ? '`' . $arg . '`' : "'" . $arg . "'", $query, 1 ); }
		return $query;
	}
	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
        if ($this->mode==='two-rows') {$this->last_result=[(object)['option_value'=>serialize([])],(object)['option_value'=>serialize([])]];return 2;}
        if ($this->mode==='negative-count') {$this->last_result=[];return -1;}
        if ($this->mode==='mismatch-count') {$this->last_result=[];return 1;}
        if ($this->mode==='extra-column') {$this->last_result=[(object)['option_value'=>serialize([]),'unexpected'=>1]];return 1;}
        if ($this->mode==='offset-option') {$this->last_result=[1=>(object)['option_value'=>serialize([])]];return 1;}
        if ($this->mode==='array-row') {$this->last_result=[['option_value'=>serialize([])]];return 1;}

		if ($this->mode === 'zero-error') {$this->last_error='0';$this->last_result=[];return 0;}
		if ($this->mode === 'offset-table' && !str_contains($sql,'SELECT option_value')) {$this->last_result=[1=>(object)['has_local_cipher'=>'1']];return 1;}
		if (!$this->errors_suppressed) {throw new LogicException('Unsuppressed read');}
		if ( $this->mode === 'false' ) {
			return false; }
		if ( $this->mode === 'throw' ) {
			throw new RuntimeException( 'private' ); }
		if ( str_contains($sql, 'SELECT option_value') ) {
			$this->last_result = [];
			foreach ($this->stored as $name => $value) {
				if (str_contains($sql, "'".$name."'")) {$this->last_result = [(object)['option_value'=>$value]]; return 1;}
			}
			return 0;
		}
		$this->last_error  = $this->mode === 'error' ? 'private' : '';
		$found             = $this->hit !== '' && str_contains( $sql, $this->hit );
		$this->last_result = $found ? array( (object) array( 'has_local_cipher' => '1' ) ) : array();
		if ( $this->mode === 'malformed' ) {
			$this->last_result = array( (object) array( 'other' => 1 ) );
			return 1; }
		return $found ? 1 : 0;
	}
}
final class LocalKeyReferenceRepositoryTest extends TestCase {
	private LocalReferenceWpdb $db;
	protected function setUp(): void {
		$GLOBALS['wpdb']             = $this->db = new LocalReferenceWpdb();
		$GLOBALS['_test_wp_options'] = array(); }
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] ); }
	public function test_false_requires_three_checked_empty_owned_table_reads(): void {
		$this->assertFalse( ( new LocalKeyReferenceRepository() )->has_references() );
		$this->assertCount( 8, $this->db->queries );
		foreach ( array( 'scalyn_recovery_queue', 'scalyn_delivery_keys', 'scalyn_ai_sessions' ) as $index => $table ) {
			$this->assertStringContainsString( $table, $this->db->queries[ $index + 5 ] );
			$this->assertStringContainsString( 'LIMIT 1', $this->db->queries[ $index + 5 ] );
			$this->assertStringContainsString( 'SELECT 1 AS has_local_cipher', $this->db->queries[ $index + 5 ] );
		}
		$this->assertFalse( $this->db->errors_suppressed );
	}
	/** @dataProvider stores */
	public function test_finds_local_ciphertext_in_every_retained_store( string $store ): void {
		if ( str_starts_with( $store, 'scalyn_mail_relay_' ) ) {
			$this->db->stored[ $store ] = serialize(array( 'archived' => array( 'disabled' => array( 'draft' => 'v2:synthetic' ) ) )); } else {
					$this->db->hit = $store; }
			$this->assertTrue( ( new LocalKeyReferenceRepository() )->has_references() );
	}
	public function test_cached_absence_does_not_hide_authoritative_option_ciphertext(): void {
        $GLOBALS['_test_wp_options'] = [];
        $this->db->stored['scalyn_mail_relay_settings'] = serialize(['retained'=>['cipher'=>'v2:synthetic']]);
        $this->assertTrue((new LocalKeyReferenceRepository())->has_references(), 'Cached absence is not authoritative absence.');
    }
    /** @dataProvider unsafeOptionRows */
    public function test_malformed_option_data_cannot_prove_absence($raw): void {
        $this->db->stored['scalyn_mail_relay_settings']=$raw;
        try {(new LocalKeyReferenceRepository())->has_references();$this->fail('Malformed option accepted');}
        catch (RuntimeException $error) {$this->assertNull($error->getPrevious());}
        $this->assertFalse($this->db->errors_suppressed);
    }
    public static function unsafeOptionRows(): array {
        $cycle=[];$cycle['self']=&$cycle;
        return [[null],[false],[42],[''],['not-serialized'],['b:0;'],['N;'],['s:3:"foo";'],['a:1:{'],[serialize([]).'trailing'],[serialize(['object'=>new stdClass()])],[serialize($cycle)]];
    }
    public function test_serialized_objects_never_instantiate(): void {
        $this->db->stored['scalyn_mail_relay_settings']='a:1:{s:6:"object";O:22:"LocalOptionWakeupProbe":0:{}}';
        $GLOBALS['_local_option_wakeup']=false;
        try {(new LocalKeyReferenceRepository())->has_references();$this->fail('Object accepted');}
        catch (RuntimeException $error) {$this->assertFalse($GLOBALS['_local_option_wakeup']);}
        finally {unset($GLOBALS['_local_option_wakeup']);}
    }
    public function test_fixed_options_are_prepared_and_all_reads_start_suppressed(): void {
        $this->assertFalse((new LocalKeyReferenceRepository())->has_references());
        $this->assertSame(['wp_options','scalyn_mail_relay_settings'],$this->db->prepare_calls[0]['args']);
        $this->assertStringContainsString('LIMIT 2',$this->db->queries[0]);
    }
	public static function stores(): array {
		return array_map( static fn( $s )=>array( $s ), array( 'scalyn_mail_relay_settings', 'scalyn_mail_relay_postmark_webhook', 'scalyn_mail_relay_smtp2go_webhook', 'scalyn_mail_relay_brevo_webhook', 'scalyn_mail_relay_ai', 'scalyn_recovery_queue', 'scalyn_delivery_keys', 'scalyn_ai_sessions' ) ); }
	/** @dataProvider failures */
	public function test_unknown_database_state_throws_private_error_and_restores_policy( string $mode, bool $suppressed ): void {
		$this->db->mode              = $mode;
		$this->db->errors_suppressed = $suppressed;
		try {
			( new LocalKeyReferenceRepository() )->has_references();
			$this->fail( 'Unknown treated as empty' ); } catch ( RuntimeException $e ) {
					$this->assertSame( 'Secure credential storage is unavailable. Restore the site key or check private storage permissions.', $e->getMessage() );
					$this->assertNull( $e->getPrevious() ); }
			$this->assertSame( $suppressed, $this->db->errors_suppressed );
	}
	public static function failures(): array {
		$cases = array();
		foreach ( array( 'false', 'error', 'throw', 'malformed', 'zero-error', 'offset-table', 'two-rows', 'negative-count', 'mismatch-count', 'extra-column', 'offset-option', 'array-row' ) as $mode ) {
			foreach ( array( false, true ) as $suppressed ) {
				$cases[] = array( $mode, $suppressed );
			}
		} return $cases; }
}

final class LocalOptionWakeupProbe {
    public function __wakeup(): void {$GLOBALS['_local_option_wakeup']=true;}
}
