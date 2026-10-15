<?php
/** Exact provider routing independent of mail transport.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Contracts\AiAdapterInterface;
defined( 'ABSPATH' ) || exit;
/** Never falls back to another vendor when a request fails. */
final class AiAdapters implements AiAdapterInterface {
	/** Creates the registry.
	 *
	 * @param AiSettings    $settings Protected profiles.
	 * @param OpenAiAdapter $openai Existing OpenAI adapter.
	 */
	public function __construct( private AiSettings $settings, private OpenAiAdapter $openai ) {}
	/** Returns only a fixed official provider adapter.
	 *
	 * @param string $provider Exact provider ID.
	 */
	public function for_provider( string $provider ): AiAdapterInterface {
		return 'openai' === $provider ? $this->openai : new VendorAiAdapter( $this->settings, $provider );}
	/** Lists models for the saved active provider. */
	public function models(): array {
		return $this->for_provider( $this->settings->summary()['provider'] )->models();}
	/** Routes a single model without fallback.
	 *
	 * @param string $model Supported ID.
	 * @param array  $history Bounded redacted history.
	 * @param array  $evidence Credential-free current evidence.
	 */
	public function answer( string $model, array $history, array $evidence ): array {
		return $this->for_provider( AiCatalog::provider( $model ) )->answer( $model, $history, $evidence );}
}
