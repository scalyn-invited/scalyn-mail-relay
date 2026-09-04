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

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can']       = array();
		$GLOBALS['_test_wp_options']             = array();
		$GLOBALS['_test_wp_actions']             = array();
		$GLOBALS['_test_wp_added_actions']       = array();
		$GLOBALS['_test_registered_rest_routes'] = array();
		$this->reset_plugin_singleton();
		$this->setup_wpdb_mock();
	}

	protected function tearDown(): void {
		$this->reset_plugin_singleton();
	}

	private function reset_plugin_singleton(): void {
		$property = new ReflectionProperty( Plugin::class, 'instance' );
		$property->setValue( null, null );
	}

	private function setup_wpdb_mock(): void {
		global $wpdb;
		$self = $this;
		$wpdb = new class( $self ) {
			public $prefix = 'wp_';
			private $parent;

			public function __construct( $parent ) {
				$this->parent = $parent;
			}

			public function prepare( string $query, ...$args ) {
				return $query;
			}

			public function insert( string $table, array $data, $format = null ) {
				return 1;
			}

			public function get_var( string $sql ) {
				return null;
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				return array();
			}
		};
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
