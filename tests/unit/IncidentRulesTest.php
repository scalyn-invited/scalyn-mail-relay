<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Scalyn\MailRelay\Alerts\IncidentRules;
use Scalyn\MailRelay\Alerts\ObservationRepository;

final class IncidentRulesTest extends TestCase {
	#[DataProvider('conditions')]
	public function test_conditions(array $mail, array $monitor, array $health, array $expected): void {
		$this->assertSame($expected, array_values((new IncidentRules())->evaluate($mail,$monitor,$health,100000)));
	}
	public static function conditions(): array {
		$fail = ['status'=>'failed','at'=>99999];
		$accepted = ['status'=>'accepted','at'=>99999];
		$scheduled = ['enabled'=>true,'due'=>100100,'interval'=>3600];
		return [
			'unknown'=>[[],[],[],[null,null,null]],
			'three failures'=>[[$fail,$fail,$fail],[],[],[true,null,null]],
			'two failures'=>[[$fail,$fail],[],[],[null,null,null]],
			'intervening acceptance'=>[[$fail,$accepted,$fail],[],[],[null,null,null]],
			'acceptance recovery'=>[[$accepted,$fail,$fail],[],[],[false,null,null]],
			'old acceptance'=>[[['status'=>'accepted','at'=>99099]],[],[],[null,null,null]],
			'future acceptance'=>[[['status'=>'accepted','at'=>100001]],[],[],[null,null,null]],
			'one stale failure'=>[[$fail,$fail,['status'=>'failed','at'=>99099]],[],[],[null,null,null]],
			'missing cron'=>[[],['enabled'=>true],[],[null,true,null]],
			'overdue cron'=>[[],['enabled'=>true,'due'=>99099],[],[null,true,null]],
			'boundary cron'=>[[],['enabled'=>true,'due'=>99100],[],[null,null,null]],
			'failed monitoring'=>[[],$scheduled+['run'=>['state'=>'failed']],[],[null,true,null]],
			'unconfirmed monitoring'=>[[],$scheduled+['run'=>['state'=>'running','started'=>99099]],[],[null,true,null]],
			'running monitoring'=>[[],$scheduled+['run'=>['state'=>'running','started'=>99999]],[],[null,null,null]],
			'completed monitoring'=>[[],$scheduled+['run'=>['state'=>'completed','finished'=>99999]],[],[null,false,null]],
			'old completion'=>[[],$scheduled+['run'=>['state'=>'completed','finished'=>95499]],[],[null,null,null]],
			'disabled is not recovery'=>[[],['enabled'=>false,'run'=>['state'=>'completed','finished'=>99999]],[],[null,null,null]],
			'low score'=>[[],[],['score'=>59,'at'=>99999],[null,null,true]],
			'zero score'=>[[],[],['score'=>0,'at'=>99999],[null,null,true]],
			'score sixty'=>[[],[],['score'=>60,'at'=>99999],[null,null,null]],
			'score seventyfour'=>[[],[],['score'=>74,'at'=>99999],[null,null,null]],
			'recovered score'=>[[],[],['score'=>75,'at'=>99999],[null,null,false]],
			'perfect score'=>[[],[],['score'=>100,'at'=>99999],[null,null,false]],
			'invalid score'=>[[],[],['score'=>101,'at'=>99999],[null,null,null]],
			'old score'=>[[],[],['score'=>100,'at'=>13599],[null,null,null]],
			'future score'=>[[],[],['score'=>100,'at'=>100001],[null,null,null]],
		];
	}
	public function test_database_dates_are_converted_from_site_timezone(): void {
		$this->assertSame(100000, ObservationRepository::epoch('1970-01-02 11:46:40',new DateTimeZone('Asia/Manila')));
		$this->assertSame(0, ObservationRepository::epoch('2026-02-31 00:00:00',new DateTimeZone('UTC')));
		$this->assertSame(0, ObservationRepository::epoch('',new DateTimeZone('UTC')));
	}
}
