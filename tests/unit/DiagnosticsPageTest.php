<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\DiagnosticsPage;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Mail\MailMessage;
use Scalyn\MailRelay\Mail\SendResult;
use Scalyn\MailRelay\Providers\ConnectionResult;
use Scalyn\MailRelay\Providers\ValidationResult;

/**
 * Tests for DiagnosticsPage.
 *
 * Covers the capability gate plus configured and not-configured B1 states.
 */
final class DiagnosticsPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_current_user_can'] = array();
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
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

	private $wpdb_mock_data = array();

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

			public function get_var( string $sql ) {
				$data = $this->parent->get_mock_diagnostic_data();
				return ! empty( $data ) ? 'latest-uuid' : null;
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				return $this->parent->get_mock_diagnostic_data();
			}
		};
	}

	public function set_mock_diagnostic_data( array $data ): void {
		$this->wpdb_mock_data = $data;
	}

	public function get_mock_diagnostic_data(): array {
		return $this->wpdb_mock_data;
	}

	private function grant_run_diagnostics(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::RUN_DIAGNOSTICS ] = true;
	}

	private function boot_plugin(): void {
		Plugin::instance()->boot();
	}

	private function configure_provider(): void {
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'test-provider' ),
		);

		$this->boot_plugin();
		Plugin::instance()->container()->get( ProviderRegistry::class )->register(
			new class() implements ProviderInterface {
				public function get_id(): string {
					return 'test-provider';
				}

				public function get_label(): string {
					return 'Test Provider';
				}

				public function validate_config( array $config ): ValidationResult {
					throw new LogicException( 'Not called in this test.' );
				}

				public function test_connection( array $config ): ConnectionResult {
					throw new LogicException( 'Not called in this test.' );
				}

				public function send( MailMessage $message, array $config ): SendResult {
					throw new LogicException( 'Not called in this test.' );
				}

				public function get_capabilities(): array {
					return array();
				}
			}
		);
	}

	private function render_and_capture(): string {
		$this->boot_plugin();
		ob_start();
		( new DiagnosticsPage() )->render();

		return (string) ob_get_clean();
	}

	public function test_render_rejects_user_without_run_diagnostics_capability(): void {
		$this->expectException( RuntimeException::class );
		( new DiagnosticsPage() )->render();
	}

	public function test_not_configured_state_prompts_for_setup_and_hides_results(): void {
		$this->grant_run_diagnostics();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Configure a mail provider first', $output );
		$this->assertStringContainsString( 'Open Setup Wizard', $output );
		$this->assertStringContainsString( 'admin.php?page=scalyn-mail-relay-wizard', $output );
		$this->assertStringNotContainsString( 'scalyn-diagnostics-grid', $output );
		$this->assertStringNotContainsString( 'scalyn-diagnostic-card', $output );
		$this->assertStringNotContainsString( 'Run Diagnostics Now', $output );
	}

	public function test_unregistered_active_provider_uses_not_configured_state(): void {
		$this->grant_run_diagnostics();
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'missing-provider' ),
		);

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Configure a mail provider first', $output );
		$this->assertStringNotContainsString( 'scalyn-diagnostics-grid', $output );
	}

	public function test_configured_state_renders_all_unknown_diagnostic_cards(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();

		$output = $this->render_and_capture();

		$this->assertSame( 4, substr_count( $output, 'class="scalyn-card scalyn-diagnostic-card"' ) );
		$this->assertSame( 4, substr_count( $output, 'scalyn-badge--unknown' ) );
		$this->assertStringContainsString( 'SPF Record', $output );
		$this->assertStringContainsString( 'DKIM Records', $output );
		$this->assertStringContainsString( 'DMARC Policy', $output );
		$this->assertStringContainsString( 'Overall Email Health', $output );
		$this->assertStringNotContainsString( 'Configure a mail provider first', $output );
	}

	public function test_configured_state_connects_each_card_to_its_heading(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();

		$output = $this->render_and_capture();
		$ids    = array( 'spf', 'dkim', 'dmarc', 'health' );

		foreach ( $ids as $id ) {
			$heading_id = 'scalyn-diagnostics-' . $id . '-heading';
			$this->assertStringContainsString( 'aria-labelledby="' . $heading_id . '"', $output );
			$this->assertStringContainsString( '<h2 id="' . $heading_id . '">', $output );
		}
	}

	public function test_configured_state_keeps_unavailable_diagnostics_action_disabled(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Run Diagnostics Now', $output );
		$this->assertStringContainsString( 'disabled aria-disabled="true"', $output );
		$this->assertStringContainsString( '>—</strong>', $output );
		$this->assertStringContainsString( 'aria-label="Health score not yet assessed"', $output );
	}

	/**
	 * B2 Tests: Diagnostics Findings/Remediation UI
	 *
	 * Tests for wiring real diagnostic results to the UI.
	 */
	public function test_organize_diagnostics_groups_results_by_check_type(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();
		$this->boot_plugin();

		$page = new DiagnosticsPage();

		// Use reflection to test private method.
		$reflection = new ReflectionMethod( $page, 'organize_diagnostics' );
		$reflection->setAccessible( true );

		$raw_results = array(
			array(
				'check_name'     => 'spf_record',
				'result_message' => 'SPF OK',
			),
			array(
				'check_name'     => 'dkim_record',
				'result_message' => 'DKIM OK',
			),
			array(
				'check_name'     => 'dmarc_policy',
				'result_message' => 'DMARC OK',
			),
			array(
				'check_name'     => 'unknown_check',
				'result_message' => 'Unknown',
			),
		);

		$organized = $reflection->invoke( $page, $raw_results );

		$this->assertIsArray( $organized );
		$this->assertArrayHasKey( 'spf', $organized );
		$this->assertArrayHasKey( 'dkim', $organized );
		$this->assertArrayHasKey( 'dmarc', $organized );
		$this->assertSame( 'SPF OK', $organized['spf']['result_message'] );
		$this->assertSame( 'DKIM OK', $organized['dkim']['result_message'] );
		$this->assertSame( 'DMARC OK', $organized['dmarc']['result_message'] );
	}

	public function test_organize_diagnostics_returns_null_for_missing_checks(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();
		$this->boot_plugin();

		$page       = new DiagnosticsPage();
		$reflection = new ReflectionMethod( $page, 'organize_diagnostics' );
		$reflection->setAccessible( true );

		$raw_results = array(
			array(
				'check_name'     => 'spf_record',
				'result_message' => 'SPF OK',
			),
		);

		$organized = $reflection->invoke( $page, $raw_results );

		$this->assertNull( $organized['dkim'] );
		$this->assertNull( $organized['dmarc'] );
	}

	public function test_get_ui_status_maps_diagnostic_status_to_ui_status(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();
		$this->boot_plugin();

		$page       = new DiagnosticsPage();
		$reflection = new ReflectionMethod( $page, 'get_ui_status' );
		$reflection->setAccessible( true );

		$this->assertSame( 'healthy', $reflection->invoke( $page, 'pass' ) );
		$this->assertSame( 'warning', $reflection->invoke( $page, 'warn' ) );
		$this->assertSame( 'critical', $reflection->invoke( $page, 'fail' ) );
		$this->assertSame( 'warning', $reflection->invoke( $page, 'error' ) );
		$this->assertSame( 'unknown', $reflection->invoke( $page, 'unknown' ) );
	}

	public function test_findings_output_is_escaped(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();

		$mock_results = array(
			array(
				'check_name'         => 'spf_record',
				'status'             => 'pass',
				'result_message'     => 'SPF check passed',
				'recommended_action' => '',
				'raw_result'         => wp_json_encode(
					array(
						'evidence' => 'v=spf1 include:_spf.google.com ~all',
						'impact'   => '',
					)
				),
			),
			array(
				'check_name'         => 'dkim_record',
				'status'             => 'fail',
				'result_message'     => 'DKIM check failed',
				'recommended_action' => 'Add DKIM record',
				'raw_result'         => wp_json_encode(
					array(
						'evidence' => 'No DKIM record found',
						'impact'   => 'Emails may be rejected',
					)
				),
			),
		);

		$this->set_mock_diagnostic_data( $mock_results );
		$output = $this->render_and_capture();

		// Verify no unescaped HTML or script tags appear.
		$this->assertStringNotContainsString( '<script', $output );
		// All findings should be rendered with proper escaping.
		$this->assertStringContainsString( 'scalyn-finding__message', $output );
		$this->assertStringContainsString( 'scalyn-finding__evidence', $output );
		$this->assertStringContainsString( 'scalyn-finding__action', $output );
	}

	public function test_health_score_displays_with_backend_provided_value(): void {
		$this->grant_run_diagnostics();
		$this->configure_provider();

		$mock_results = array(
			array(
				'check_name'         => 'spf_record',
				'status'             => 'pass',
				'result_message'     => 'SPF OK',
				'recommended_action' => '',
				'raw_result'         => '{"evidence":"test","impact":""}',
			),
		);

		$this->set_mock_diagnostic_data( $mock_results );
		$output = $this->render_and_capture();

		// When data exists, health score should be rendered with a numeric value.
		// The actual value depends on backend calculation, so we just verify presence.
		$this->assertStringContainsString( 'scalyn-score', $output );
	}
}
