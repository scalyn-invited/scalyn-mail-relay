<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Admin\Pages\DashboardPage;
use Scalyn\MailRelay\Contracts\ProviderInterface;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\ProviderRegistry;
use Scalyn\MailRelay\Core\SettingsRepository;
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

	protected function setUp(): void {
		$this->wpdb                        = new WpdbStub();
		$GLOBALS['wpdb']                   = $this->wpdb;
		$GLOBALS['_test_current_user_can'] = array();
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_actions']       = array();
		$GLOBALS['_test_wp_added_actions'] = array();
		$this->reset_plugin_singleton();
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
		$this->wpdb->get_results_return = array();
		$this->boot_plugin();

		// Register a minimal provider so ProviderRegistry::has() returns true.
		Plugin::instance()->container()->get( ProviderRegistry::class )->register(
			new class implements ProviderInterface {
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

		// find_recent() calls prepare() with the limit and offset as args.
		$last_prepare = end( $this->wpdb->prepare_calls );
		$this->assertIsArray( $last_prepare );
		$this->assertSame( 1, $last_prepare['args'][0], 'Dashboard must request exactly 1 row.' );
		$this->assertSame( 0, $last_prepare['args'][1], 'Dashboard must use offset 0.' );
	}

	public function test_no_direct_sql_exists_in_dashboard_render_path(): void {
		$this->grant_view_dashboard();
		$this->wpdb->get_results_return = array( $this->make_log_row() );

		// DashboardPage::render() must only access $wpdb through the repository.
		// If prepare_calls is populated it means the query went through $wpdb->prepare().
		$this->render_and_capture();

		foreach ( $this->wpdb->prepare_calls as $call ) {
			$this->assertStringContainsString(
				'scalyn_mail_logs',
				$call['query'],
				'All wpdb queries from render() must target the mail_logs table via the repository.'
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
