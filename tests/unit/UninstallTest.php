<?php

use PHPUnit\Framework\TestCase;
use Scalyn\MailRelay\Core\Capabilities;

/**
 * Covers both uninstall modes.
 *
 * Retain (the default) must remove nothing at all. Delete must remove the owned
 * tables, the plugin options, the granted capabilities, the scheduled events and
 * the transients — and nothing outside that set.
 *
 * uninstall.php is a script, not a class, so each test runs it with `require`
 * (not require_once) against freshly seeded globals.
 */
final class UninstallTest extends TestCase {

	private const OWNED_TABLES = array(
		'wp_scalyn_mail_logs',
		'wp_scalyn_mail_timeline',
		'wp_scalyn_diagnostics',
		'wp_scalyn_health_scores',
		'wp_scalyn_alerts',
		'wp_scalyn_audit_logs',
	);

	private WpdbStub $wpdb;

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'scalyn-mail-relay/scalyn-mail-relay.php' );
		}
	}

	protected function setUp(): void {
		$this->wpdb                        = new WpdbStub();
		$GLOBALS['wpdb']                   = $this->wpdb;
		$GLOBALS['_test_wp_cron']          = array();
		$GLOBALS['_test_wp_cleared_hooks'] = array();
		$GLOBALS['_test_wp_transients']    = array(
			'scalyn_mail_relay_health_cache'      => 'cached',
			'scalyn_mail_relay_diagnostics_cache' => 'cached',
			'unrelated_plugin_cache'              => 'keep me',
		);

		$administrator = new RoleStub();
		foreach ( Capabilities::all() as $capability ) {
			$administrator->add_cap( $capability );
		}
		$administrator->add_cap( 'manage_options' );

		$GLOBALS['_test_wp_roles'] = array(
			'administrator' => $administrator,
			'editor'        => new RoleStub(),
		);

		$GLOBALS['_test_wp_options'] = array(
			'scalyn_mail_relay_db_version' => '0.1.0',
			'scalyn_mail_relay_version'    => '0.1.0',
			'unrelated_plugin_option'      => 'keep me',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['_test_wp_roles']         = array();
		$GLOBALS['_test_wp_cleared_hooks'] = array();
	}

	/**
	 * Seeds the settings option and runs uninstall.php.
	 *
	 * @param bool|null $delete_flag Value for advanced.delete_data_on_uninstall,
	 *                               or null to omit the key entirely.
	 */
	private function run_uninstall( ?bool $delete_flag ): void {
		$settings = array( 'general' => array( 'from_name' => 'Site' ) );

		if ( null !== $delete_flag ) {
			$settings['advanced'] = array( 'delete_data_on_uninstall' => $delete_flag );
		}

		$GLOBALS['_test_wp_options']['scalyn_mail_relay_settings'] = $settings;

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	private function administrator(): RoleStub {
		return $GLOBALS['_test_wp_roles']['administrator'];
	}

	// -------------------------------------------------------------------------
	// Retain mode — the default
	// -------------------------------------------------------------------------

	public function test_retains_everything_when_the_flag_is_absent(): void {
		$this->run_uninstall( null );

		$this->assertSame( array(), $this->wpdb->queries, 'No table may be dropped by default.' );
		$this->assertArrayHasKey( 'scalyn_mail_relay_settings', $GLOBALS['_test_wp_options'] );
		$this->assertArrayHasKey( 'scalyn_mail_relay_db_version', $GLOBALS['_test_wp_options'] );
		$this->assertTrue( $this->administrator()->has_cap( Capabilities::VIEW_DASHBOARD ) );
		$this->assertArrayHasKey( 'scalyn_mail_relay_health_cache', $GLOBALS['_test_wp_transients'] );
		$this->assertSame( array(), $GLOBALS['_test_wp_cleared_hooks'] );
	}

	public function test_retains_everything_when_the_flag_is_false(): void {
		$this->run_uninstall( false );

		$this->assertSame( array(), $this->wpdb->queries );
		$this->assertArrayHasKey( 'scalyn_mail_relay_settings', $GLOBALS['_test_wp_options'] );
		$this->assertTrue( $this->administrator()->has_cap( Capabilities::VIEW_DASHBOARD ) );
	}

	/**
	 * A truthy-but-not-true value must not be enough to destroy data.
	 */
	public function test_retains_everything_for_a_truthy_non_boolean_flag(): void {
		$GLOBALS['_test_wp_options']['scalyn_mail_relay_settings'] = array(
			'advanced' => array( 'delete_data_on_uninstall' => '0' ),
		);

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( array(), $this->wpdb->queries );
		$this->assertTrue( $this->administrator()->has_cap( Capabilities::VIEW_DASHBOARD ) );
	}

	// -------------------------------------------------------------------------
	// Delete mode — explicit opt-in
	// -------------------------------------------------------------------------

	public function test_drops_every_owned_table_when_deletion_is_enabled(): void {
		$this->run_uninstall( true );

		$this->assertCount( count( self::OWNED_TABLES ), $this->wpdb->queries );

		foreach ( self::OWNED_TABLES as $table ) {
			$this->assertContains(
				"DROP TABLE IF EXISTS `{$table}`",
				$this->wpdb->queries,
				sprintf( '%s should be dropped in delete mode.', $table )
			);
		}
	}

	public function test_removes_plugin_options_but_leaves_others(): void {
		$this->run_uninstall( true );

		$this->assertArrayNotHasKey( 'scalyn_mail_relay_settings', $GLOBALS['_test_wp_options'] );
		$this->assertArrayNotHasKey( 'scalyn_mail_relay_db_version', $GLOBALS['_test_wp_options'] );
		$this->assertArrayNotHasKey( 'scalyn_mail_relay_version', $GLOBALS['_test_wp_options'] );
		$this->assertSame( 'keep me', $GLOBALS['_test_wp_options']['unrelated_plugin_option'] );
	}

	public function test_revokes_every_granted_capability_from_every_role(): void {
		$this->run_uninstall( true );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse(
				$this->administrator()->has_cap( $capability ),
				sprintf( '%s must not survive an uninstall.', $capability )
			);
		}

		$this->assertSame(
			Capabilities::all(),
			$GLOBALS['_test_wp_roles']['editor']->removed,
			'Revocation must sweep every role, not only administrator.'
		);
	}

	public function test_leaves_unrelated_capabilities_intact(): void {
		$this->run_uninstall( true );

		$this->assertTrue(
			$this->administrator()->has_cap( 'manage_options' ),
			'Uninstall must not touch capabilities the plugin never granted.'
		);
	}

	public function test_clears_every_owned_cron_hook(): void {
		$this->run_uninstall( true );

		$this->assertSame(
			array(
				'scalyn_mail_relay_cleanup_logs',
				'scalyn_mail_relay_run_daily_diagnostics',
				'scalyn_mail_relay_generate_health_snapshot',
				'scalyn_mail_relay_send_alerts',
			),
			$GLOBALS['_test_wp_cleared_hooks']
		);
	}

	public function test_deletes_plugin_transients_but_leaves_others(): void {
		$this->run_uninstall( true );

		$this->assertArrayNotHasKey( 'scalyn_mail_relay_health_cache', $GLOBALS['_test_wp_transients'] );
		$this->assertArrayNotHasKey( 'scalyn_mail_relay_diagnostics_cache', $GLOBALS['_test_wp_transients'] );
		$this->assertSame( 'keep me', $GLOBALS['_test_wp_transients']['unrelated_plugin_cache'] );
	}
}
