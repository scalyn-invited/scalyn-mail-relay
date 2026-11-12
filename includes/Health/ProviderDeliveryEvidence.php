<?php
/**
 * Source-lifecycle gate for provider health evidence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Health;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\ProviderDeliveryRepository;

defined( 'ABSPATH' ) || exit;

/** Collection state never authorizes querying a different configuration. */
final class ProviderDeliveryEvidence {
	/**
	 * Uses only safe source projections and an aggregate repository.
	 *
	 * @param SettingsRepository         $settings Current revision.
	 * @param ProviderDeliveryRepository $repository Counts.
	 * @param array                      $sources Provider-indexed source lifecycle services.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ProviderDeliveryRepository $repository,
		private readonly array $sources
	) {}

	/** Returns bounded counts or explicit missing-evidence state; never credentials.
	 *
	 * @param int      $days Requested window; provider health defaults to seven days.
	 * @param int|null $now Shared evaluation instant, UTC epoch.
	 * @param bool     $include_source Include public source UUID for persisted trace invalidation only.
	 */
	public function current( int $days = 7, ?int $now = null, bool $include_source = false ): array {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return array( 'status' => 'unavailable' ); }
		$provider = $this->settings->get_active_provider_id();
		if ( ! isset( $this->sources[ $provider ] ) ) {
			return array( 'status' => 'unsupported' ); }
		try {
			$service = $this->sources[ $provider ];
			$status  = $service->collection_status();
			if ( 'collecting' !== $status ) {
				return array( 'status' => in_array( $status, array( 'off', 'paused' ), true ) ? $status : 'unavailable' ); }
			$source   = $service->dispatch_source();
			$revision = $this->settings->get_diagnostic_revision();
			if ( ! is_array( $source ) || ( $source['configuration_id'] ?? '' ) !== $revision ) {
				return array( 'status' => 'unavailable' ); }
			$counts = $this->repository->counts( $revision, $provider, $source['id'], min( 30, max( 1, $days ), $this->settings->get_log_retention_days() ), new \DateTimeImmutable( '@' . ( $now ?? time() ) ) );
			return null === $counts ? array( 'status' => 'unavailable' ) : array_merge( $counts, array( 'status' => 'available' ), $include_source ? array( 'source_id' => $source['id'] ) : array() );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'unavailable' );
		}
	}
}
