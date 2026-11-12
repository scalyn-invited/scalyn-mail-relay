<?php
/** Explicit supported models, not a claim of account access.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

defined( 'ABSPATH' ) || exit;
/** No arbitrary providers, model IDs or automatic upgrades. */
final class AiCatalog {
	public const PROVIDERS = array(
		'openai' => 'OpenAI',
		'gemini' => 'Google Gemini',
		'claude' => 'Anthropic Claude',
	);
	public const MODELS    = array(
		'openai' => array(
			'gpt-4.1-mini' => 'GPT-4.1 mini',
			'gpt-4.1'      => 'GPT-4.1',
			'gpt-5-mini'   => 'GPT-5 mini',
			'gpt-5'        => 'GPT-5',
		),
		'gemini' => array(
			'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite',
			'gemini-3.8-flash'      => 'Gemini 3.8 Flash',
		),
		'claude' => array(
			'claude-haiku-5-5'  => 'Claude Haiku 5.5',
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
		),
	);
	/** Resolves an exact supported model.
	 *
	 * @param string $model Model ID.
	 * @throws \InvalidArgumentException For unsupported IDs.
	 */
	public static function provider( string $model ): string {
		foreach ( self::MODELS as $provider => $models ) {
			if ( isset( $models[ $model ] ) ) {
				return $provider;}
		}
		throw new \InvalidArgumentException( 'Choose a supported AI model.' );
	}
}
