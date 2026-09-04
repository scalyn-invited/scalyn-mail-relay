<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Lifecycle;

/**
 * Covers activation and deactivation behaviour:
 *
 *  - environment gating on PHP/WordPress baselines,
 *  - capability grants to the Administrator role,
 *  - that no recurring cron event is registered in the 0.1.0 MVP,
 *  - that stale events from an earlier install are cleared,
 *  - that deactivation never destroys data.
 */
final class LifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                   = new WpdbStub();
		$GLOBALS['_test_wp_options']       = array();
		$GLOBALS['_test_wp_cron']          = array();
		$GLOBALS['_test_wp_cleared_hooks'] = array();
		$GLOBALS['_test_wp_roles']         = array( 'administrator' => new RoleStub() );
		$GLOBALS['wp_version']             = '6.5';

		// Migrator::migrate() returns early when the stored DB version already
		// matches the target, which keeps activation free of dbDelta().
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_db_version'] = '0.1.0';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_version'] );
		$GLOBALS['_test_wp_roles']         = array();
		$GLOBALS['_test_wp_cron']          = array();
		$GLOBALS['_test_wp_cleared_hooks'] = array();
	}

	private function administrator(): RoleStub {
		return $GLOBALS['_test_wp_roles']['administrator'];
	}

	// -------------------------------------------------------------------------
	// Capabilities
	// -------------------------------------------------------------------------

	public function test_activation_grants_every_capability_to_administrator(): void {
		Lifecycle::activate();

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue(
				$this->administrator()->has_cap( $capability ),
				sprintf( 'Administrator should hold %s after activation.', $capability )
			);
		}
	}

	public function test_activation_without_administrator_role_does_not_fatal(): void {
		$GLOBALS['_test_wp_roles'] = array();

		Lifecycle::activate();

		$this->assertSame( '0.1.0', $GLOBALS['_test_wp_options']['scalyn_mail_relay_version'] );
	}

	// -------------------------------------------------------------------------
	// Version stamp
	// -------------------------------------------------------------------------

	public function test_activation_records_the_plugin_version(): void {
		Lifecycle::activate();

		$this->assertSame(
			SCALYN_MAIL_RELAY_VERSION,
			$GLOBALS['_test_wp_options']['scalyn_mail_relay_version']
		);
	}

	// -------------------------------------------------------------------------
	// Scheduled events
	// -------------------------------------------------------------------------

	public function test_activation_schedules_no_recurring_events(): void {
		Lifecycle::activate();

		$this->assertSame(
			array(),
			$GLOBALS['_test_wp_cron'],
			'0.1.0 implements no cron handlers, so it must not register cron events.'
		);
	}

	public function test_activation_clears_stale_events_from_an_earlier_install(): void {
		// An install upgraded from a build that scheduled these hooks.
		$GLOBALS['_test_wp_cron'] = array(
			'scalyn_mail_relay_cleanup_logs'          => 1234567890,
			'scalyn_mail_relay_run_daily_diagnostics' => 1234567890,
		);

		Lifecycle::activate();

		$this->assertSame( array(), $GLOBALS['_test_wp_cron'] );
	}

	public function test_deactivation_clears_every_owned_cron_hook(): void {
		Lifecycle::deactivate();

		$this->assertSame(
			array(
				'scalyn_mail_relay_cleanup_logs',
				'scalyn_mail_relay_run_daily_diagnostics',
				'scalyn_mail_relay_generate_health_snapshot',
				'scalyn_mail_relay_send_alerts',
			),
			$GLOBALS['_test_wp_cleared_hooks'],
			'Deactivation must clear every hook name the plugin has ever scheduled.'
		);
	}

	// -------------------------------------------------------------------------
	// Deactivation preserves data
	// -------------------------------------------------------------------------

	public function test_deactivation_preserves_settings_and_capabilities(): void {
		Lifecycle::activate();
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_settings'] = array( 'general' => array() );

		Lifecycle::deactivate();

		$this->assertArrayHasKey( 'scalyn_mail_relay_settings', $GLOBALS['_test_wp_options'] );
		$this->assertArrayHasKey( 'scalyn_mail_relay_db_version', $GLOBALS['_test_wp_options'] );
		$this->assertTrue( $this->administrator()->has_cap( Capabilities::VIEW_DASHBOARD ) );
		$this->assertSame( array(), $this->administrator()->removed );
	}

	// -------------------------------------------------------------------------
	// Environment gating
	// -------------------------------------------------------------------------

	public function test_activation_is_refused_below_the_supported_wordpress_baseline(): void {
		$GLOBALS['wp_version'] = '6.4';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'WordPress 6.5 or newer' );

		Lifecycle::activate();
	}
}
