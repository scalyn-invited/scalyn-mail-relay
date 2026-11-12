<?php
/**
 * Explainable delivery evidence summary for one send attempt.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Delivery;

use Scalyn\MailRelay\Core\PostmarkWebhookSettings;
use Scalyn\MailRelay\Database\DeliveryCoverageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Read model shared by the timeline drawer and full page. It never changes the
 * transport status, never infers delivery for untracked recipients, and never
 * exposes recipient identities, tokens or Bcc membership.
 */
final class DeliveryCoverage {

	/**
	 * Creates the read model.
	 *
	 * @param DeliveryCoverageRepository $repository Token-free aggregates.
	 * @param PostmarkWebhookSettings    $sources Source state.
	 * @param array                      $api_sources Provider-bound optional sources.
	 */
	public function __construct(
		private readonly DeliveryCoverageRepository $repository,
		private readonly PostmarkWebhookSettings $sources,
		private readonly array $api_sources = array()
	) {}

	/**
	 * Summarizes evidence for a message.
	 *
	 * @param string $message_uuid Validated attempt UUID.
	 * @param string $transport_status Original log status, unchanged by evidence.
	 * @return array state, label, explanation and counts; all strings are plain text.
	 */
	public function summarize( string $message_uuid, string $transport_status ): array {
		try {
			$coverage = $this->repository->find( $message_uuid );
		} catch ( \Throwable $error ) {
			return $this->result( 'unavailable', __( 'Unavailable', 'scalyn-mail-relay' ), __( 'Delivery evidence could not be read. The sending outcome above is unchanged.', 'scalyn-mail-relay' ) );
		}
		if ( null === $coverage ) {
			if ( ! $this->sources->collection_enabled() && ! array_filter( $this->api_sources, static fn( $source ): bool => $source->collection_enabled() ) ) {
				return $this->result( 'not_enabled', __( 'Not enabled', 'scalyn-mail-relay' ), __( 'Delivery and bounce evidence collection is not enabled. Accepted reflects provider acknowledgement only.', 'scalyn-mail-relay' ) );
			}
			return $this->result( 'unavailable', __( 'Unavailable', 'scalyn-mail-relay' ), __( 'This message was not tracked. It may have been sent before collection started, through another provider, while collection was paused, or tracking could not be prepared. Evidence is never inferred for untracked messages.', 'scalyn-mail-relay' ) );
		}
		$expected = max( 1, $coverage['expected'] );
		if ( 0 === $coverage['with_events'] ) {
			if ( 'failed' === $transport_status ) {
				return $this->result( 'unavailable', __( 'Unavailable', 'scalyn-mail-relay' ), __( 'The send attempt failed, so no provider delivery evidence is expected.', 'scalyn-mail-relay' ), $coverage );
			}
			return $this->result(
				'awaiting',
				__( 'Awaiting evidence', 'scalyn-mail-relay' ),
				/* translators: %d: number of tracked recipients. */
				sprintf( _n( 'No authenticated delivery or bounce report has been received yet for %d tracked recipient. Reports can be delayed.', 'No authenticated delivery or bounce report has been received yet for %d tracked recipients. Reports can be delayed.', $expected, 'scalyn-mail-relay' ), $expected ),
				$coverage
			);
		}
		$parts = array(
			/* translators: 1: recipients with delivery reports, 2: tracked recipients. */
			sprintf( __( 'Delivery confirmed for %1$d of %2$d recipients', 'scalyn-mail-relay' ), $coverage['delivered'], $expected ),
		);
		if ( $coverage['bounced'] > 0 ) {
			/* translators: %d: recipients with bounce reports. */
			$parts[] = sprintf( __( 'bounce reported for %d', 'scalyn-mail-relay' ), $coverage['bounced'] );
		}
		if ( $coverage['mixed'] > 0 ) {
			/* translators: %d: recipients with both delivery and bounce reports. */
			$parts[] = sprintf( __( 'both delivery and bounce reported for %d', 'scalyn-mail-relay' ), $coverage['mixed'] );
		}
		$unknown = max( 0, $expected - $coverage['with_events'] );
		if ( $unknown > 0 ) {
			/* translators: %d: recipients without reports. */
			$parts[] = sprintf( __( 'remaining outcomes unknown for %d', 'scalyn-mail-relay' ), $unknown );
		}
		$explanation = implode( '; ', $parts ) . '. ' . __( 'Recipient-server delivery does not prove inbox placement.', 'scalyn-mail-relay' );
		if ( $coverage['delivered'] === $expected && 0 === $coverage['bounced'] + $coverage['mixed'] ) {
			return $this->result( 'delivered', __( 'Delivered (recipient server)', 'scalyn-mail-relay' ), $explanation, $coverage );
		}
		if ( $coverage['bounced'] + $coverage['mixed'] > 0 ) {
			$state = $coverage['delivered'] + $coverage['mixed'] > 0 ? 'mixed' : 'bounced';
			return $this->result( $state, 'mixed' === $state ? __( 'Mixed evidence', 'scalyn-mail-relay' ) : __( 'Bounce reported', 'scalyn-mail-relay' ), $explanation, $coverage );
		}
		return $this->result( 'partial', __( 'Partially confirmed', 'scalyn-mail-relay' ), $explanation, $coverage );
	}

	/**
	 * Builds the fixed result shape.
	 *
	 * @param string     $state Machine state.
	 * @param string     $label Visible label.
	 * @param string     $explanation Visible explanation.
	 * @param array|null $coverage Aggregates, when tracked.
	 */
	private function result( string $state, string $label, string $explanation, ?array $coverage = null ): array {
		return array(
			'state'       => $state,
			'label'       => $label,
			'explanation' => $explanation,
			'expected'    => $coverage['expected'] ?? null,
			'latest_at'   => $coverage['latest_at'] ?? null,
		);
	}
}
