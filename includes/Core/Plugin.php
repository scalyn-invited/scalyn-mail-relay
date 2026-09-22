<?php
/**
 * Main plugin application.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Core;

use Scalyn\MailRelay\Admin\AdminMenu;
use Scalyn\MailRelay\Audit\AuditRepository;
use Scalyn\MailRelay\Audit\AuditRecorder;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\DiagnosticRetentionRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;
use Scalyn\MailRelay\Database\RetentionStateRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;
use Scalyn\MailRelay\Diagnostics\HealthScorer;
use Scalyn\MailRelay\Diagnostics\Checks\DkimCheck;
use Scalyn\MailRelay\Diagnostics\Checks\DmarcCheck;
use Scalyn\MailRelay\Diagnostics\Checks\MxCheck;
use Scalyn\MailRelay\Diagnostics\Checks\SpfCheck;
use Scalyn\MailRelay\Providers\Mail\SmtpTlsCheck;
use Scalyn\MailRelay\Logging\MailEventSubscriber;
use Scalyn\MailRelay\Logging\MailLogRepository;
use Scalyn\MailRelay\Logging\MailRetentionRepository;
use Scalyn\MailRelay\Logging\TimelineRepository;
use Scalyn\MailRelay\Mail\FailureClassifier;
use Scalyn\MailRelay\Mail\MailDispatcher;
use Scalyn\MailRelay\Rest\DiagnosticsRunEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton. Bootstraps the service container and registers WordPress hooks.
 *
 * Entry point: scalyn-mail-relay.php fires Plugin::instance()->boot() on plugins_loaded.
 * The scalyn_mail_relay_booted action is fired after all services are registered,
 * giving other modules and third-party code an opportunity to extend the container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The plugin service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has already been called.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor — use instance() to obtain the singleton.
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * Returns the singleton plugin instance, creating it if necessary.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boots the plugin: registers services, hooks, and fires scalyn_mail_relay_booted.
	 * Subsequent calls are no-ops.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->register_services();
		$this->populate_diagnostic_checks();
		$this->register_hooks();
		$this->booted = true;

		do_action( 'scalyn_mail_relay_booted', $this->container );
	}

	/**
	 * Returns the plugin service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Registers core services in the container.
	 */
	private function register_services(): void {
		$this->container->set( \Scalyn\MailRelay\Alerts\AlertRepository::class, static fn(): \Scalyn\MailRelay\Alerts\AlertRepository => new \Scalyn\MailRelay\Alerts\AlertRepository() );
		$this->container->set( \Scalyn\MailRelay\Alerts\ObservationRepository::class, static fn(): \Scalyn\MailRelay\Alerts\ObservationRepository => new \Scalyn\MailRelay\Alerts\ObservationRepository() );
		$this->container->set( \Scalyn\MailRelay\Alerts\WebhookChannel::class, static fn(): \Scalyn\MailRelay\Alerts\WebhookChannel => new \Scalyn\MailRelay\Alerts\WebhookChannel() );
		$this->container->set( \Scalyn\MailRelay\Alerts\AlertService::class, static fn( Container $c ): \Scalyn\MailRelay\Alerts\AlertService => new \Scalyn\MailRelay\Alerts\AlertService( $c->get( \Scalyn\MailRelay\Alerts\AlertRepository::class ), $c->get( \Scalyn\MailRelay\Alerts\ObservationRepository::class ), $c->get( \Scalyn\MailRelay\Alerts\WebhookChannel::class ) ) );
		$this->container->set( AuditRepository::class, static fn(): AuditRepository => new AuditRepository() );
		$this->container->set( AuditRecorder::class, static fn( Container $c ): AuditRecorder => new AuditRecorder( $c->get( AuditRepository::class ) ) );
		$this->container->set( AdminMenu::class, static fn(): AdminMenu => new AdminMenu() );
		$this->container->set( SettingsRepository::class, static fn(): SettingsRepository => new SettingsRepository() );
		$this->container->set( ProviderRegistry::class, static fn(): ProviderRegistry => new ProviderRegistry() );
		$this->container->set(
			MailDispatcher::class,
			static fn( Container $c ): MailDispatcher => new MailDispatcher(
				$c->get( ProviderRegistry::class ),
				$c->get( SettingsRepository::class )
			)
		);

		$this->container->set( MailLogRepository::class, static fn(): MailLogRepository => new MailLogRepository() );
		$this->container->set( MailRetentionRepository::class, static fn(): MailRetentionRepository => new MailRetentionRepository() );
		$this->container->set( TimelineRepository::class, static fn(): TimelineRepository => new TimelineRepository() );
		$this->container->set( FailureClassifier::class, static fn(): FailureClassifier => new FailureClassifier() );
		$this->container->set(
			MailEventSubscriber::class,
			static fn( Container $c ): MailEventSubscriber => new MailEventSubscriber(
				$c->get( MailLogRepository::class ),
				$c->get( TimelineRepository::class )
			)
		);

		$this->container->set( DiagnosticCheckRegistry::class, static fn(): DiagnosticCheckRegistry => new DiagnosticCheckRegistry() );
		$this->container->set( DiagnosticRunner::class, static fn(): DiagnosticRunner => new DiagnosticRunner() );
		$this->container->set( \Scalyn\MailRelay\Database\DiagnosticRunStateRepository::class, static fn(): \Scalyn\MailRelay\Database\DiagnosticRunStateRepository => new \Scalyn\MailRelay\Database\DiagnosticRunStateRepository() );
		$this->container->set( DiagnosticSchedule::class, static fn(): DiagnosticSchedule => new DiagnosticSchedule() );
		$this->container->set( \Scalyn\MailRelay\Database\DiagnosticRunLock::class, static fn(): \Scalyn\MailRelay\Database\DiagnosticRunLock => new \Scalyn\MailRelay\Database\DiagnosticRunLock() );
		$this->container->set( \Scalyn\MailRelay\Diagnostics\DiagnosticRunService::class, static fn( Container $c ): \Scalyn\MailRelay\Diagnostics\DiagnosticRunService => new \Scalyn\MailRelay\Diagnostics\DiagnosticRunService( $c, $c->get( \Scalyn\MailRelay\Database\DiagnosticRunLock::class ) ) );
		$this->container->set( DiagnosticContextBuilder::class, static fn(): DiagnosticContextBuilder => new DiagnosticContextBuilder() );
		$this->container->set( DiagnosticRepository::class, static fn(): DiagnosticRepository => new DiagnosticRepository() );
		$this->container->set( DiagnosticRetentionRepository::class, static fn(): DiagnosticRetentionRepository => new DiagnosticRetentionRepository() );
		$this->container->set( RetentionStateRepository::class, static fn(): RetentionStateRepository => new RetentionStateRepository() );
		$this->container->set(
			RetentionService::class,
			static fn( Container $c ): RetentionService => new RetentionService(
				$c->get( SettingsRepository::class ),
				$c->get( MailRetentionRepository::class ),
				$c->get( DiagnosticRetentionRepository::class ),
				$c->get( RetentionStateRepository::class ),
				$c->get( AuditRepository::class )
			)
		);
		$this->container->set( HealthScorer::class, static fn(): HealthScorer => new HealthScorer() );
		$this->container->set( HealthScoreRepository::class, static fn(): HealthScoreRepository => new HealthScoreRepository() );
		$this->container->set( \Scalyn\MailRelay\Database\DiagnosticPublicationRepository::class, static fn( Container $c ): \Scalyn\MailRelay\Database\DiagnosticPublicationRepository => new \Scalyn\MailRelay\Database\DiagnosticPublicationRepository( $c->get( DiagnosticRepository::class ), $c->get( HealthScoreRepository::class ), $c->get( HealthScorer::class ), $c->get( MailLogRepository::class ) ) );
		$this->container->set( DiagnosticsRunEndpoint::class, static fn(): DiagnosticsRunEndpoint => new DiagnosticsRunEndpoint() );

		// Register core diagnostic checks.
		$this->container->set( SpfCheck::class, static fn(): SpfCheck => new SpfCheck() );
		$this->container->set( MxCheck::class, static fn(): MxCheck => new MxCheck() );
		$this->container->set( DkimCheck::class, static fn(): DkimCheck => new DkimCheck() );
		$this->container->set( DmarcCheck::class, static fn(): DmarcCheck => new DmarcCheck() );
		$this->container->set( SmtpTlsCheck::class, static fn(): SmtpTlsCheck => new SmtpTlsCheck() );
	}

	/**
	 * Populates the diagnostic check registry with registered checks.
	 * Called during boot after all services are registered but before hooks.
	 */
	private function populate_diagnostic_checks(): void {
		$registry = $this->container->get( DiagnosticCheckRegistry::class );
		$registry->register( $this->container->get( SpfCheck::class ) );
		$registry->register( $this->container->get( MxCheck::class ) );
		$registry->register( $this->container->get( DkimCheck::class ) );
		$registry->register( $this->container->get( DmarcCheck::class ) );
		$registry->register( $this->container->get( SmtpTlsCheck::class ) );
	}

	/**
	 * Registers WordPress action and filter hooks.
	 */
	private function register_hooks(): void {
		load_plugin_textdomain( 'scalyn-mail-relay', false, dirname( plugin_basename( SCALYN_MAIL_RELAY_FILE ) ) . '/languages' );

		// Mail logging hooks run on every request (not only admin) because mail
		// can be dispatched from frontend, REST, WP-CLI, and cron contexts.
		$this->container->get( MailEventSubscriber::class )->register();
		$this->container->get( AuditRecorder::class )->register();
		$this->container->get( RetentionService::class )->register();
		$this->container->get( DiagnosticSchedule::class )->register();
		$this->container->get( \Scalyn\MailRelay\Alerts\AlertService::class )->register();
		add_action( 'admin_init', array( \Scalyn\MailRelay\Database\Migrator::class, 'maybe_upgrade' ) );

		// Register REST endpoints.
		add_action( 'rest_api_init', array( $this->container->get( DiagnosticsRunEndpoint::class ), 'register' ) );

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
		}
	}
}
