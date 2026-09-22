<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Contracts\DiagnosticCheckInterface;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;
use Scalyn\MailRelay\Diagnostics\DiagnosticContext;
use Scalyn\MailRelay\Diagnostics\DiagnosticResult;
use Scalyn\MailRelay\Rest\DiagnosticsRunEndpoint;

/**
 * Integration tests for the Diagnostics REST endpoint.
 *
 * Verifies that the endpoint is properly registered and executes diagnostic
 * checks when called with proper authentication.
 */
final class DiagnosticsEndpointTest extends TestCase {

	public function test_diagnostic_audit_records_one_run_without_evidence_payload(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		$events = array();
		$GLOBALS['_test_wp_actions'][\Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT] = static function($event) use (&$events) { $events[] = $event; };
		(new DiagnosticsRunEndpoint())->handle_request();
		$this->assertSame(array('started','completed'), array_column($events,'outcome'));
		$this->assertSame($events[0]->correlation_id,$events[1]->correlation_id);
		$this->assertStringNotContainsString('raw_result',json_encode($events));
	}

	public function test_failed_diagnostics_records_failure_with_same_run_uuid(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		$GLOBALS['wpdb'] = new WpdbStub();
		$GLOBALS['wpdb']->return_false_on_insert = true;
		$GLOBALS['wpdb']->get_var_return = '1';
		$events = array();
		$GLOBALS['_test_wp_actions'][\Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT] = static function($event) use (&$events) { $events[] = $event; };
		(new DiagnosticsRunEndpoint())->handle_request();
		$this->assertSame(array('started','failed'), array_column($events,'outcome'));
		$this->assertSame($events[0]->correlation_id,$events[1]->correlation_id);
	}

	protected function setUp(): void {
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$GLOBALS['_test_current_user_can']       = array();
		$GLOBALS['_test_wp_options']             = array();
		$GLOBALS['_test_wp_actions']             = array();
		$GLOBALS['_test_wp_added_actions']       = array();
		$GLOBALS['_test_registered_rest_routes'] = array();
		$this->reset_plugin_singleton();
		$this->setup_wpdb_mock();
	}

	protected function tearDown(): void {
		$GLOBALS['_test_wp_option_write_failures'] = array();
		$this->reset_plugin_singleton();
	}

	private function reset_plugin_singleton(): void {
		$property = new ReflectionProperty( Plugin::class, 'instance' );
		$property->setValue( null, null );
	}

	public function test_busy_run_returns_conflict_without_executing_checks(): void {
		$this->boot_plugin();
		$spy = $this->install_context_spy();
		$GLOBALS['wpdb'] = new WpdbStub();
		$GLOBALS['wpdb']->get_var_return = '0';
		$response = (new DiagnosticsRunEndpoint())->handle_request();
		$this->assertSame(409, $response->get_status());
		$this->assertNull($spy->context);
		$this->assertArrayNotHasKey(\Scalyn\MailRelay\Database\DiagnosticRunStateRepository::OPTION_KEY,$GLOBALS['_test_wp_options']);
	}

	public function test_completed_state_tracks_the_published_uuid(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		$response=(new DiagnosticsRunEndpoint())->handle_request();
		$state=(new \Scalyn\MailRelay\Database\DiagnosticRunStateRepository())->get();
		$this->assertSame('completed',$state['latest']['state']);
		$this->assertSame($response->get_data()['results'][0]['diagnostic_uuid'],$state['latest']['uuid']);
		$this->assertSame($state['latest'],$state['last_success']);
	}

	public function test_context_failure_records_only_fixed_failure_code(): void {
		$GLOBALS['_test_wp_options'][SettingsRepository::OPTION_KEY]=array('smtp'=>'private-secret');
		$this->boot_plugin();
		$this->install_context_spy();
		$response=(new DiagnosticsRunEndpoint())->handle_request();
		$this->assertSame(500,$response->get_status());
		$state=(new \Scalyn\MailRelay\Database\DiagnosticRunStateRepository())->get();
		$this->assertSame('context_failed',$state['latest']['failure_code']);
		$this->assertStringNotContainsString('private-secret',json_encode($state).json_encode($response->get_data()));
	}

	public function test_check_budget_failure_has_terminal_state_without_publication(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		$registry=Plugin::instance()->container()->get(DiagnosticCheckRegistry::class);
		foreach(range(1,21) as $id){
			$registry->register(new class((string)$id) implements DiagnosticCheckInterface {
				public function __construct(private string $id){}
				public function get_id(): string{return $this->id;}
				public function get_category(): string{return 'dns';}
				public function run(DiagnosticContext $context): DiagnosticResult{throw new RuntimeException('Must not execute');}
			});
		}
		$this->assertSame(500,(new DiagnosticsRunEndpoint())->handle_request()->get_status());
		$state=(new \Scalyn\MailRelay\Database\DiagnosticRunStateRepository())->get();
		$this->assertSame('checks_failed',$state['latest']['failure_code']);
		$this->assertGreaterThanOrEqual($state['latest']['started_at'],$state['latest']['finished_at']);
		$this->assertSame(array(),$GLOBALS['wpdb']->queries);
	}

	public function test_start_status_failure_prevents_checks(): void {
		$this->boot_plugin();
		$spy=$this->install_context_spy();
		$GLOBALS['_test_wp_option_write_failures'][\Scalyn\MailRelay\Database\DiagnosticRunStateRepository::OPTION_KEY]=true;
		$this->assertSame(503,(new DiagnosticsRunEndpoint())->handle_request()->get_status());
		$this->assertNull($spy->context);
		$this->assertSame(array(),$GLOBALS['wpdb']->queries);
	}

	public function test_terminal_status_failure_does_not_relabel_committed_results(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		$GLOBALS['_test_wp_actions'][\Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT]=static function($event) {
			if ($event->outcome==='started') {
				$GLOBALS['_test_wp_option_write_failures'][\Scalyn\MailRelay\Database\DiagnosticRunStateRepository::OPTION_KEY]=true;
			}
		};
		$this->assertSame(200,(new DiagnosticsRunEndpoint())->handle_request()->get_status());
		$state=(new \Scalyn\MailRelay\Database\DiagnosticRunStateRepository())->get();
		$this->assertSame('running',$state['latest']['state']);
		$this->assertNull($state['last_success']);
		$this->assertSame('COMMIT',end($GLOBALS['wpdb']->queries));
	}

	public function test_throwing_check_is_published_as_error_alongside_remaining_checks(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		Plugin::instance()->container()->get(DiagnosticCheckRegistry::class)->register(
			new class implements DiagnosticCheckInterface {
				public function get_id(): string { return 'spf_record'; }
				public function get_category(): string { return 'dns'; }
				public function run(DiagnosticContext $context): DiagnosticResult { throw new RuntimeException('private-secret'); }
			}
		);
		$response = (new DiagnosticsRunEndpoint())->handle_request();
		$this->assertSame(200,$response->get_status());
		$rows = $response->get_data()['results'];
		$this->assertCount(5,$rows);
		$this->assertSame(1,count(array_filter($rows,static fn($row)=>$row['status']==='error')));
		$this->assertStringNotContainsString('private-secret',json_encode($response->get_data()));
		$this->assertSame(array('START TRANSACTION','COMMIT'),$GLOBALS['wpdb']->queries);
	}

	public function test_failed_run_releases_lock_and_retry_reads_its_own_uuid(): void {
		$this->boot_plugin();
		$this->install_context_spy();
		$db = new PublicationWpdbStub();
		$db->get_var_return = '1';
		$GLOBALS['wpdb'] = $db;
		$db->return_false_on_insert = true;
		$this->assertSame(500, (new DiagnosticsRunEndpoint())->handle_request()->get_status());
		$failed=(new \Scalyn\MailRelay\Database\DiagnosticRunStateRepository())->get()['latest'];
		$this->assertSame('failed',$failed['state']);
		$this->assertSame('publication_failed',$failed['failure_code']);
		$db->return_false_on_insert = false;
		$this->assertSame(200, (new DiagnosticsRunEndpoint())->handle_request()->get_status());
		$reads = array_values(array_filter($db->prepare_calls, static fn($call) => str_contains($call['query'],'WHERE diagnostic_uuid =')));
		$inserts = array_values(array_filter($db->inserts, static fn($call) => str_ends_with($call['table'],'scalyn_diagnostics')));
		$this->assertNotEmpty($reads);
		$this->assertSame(end($inserts)['data']['diagnostic_uuid'],end($reads)['args'][0]);
		$releases = array_filter($db->prepare_calls, static fn($call) => str_contains($call['query'],'RELEASE_LOCK'));
		$this->assertCount(2,$releases);
	}

	public function test_scheduled_adapter_uses_the_same_checks_and_audit_context(): void {
		$this->boot_plugin();
		$spy = $this->install_context_spy();
		(new SettingsRepository())->save(array('advanced'=>array('diagnostic_schedule'=>'daily')));
		$GLOBALS['_test_doing_cron'] = true;
		$events = array();
		$GLOBALS['_test_wp_actions'][\Scalyn\MailRelay\Core\HookNames::AUDIT_EVENT] = static function($event) use (&$events) { $events[] = $event; };
		try {
			(new \Scalyn\MailRelay\Core\DiagnosticSchedule())->run();
			$this->assertNotNull($spy->context);
			$this->assertSame(array('started','completed'), array_column($events,'outcome'));
			$this->assertSame('scheduled', $events[0]->actor->source);
			$this->assertSame(0, $events[0]->actor->user_id);
		} finally {
			$GLOBALS['_test_doing_cron'] = false;
		}
	}

	private function setup_wpdb_mock(): void {
		$GLOBALS['wpdb'] = new PublicationWpdbStub();
		$GLOBALS['wpdb']->get_var_return = '1';
	}

	public function test_endpoint_is_registered(): void {
		$this->boot_plugin();

		$this->assertNotEmpty( $GLOBALS['_test_registered_rest_routes'] );

		$routes = $GLOBALS['_test_registered_rest_routes'];
		$found  = false;

		foreach ( $routes as $route ) {
			if ( 'scalyn-mail-relay/v1' === $route['namespace'] && '/diagnostics/run' === $route['route'] ) {
				$found = true;
				$this->assertArrayHasKey( 'methods', $route['args'] );
				$this->assertArrayHasKey( 'callback', $route['args'] );
				$this->assertArrayHasKey( 'permission_callback', $route['args'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Diagnostics endpoint was not registered' );
	}

	public function test_endpoint_requires_permission(): void {
		$this->boot_plugin();

		// Get the endpoint from registered routes
		$endpoint = null;
		foreach ( $GLOBALS['_test_registered_rest_routes'] as $route ) {
			if ( 'scalyn-mail-relay/v1' === $route['namespace'] && '/diagnostics/run' === $route['route'] ) {
				$endpoint = $route['args'];
				break;
			}
		}

		$this->assertNotNull( $endpoint );

		// Create a request object
		$request = new WP_REST_Request();

		// Permission should be denied when user doesn't have capability
		$permission_callback = $endpoint['permission_callback'];
		$this->assertFalse( $permission_callback( $request ) );

		// Permission should be granted when user has capability
		$GLOBALS['_test_current_user_can'][ Capabilities::RUN_DIAGNOSTICS ] = true;
		$this->assertTrue( $permission_callback( $request ) );
	}

	public function test_diagnostic_checks_are_registered(): void {
		$this->boot_plugin();

		$container = Plugin::instance()->container();
		$registry  = $container->get( DiagnosticCheckRegistry::class );

		// Verify all expected checks are registered
		$this->assertTrue( $registry->has( 'spf_record' ), 'SPF check not registered' );
		$this->assertTrue( $registry->has( 'mx_record' ), 'MX check not registered' );
		$this->assertTrue( $registry->has( 'dkim_record' ), 'DKIM check not registered' );
		$this->assertTrue( $registry->has( 'dmarc_policy' ), 'DMARC check not registered' );
		$this->assertTrue( $registry->has( 'smtp_tls' ), 'SMTP/TLS check not registered' );
	}

	// -------------------------------------------------------------------------
	// handle_request() — context handed to checks
	// -------------------------------------------------------------------------

	/**
	 * Replaces every real check in the booted registry with a context-capturing
	 * spy of the same id, so handle_request() performs no DNS lookups or socket
	 * probes. (The container caches resolved services, so the registry instance
	 * itself cannot be swapped after boot; DiagnosticCheckRegistry::register()
	 * overwrites by id, which is what we rely on here.)
	 *
	 * @return object The spy registered under the 'smtp_tls' id; all spies receive the same context.
	 */
	private function install_context_spy(): object {
		$make_spy = static function ( string $id ): DiagnosticCheckInterface {
			return new class( $id ) implements DiagnosticCheckInterface {
				public ?DiagnosticContext $context = null;

				public function __construct( private string $id ) {}

				public function get_id(): string {
					return $this->id;
				}

				public function get_category(): string {
					return 'smtp_tls' === $this->id ? 'smtp' : 'dns';
				}

				public function run( DiagnosticContext $context ): DiagnosticResult {
					$this->context = $context;
					return new DiagnosticResult( status: 'pass', severity: 'low', message: 'ok' );
				}
			};
		};

		$registry = Plugin::instance()->container()->get( DiagnosticCheckRegistry::class );
		$spy      = null;
		foreach ( array_keys( $registry->get_all() ) as $id ) {
			$replacement = $make_spy( $id );
			$registry->register( $replacement );
			if ( 'smtp_tls' === $id ) {
				$spy = $replacement;
			}
		}

		$this->assertNotNull( $spy, 'The smtp_tls check must be registered at boot.' );

		return $spy;
	}

	/**
	 * Regression guard for the QA report: SmtpTlsCheck always reported "no valid
	 * SMTP host and port are configured" because the endpoint built a context
	 * with no settings. Checks must now receive host/port/encryption.
	 */
	public function test_handle_request_passes_smtp_host_port_and_encryption_to_checks(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array(
				'host'       => 'smtp.example.org',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => 'mailer@example.org',
				'password'   => 'super-secret-password',
				'from_email' => 'noreply@example.org',
			),
		);
		$GLOBALS['_test_current_user_can'][ Capabilities::RUN_DIAGNOSTICS ] = true;
		$this->boot_plugin();
		$spy = $this->install_context_spy();

		( new DiagnosticsRunEndpoint() )->handle_request();

		$this->assertNotNull( $spy->context, 'The check must have been executed.' );
		$this->assertSame( 'smtp.example.org', $spy->context->settings['host'] );
		$this->assertSame( 587, $spy->context->settings['port'] );
		$this->assertSame( 'tls', $spy->context->settings['encryption'] );
	}

	public function test_handle_request_context_never_contains_credentials(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'smtp' ),
			'smtp'     => array(
				'host'       => 'smtp.example.org',
				'port'       => 587,
				'username'   => 'mailer@example.org',
				'password'   => 'super-secret-password',
				'from_email' => 'noreply@example.org',
			),
		);
		$this->boot_plugin();
		$spy = $this->install_context_spy();

		( new DiagnosticsRunEndpoint() )->handle_request();

		$this->assertNotNull( $spy->context );
		$this->assertArrayNotHasKey( 'username', $spy->context->settings );
		$this->assertArrayNotHasKey( 'password', $spy->context->settings );
		$this->assertStringNotContainsString( 'super-secret-password', (string) json_encode( $spy->context->settings ) );
	}

	public function test_handle_request_falls_back_to_site_host_when_no_from_address(): void {
		// No From address configured: fall back to the site host (home_url stub = example.com).
		$this->boot_plugin();
		$spy = $this->install_context_spy();

		( new DiagnosticsRunEndpoint() )->handle_request();

		$this->assertNotNull( $spy->context );
		$this->assertSame( 'example.com', $spy->context->domain );
	}

	public function test_handle_request_targets_from_email_domain(): void {
		// From address configured: DNS checks target the sending domain, not the site host.
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'smtp' => array( 'from_email' => 'noreply@sender.example.org' ),
		);
		$this->boot_plugin();
		$spy = $this->install_context_spy();

		( new DiagnosticsRunEndpoint() )->handle_request();

		$this->assertNotNull( $spy->context );
		$this->assertSame( 'sender.example.org', $spy->context->domain );
	}

	private function boot_plugin(): void {
		Plugin::instance()->boot();
		do_action( 'rest_api_init' );
	}
}
