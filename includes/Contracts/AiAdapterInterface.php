<?php
/** Advisory AI is independent of mail transport.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Contracts;

defined( 'ABSPATH' ) || exit;

/** Fixed credential-free adapter outputs; no action execution. */
interface AiAdapterInterface {

	/** Returns account-visible supported model IDs. */
	public function models(): array;

	/** Generates a bounded structured advisory answer.
	 *
	 * @param string $model Explicit selected model.
	 * @param array  $history Redacted bounded conversation.
	 * @param array  $evidence Redacted current evidence.
	 */
	public function answer( string $model, array $history, array $evidence ): array;
}
