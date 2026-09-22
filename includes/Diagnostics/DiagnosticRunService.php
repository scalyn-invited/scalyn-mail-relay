<?php
/**
 * Shared manual and scheduled diagnostic orchestration.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Core\HookNames;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Core\Container;
use Scalyn\MailRelay\Database\DiagnosticRunLock;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Diagnostics\DiagnosticCheckRegistry;
use Scalyn\MailRelay\Diagnostics\DiagnosticContextBuilder;
use Scalyn\MailRelay\Diagnostics\DiagnosticRunner;

defined( 'ABSPATH' ) || exit;

/** Shared execution for REST and scheduled diagnostics. */
final class DiagnosticRunService {
	/** Re-entry guard.
	 *
	 * @var bool
	 */
	private bool $running = false;

	/** Creates the orchestrator using the shared lazy container.
	 *
	 * @param Container         $container Shared services.
	 * @param DiagnosticRunLock $lock Run exclusion.
	 */
	public function __construct( private Container $container, private DiagnosticRunLock $lock ) {}

	/** Runs one bounded operation.
	 *
	 * @return array Safe result with status for adapters.
	 */
	public function run(): array {
		$lock = $this->lock;
		if ( $this->running || ! $lock->acquire() ) {
			return array(
				'success' => false,
				'message' => __( 'Diagnostics are already running or unavailable. Try again later.', 'scalyn-mail-relay' ),
				'status'  => 409,
			);
		}
		$this->running = true;
		try {
			return $this->execute();
		} finally {
			$this->running = false;
			$lock->release();
		}
	}

	/** Executes with shared container dependencies.
	 *
	 * @return array Safe run result.
	 */
	private function execute(): array {
		$container = $this->container;
		$run_uuid  = wp_generate_uuid4();
		$state     = $container->get( \Scalyn\MailRelay\Database\DiagnosticRunStateRepository::class );
		$begun     = false;
		$failure   = 'context_failed';

		try {
			$begun = $state->begin( $run_uuid, \Scalyn\MailRelay\Audit\AuditActor::capture()->source );
			if ( ! $begun ) {
				return array(
					'success' => false,
					'message' => __( 'Diagnostic execution status could not be saved. Check database availability and try again.', 'scalyn-mail-relay' ),
					'status'  => 503,
				);
			}
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'diagnostic_run', 'started', $run_uuid ) );
			$runner   = $container->get( DiagnosticRunner::class );
			$registry = $container->get( DiagnosticCheckRegistry::class );

			// Build the context through the credential-safe builder: it exposes only
			// host/port/encryption to checks (never username/password) and targets
			// the sending domain from the From address, falling back to the site host.
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$context   = $container->get( DiagnosticContextBuilder::class )->build(
				$container->get( SettingsRepository::class ),
				is_string( $site_host ) && '' !== $site_host ? $site_host : 'localhost'
			);

			// Execute all registered diagnostic checks and collect results.
			$failure       = 'checks_failed';
			$checks        = $registry->get_all();
			$check_results = $runner->run( array_values( $checks ), $context );

			$failure   = 'publication_failed';
			$published = $container->get( \Scalyn\MailRelay\Database\DiagnosticPublicationRepository::class )->publish( $run_uuid, $check_results );

			$this->record_finish( $state, $run_uuid );
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'diagnostic_run', 'completed', $run_uuid ) );
			return array(
				'success'      => true,
				'results'      => $published['results'],
				'health_score' => $published['health_score'],
				'status'       => 200,
			);
		} catch ( \Throwable $e ) {
			if ( $begun ) {
				$this->record_finish( $state, $run_uuid, $failure );
			}
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'diagnostic_run', 'failed', $run_uuid ) );
			return array(
				'success' => false,
				'message' => __( 'An error occurred while running diagnostics.', 'scalyn-mail-relay' ),
				'status'  => 500,
			);
		}
	}

	/** Terminal status failure must not relabel an already committed run.
	 *
	 * @param \Scalyn\MailRelay\Database\DiagnosticRunStateRepository $state Status repository.
	 * @param string                                                  $uuid Owning run.
	 * @param string                                                  $failure Fixed failure code.
	 */
	private function record_finish( \Scalyn\MailRelay\Database\DiagnosticRunStateRepository $state, string $uuid, string $failure = '' ): void {
		try {
			if ( $state->finish( $uuid, $failure ) ) {
				return;
			}
		} catch ( \Throwable $error ) {
			// Leave the durable running marker unresolved, without exception details.
			$failure = '';
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed operational warning, no exception payload.
		error_log( 'Scalyn Mail Relay: diagnostic status persistence failed.' );
	}
}
