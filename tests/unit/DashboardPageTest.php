<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\DashboardPage;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Logging\MailLogRepository;

/**
 * Tests for DashboardPage.
 *
 * Covers: capability gate, empty state, accepted/failed status rendering,
 * UUID validation, privacy invariants, output escaping, provider regression,
 * Email Health invariant, and bounded repository query.
 *
 * Repository interaction is driven through WpdbStub configured per test.
 * Output is captured via output buffering so HTML assertions can be made
 * without coupling to full document structure.
 *
 * Privacy invariants tested:
 *  - response_message is never echoed.
 *  - event_data does not exist in mail log rows and cannot appear.
 *  - "Delivered" / "delivered" terminology never appears when status is accepted.
 *  - XSS payloads in provider/source fields are HTML-escaped.
 *  - No raw UUID is echoed without format validation.
 */
final class DashboardPageTest extends TestCase {

	private WpdbStub $wpdb;

	private array $mock_diagnostic_data = array();

	protected function setUp(): void {
		$this->wpdb                        = new WpdbStub();
		$GLOBALS['wpdb']                   = $this->wpdb;
		$GLOBALS['_test_current_user_can'] = array();
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$this->reset_plugin_singleton();
		$this->setup_wpdb_for_diagnostics();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$this->reset_plugin_singleton();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function reset_plugin_singleton(): void {
		$prop = new \ReflectionProperty( Plugin::class, 'instance' );
		$prop->setValue( null, null );
	}

	/** Boots the plugin singleton. Must be called before DashboardPage::render(). */
	private function boot_plugin(): void {
		Plugin::instance()->boot();
	}

	/** Boots the plugin and returns a new DashboardPage instance. */
	private function make_page(): DashboardPage {
		$this->boot_plugin();
		return new DashboardPage();
	}

	/** Grants VIEW_DASHBOARD capability for the current test. */
	private function grant_view_dashboard(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::VIEW_DASHBOARD ] = true;
	}

	/** Renders the dashboard and returns captured HTML output. */
	private function render_and_capture(): string {
		ob_start();
		$this->make_page()->render();
		return (string) ob_get_clean();
	}

	/** Set mock diagnostic data for find_latest_run() to return. */
	public function set_mock_diagnostic_data( array $data ): void {
		$this->mock_diagnostic_data = $data;
	}

	/** Get mock diagnostic data. */
	public function get_mock_diagnostic_data(): array {
		return $this->mock_diagnostic_data;
	}

	/** Setup wpdb mock to handle diagnostic queries with get_var/get_results. */
	private function setup_wpdb_for_diagnostics(): void {
		global $wpdb;
		$self = $this;

		// Create a custom wpdb that wraps WpdbStub but overrides get_var/get_results for diagnostics.
		$original_wpdb = $this->wpdb;
		$wpdb          = new class( $self, $original_wpdb ) {
			public $prefix = 'wp_';
			private $parent;
			private $original_wpdb;

			public function __construct( $parent, $original_wpdb ) {
				$this->parent        = $parent;
				$this->original_wpdb = $original_wpdb;
			}

			public function prepare( string $query, ...$args ) {
				return $this->original_wpdb->prepare( $query, ...$args );
			}

			public function get_var( string $sql ) {
				// For diagnostic queries, return a UUID if data exists, null otherwise.
				$data = $this->parent->get_mock_diagnostic_data();
				if ( ! empty( $data ) ) {
					return 'latest-uuid';
				}
				// If no diagnostic mock data, delegate to original wpdb.
				return $this->original_wpdb->get_var( $sql );
			}

			public function get_results( string $sql, string $output = 'OBJECT' ) {
				// Check if this is a diagnostic query by checking if diagnostics table is referenced.
				if ( strpos( $sql, 'scalyn_diagnostics' ) !== false ) {
					// This is a diagnostic query, use mock data.
					return $this->parent->get_mock_diagnostic_data();
				}
				// For other queries (like mail logs), delegate to original wpdb.
				return $this->original_wpdb->get_results( $sql, $output );
			}

			public function get_row( string $sql, string $output = 'OBJECT' ) {
				return $this->original_wpdb->get_row( $sql, $output );
			}

			public function insert( string $table, array $data, mixed $format = null ): int|false {
				return $this->original_wpdb->insert( $table, $data, $format );
			}

			public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
				return $this->original_wpdb->update( $table, $data, $where, $format, $where_format );
			}
		};
	}

	/**
	 * Returns a minimal mail log row for test fixtures.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return array<string, mixed>
	 */
	private function make_log_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => '1',
				'message_uuid'     => '550e8400-e29b-41d4-a716-446655440000',
				'mailer'           => '',
				'provider'         => 'smtp',
				'status'           => 'accepted',
				'source_type'      => '',
				'source_name'      => '',
				'response_code'    => '',
				'response_message' => 'SENSITIVE RESPONSE DATA',
				'attachment_count' => '0',
				'retry_count'      => '0',
				'created_at'       => '2026-08-24 10:00:00',
				'sent_at'          => '2026-08-24 10:00:00',
				'failed_at'        => null,
			),
			$overrides
		);
	}

	// =========================================================================
	// CAPABILITY GATE
	// =========================================================================

	public function test_render_rejects_user_without_view_dashboard(): void {
		$this->expectException( RuntimeException::class );
		( new DashboardPage() )->render();
	}

	public function test_render_rejects_user_with_only_manage_settings(): void {
		$GLOBALS['_test_current_user_can'][ Capabilities::MANAGE_SETTINGS ] = true;

		$this->expectException( RuntimeException::class );
		( new DashboardPage() )->render();
	}

	// =========================================================================
	// EMPTY STATE — no mail records
	// =========================================================================

	public function test_activity_section_shows_empty_state_when_no_logs(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'No email activity has been recorded yet', $output );
	}

	public function test_activity_section_has_no_timeline_link_when_no_logs(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'View Timeline', $output );
		$this->assertStringNotContainsString( 'message_uuid', $output );
	}

	public function test_activity_section_includes_logs_navigation_in_empty_state(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'scalyn-mail-relay-logs', $output );
	}

	public function test_accepted_disclaimer_is_absent_for_empty_state(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'does not guarantee inbox delivery', $output );
	}

	// =========================================================================
	// ACCEPTED STATUS
	// =========================================================================

	public function test_accepted_status_renders_accepted_label(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'status' => 'accepted' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Accepted', $output );
	}

	public function test_accepted_status_does_not_render_delivered(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'status' => 'accepted' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'Delivered', $output );
		$this->assertStringNotContainsString( 'delivered', $output );
		$this->assertStringNotContainsString( 'Delivery Confirmed', $output );
		$this->assertStringNotContainsString( 'Successfully Delivered', $output );
	}

	public function test_accepted_disclaimer_is_present_for_accepted_status(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'status' => 'accepted' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'does not guarantee inbox delivery', $output );
	}

	public function test_accepted_with_valid_uuid_renders_timeline_link(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row() );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'View Timeline', $output );
		$this->assertStringContainsString( 'message_uuid=550e8400-e29b-41d4-a716-446655440000', $output );
	}

	public function test_accepted_provider_field_renders_in_output(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'provider' => 'smtp' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'smtp', $output );
	}

	public function test_accepted_timestamp_renders_in_output(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'created_at' => '2026-08-24 10:00:00' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( '2026-08-24 10:00:00', $output );
	}

	// =========================================================================
	// FAILED STATUS
	// =========================================================================

	public function test_failed_status_renders_failed_label(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row(
				array(
					'status'    => 'failed',
					'sent_at'   => null,
					'failed_at' => '2026-08-24 10:00:00',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Failed', $output );
	}

	public function test_failed_with_valid_uuid_renders_timeline_link(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row(
				array(
					'status'    => 'failed',
					'sent_at'   => null,
					'failed_at' => '2026-08-24 10:00:00',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'View Timeline', $output );
		$this->assertStringContainsString( 'message_uuid=550e8400-e29b-41d4-a716-446655440000', $output );
	}

	public function test_accepted_disclaimer_is_absent_for_failed_status(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row(
				array(
					'status'    => 'failed',
					'sent_at'   => null,
					'failed_at' => '2026-08-24 10:00:00',
				)
			),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'does not guarantee inbox delivery', $output );
	}

	// =========================================================================
	// UNKNOWN / INTERMEDIATE STATUS
	// =========================================================================

	public function test_unknown_log_status_renders_safely(): void {
		$this->grant_view_dashboard();
		// 'generated' is a canonical but non-terminal status; the Dashboard only
		// defines display labels for 'accepted' and 'failed'.
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'status' => 'generated' ) ),
		);

		$output = $this->render_and_capture();

		// Status badge must not show the accepted or failed class for an intermediate status.
		$this->assertStringNotContainsString( 'scalyn-badge--accepted', $output );
		$this->assertStringNotContainsString( 'scalyn-badge--failed', $output );
		// The accepted disclaimer must not appear for a non-accepted status.
		$this->assertStringNotContainsString( 'does not guarantee inbox delivery', $output );
		// The activity section must still render (a log record exists).
		$this->assertStringContainsString( 'Recent Email Activity', $output );
	}

	// =========================================================================
	// UUID VALIDATION
	// =========================================================================

	public function test_valid_uuid_generates_timeline_navigation_url(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'message_uuid' => '550e8400-e29b-41d4-a716-446655440000' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'scalyn-mail-relay-logs', $output );
		$this->assertStringContainsString( 'message_uuid=550e8400-e29b-41d4-a716-446655440000', $output );
	}

	public function test_malformed_uuid_does_not_generate_timeline_link(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'message_uuid' => 'not-a-valid-uuid' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'View Timeline', $output );
		$this->assertStringNotContainsString( 'message_uuid=not-a-valid-uuid', $output );
	}

	public function test_empty_uuid_does_not_generate_timeline_link(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'message_uuid' => '' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'View Timeline', $output );
	}

	public function test_uuid_with_sql_injection_attempt_does_not_generate_timeline_link(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'message_uuid' => "1' OR '1'='1" ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'View Timeline', $output );
	}

	// =========================================================================
	// PRIVACY — sensitive fields must not appear in output
	// =========================================================================

	public function test_response_message_is_not_rendered_in_output(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'response_message' => 'SENSITIVE RESPONSE DATA' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'SENSITIVE RESPONSE DATA', $output );
	}

	public function test_delivered_terminology_is_absent_from_accepted_output(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row( array( 'status' => 'accepted' ) ) );

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( 'Delivered', $output );
		$this->assertStringNotContainsString( 'delivered', $output );
	}

	// =========================================================================
	// OUTPUT ESCAPING
	// =========================================================================

	public function test_xss_in_provider_field_is_escaped(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'provider' => '<script>alert(1)</script>' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	public function test_xss_in_source_type_field_is_escaped(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'source_type' => '<img src=x onerror=alert(1)>' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringContainsString( '&lt;img', $output );
	}

	public function test_xss_in_source_name_field_is_escaped(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'source_name' => '"><script>alert(2)</script>' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<script>alert(2)', $output );
	}

	public function test_xss_in_created_at_field_is_escaped(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array(
			$this->make_log_row( array( 'created_at' => '"><script>xss</script>' ) ),
		);

		$output = $this->render_and_capture();

		$this->assertStringNotContainsString( '<script>xss', $output );
	}

	// =========================================================================
	// EMAIL HEALTH — must remain unknown/unscored
	// =========================================================================

	public function test_email_health_section_is_present(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Email Health', $output );
	}

	public function test_email_health_shows_unknown_status_badge(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Unknown', $output );
		$this->assertStringContainsString( 'scalyn-badge--unknown', $output );
	}

	public function test_email_health_has_no_fabricated_score_when_logs_present(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row() );

		$output = $this->render_and_capture();

		// The health score placeholder must remain as '—'; no numeric score.
		$this->assertStringContainsString( 'scalyn-score', $output );
		$this->assertStringNotContainsString( 'scalyn-badge--healthy', $output );
		$this->assertStringNotContainsString( 'scalyn-badge--critical', $output );
	}

	/**
	 * The Dashboard reads the persisted HealthScorer snapshot (scalyn_health_scores),
	 * the same source as the Diagnostics page — never a per-row average of
	 * scalyn_diagnostics, which no check populates.
	 */
	private function store_health_row( int $overall, ?int $dns, ?int $provider, ?int $failure, string $summary ): void {
		$this->wpdb->get_row_return = array(
			'overall_score'  => $overall,
			'dns_score'      => $dns,
			'provider_score' => $provider,
			'failure_score'  => $failure,
			'summary'        => $summary,
			'created_at'     => '2026-09-04 10:00:00',
		);
	}

	public function test_email_health_displays_healthy_score(): void {
		$this->grant_view_dashboard();
		$this->store_health_row( 88, 88, null, null, 'Health score based on: DNS & authentication.' );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'scalyn-badge--healthy', $output );
		$this->assertStringContainsString( '88/100', $output );
	}

	public function test_email_health_displays_warning_score(): void {
		$this->grant_view_dashboard();
		$this->store_health_row( 68, 68, null, null, 'Health score based on: DNS & authentication.' );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'scalyn-badge--warning', $output );
		$this->assertStringContainsString( '68/100', $output );
	}

	public function test_email_health_displays_critical_score(): void {
		$this->grant_view_dashboard();
		$this->store_health_row( 45, 45, null, null, 'Health score based on: DNS & authentication.' );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'scalyn-badge--critical', $output );
		$this->assertStringContainsString( '45/100', $output );
	}

	public function test_email_health_ignores_diagnostic_row_scores(): void {
		$this->grant_view_dashboard();

		// Diagnostic rows exist (with legacy per-row scores) but no HealthScorer
		// snapshot has been persisted: the Dashboard must show Unknown, exactly
		// like the Diagnostics page, instead of averaging the rows itself.
		$this->set_mock_diagnostic_data(
			array(
				array(
					'check_name' => 'spf_record',
					'status'     => 'pass',
					'score'      => 100,
					'raw_result' => '{}',
				),
			)
		);

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'scalyn-badge--unknown', $output );
		$this->assertStringNotContainsString( '/100', $output );
	}

	public function test_email_health_explains_which_components_have_evidence(): void {
		$this->grant_view_dashboard();
		$this->store_health_row( 100, null, null, 100, 'Health score based on: Operational reliability.' );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( '100/100', $output );
		$this->assertStringContainsString( 'DNS &amp; authentication', $output );
		$this->assertStringContainsString( 'Not evaluated', $output );
		$this->assertStringContainsString( 'Health score based on: Operational reliability.', $output );
		$this->assertStringNotContainsString( 'based on SPF, DKIM, DMARC configuration', $output );
	}

	// =========================================================================
	// PROVIDER SECTION REGRESSION
	// =========================================================================

	public function test_unconfigured_provider_shows_not_configured_badge(): void {
		$this->grant_view_dashboard();
		// No options set → no active provider.
		$this->wpdb->get_results_return = array();

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'Not configured', $output );
		$this->assertStringContainsString( 'scalyn-badge--disconnected', $output );
	}

	public function test_configured_provider_shows_configured_badge(): void {
		$this->grant_view_dashboard();
		$GLOBALS['_test_wp_options'][ SettingsRepository::OPTION_KEY ] = array(
			'provider' => array( 'active' => 'test-provider' ),
		);
		$this->wpdb->get_results_return                                = array();
		$this->boot_plugin();

		// Register a minimal provider so ProviderRegistry::has() returns true.
		Plugin::instance()->container()->get( ProviderRegistry::class )->register(
			new class() implements ProviderInterface {
				public function get_id(): string {
					return 'test-provider';
				}
				public function get_label(): string {
					return 'Test Provider';
				}
				public function validate_config( array $config ): \Scalyn\MailRelay\Providers\ValidationResult {
					throw new \LogicException( 'Not called in this test.' );
				}
				public function test_connection( array $config ): \Scalyn\MailRelay\Providers\ConnectionResult {
					throw new \LogicException( 'Not called in this test.' );
				}
				public function send( \Scalyn\MailRelay\Mail\MailMessage $message, array $config ): \Scalyn\MailRelay\Mail\SendResult {
					throw new \LogicException( 'Not called in this test.' );
				}
				public function get_capabilities(): array {
					return array();
				}
			}
		);

		ob_start();
		( new DashboardPage() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Configured', $output );
		$this->assertStringContainsString( 'scalyn-badge--connected', $output );
	}

	// =========================================================================
	// REPOSITORY QUERY BOUND
	// =========================================================================

	public function test_find_recent_is_called_with_limit_1_and_offset_0(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array();

		$this->render_and_capture();

		// find_recent() calls prepare() with the limit and offset as args. Other
		// repositories (health score) also prepare queries, so select the mail-log one.
		$log_prepare = null;
		foreach ( $this->wpdb->prepare_calls as $call ) {
			if ( false !== strpos( $call['query'], 'scalyn_mail_logs' ) ) {
				$log_prepare = $call;
				break;
			}
		}
		$this->assertIsArray( $log_prepare, 'Dashboard must query mail logs through the repository.' );
		$this->assertSame( 1, $log_prepare['args'][0], 'Dashboard must request exactly 1 row.' );
		$this->assertSame( 0, $log_prepare['args'][1], 'Dashboard must use offset 0.' );
	}

	public function test_no_direct_sql_exists_in_dashboard_render_path(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row() );

		// DashboardPage::render() must only access $wpdb through the repository.
		// If prepare_calls is populated it means the query went through $wpdb->prepare().
		$this->render_and_capture();

		// The only tables the dashboard may read are the ones its repositories own:
		// mail logs (MailLogRepository) and the persisted health score (HealthScoreRepository).
		foreach ( $this->wpdb->prepare_calls as $call ) {
			$this->assertMatchesRegularExpression(
				'/scalyn_(mail_logs|health_scores)/',
				$call['query'],
				'All wpdb queries from render() must go through MailLogRepository or HealthScoreRepository.'
			);
		}
	}

	// =========================================================================
	// VIEW ALL LOGS NAVIGATION
	// =========================================================================

	public function test_view_all_logs_link_present_when_logs_exist(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row() );

		$output = $this->render_and_capture();

		$this->assertStringContainsString( 'View All Logs', $output );
		$this->assertStringContainsString( 'scalyn-mail-relay-logs', $output );
	}
}
