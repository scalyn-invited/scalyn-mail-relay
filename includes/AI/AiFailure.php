<?php
/** Fixed, credential-free AI failure categories.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

defined( 'ABSPATH' ) || exit;

/** Only these authored messages may cross the administrator response boundary. */
final class AiFailure extends \RuntimeException {

	public const MESSAGES = array(
		'ai_new_chat'   => 'This conversation belongs to another AI provider. Start a new chat before using the selected provider. Earlier messages were not shared.',
		'ai_network'    => 'Could not connect to OpenAI. Check the server connection and try again manually. No automatic retry was made.',
		'ai_key'        => 'OpenAI rejected API access. Check the saved API key and its project permissions in AI Settings.',
		'ai_quota'      => 'OpenAI API quota is unavailable. Check API billing, credits and project spending limits. A ChatGPT subscription does not provide API credits.',
		'ai_rate'       => 'OpenAI rate-limited this request (HTTP 429). Wait before trying again and check API project rate limits or quota.',
		'ai_model'      => 'OpenAI could not use the selected model. Refresh models in AI Settings and explicitly select an available model.',
		'ai_remote'     => 'OpenAI could not complete this request. Check API service availability and your project settings. No automatic retry was made.',
		'ai_incomplete' => 'OpenAI reached the reply limit before completing an answer. Try a shorter, focused plugin question. No partial answer or automatic retry was accepted.',
	);

	/** Creates a safe error, never accepting provider-supplied prose.
	 *
	 * @param string $reason Fixed failure identifier.
	 * @param string $provider Fixed provider ID for safe authored labels.
	 */
	public function __construct( public readonly string $reason, public readonly string $provider = 'openai' ) {
		parent::__construct( str_replace( 'OpenAI', AiCatalog::PROVIDERS[ $provider ] ?? 'AI provider', self::MESSAGES[ $reason ] ?? self::MESSAGES['ai_remote'] ), 'ai_new_chat' === $reason ? 409 : ( in_array( $reason, array( 'ai_quota', 'ai_rate' ), true ) ? 429 : 502 ) );
	}
}
