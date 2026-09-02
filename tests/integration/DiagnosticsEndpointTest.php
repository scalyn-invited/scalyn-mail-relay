<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;

/**
 * Integration tests for the Diagnostics REST endpoint.
 *
 * Verifies that the endpoint is properly registered and executes diagnostic
 * checks when called with proper authentication.
 */
final class DiagnosticsEndpointTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = array();
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
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

	private function boot_plugin(): void {
		Plugin::instance()->boot();
		do_action( 'rest_api_init' );
	}
}
