<?php
/** Current provider diagnostic read model.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Diagnostics;

use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\DiagnosticRepository;
use Scalyn\MailRelay\Database\HealthScoreRepository;

defined( 'ABSPATH' ) || exit;

/** Keeps all current-health surfaces on the same evidence boundary. */
final class CurrentDiagnostics {
	/** Creates the read model.
	 *
	 * @param DiagnosticRepository     $diagnostics Findings repository.
	 * @param HealthScoreRepository    $health Score repository.
	 * @param SettingsRepository       $settings Current settings.
	 * @param DiagnosticContextBuilder $builder Public context builder.
	 */
	public function __construct( private DiagnosticRepository $diagnostics, private HealthScoreRepository $health, private SettingsRepository $settings, private DiagnosticContextBuilder $builder ) {}

	/** Returns a scoped snapshot; legacy and superseded runs never become current.
	 *
	 * @return array Public attribution, current findings and correlated score.
	 */
	public function snapshot(): array {
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$context  = $this->builder->build( $this->settings, is_string( $host ) ? $host : 'localhost' );
		$provider = $this->settings->get_active_provider_id();
		$rows     = $this->diagnostics->find_current_run( $this->settings->get_diagnostic_revision(), $provider, $context->domain );
		return array(
			'provider' => $provider,
			'domain'   => $context->domain,
			'results'  => $rows,
			'health'   => $this->health->find_by_run( (string) ( $rows[0]['diagnostic_uuid'] ?? '' ) ),
		);
	}
}
