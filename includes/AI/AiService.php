<?php
/** Advisory orchestration, never dispatches mail or changes sending settings.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Contracts\AiAdapterInterface;

defined( 'ABSPATH' ) || exit;

/** Owns consent, context bounds and explicit diagnostic confirmation. */
final class AiService {

	/** Constructs the service.
	 *
	 * @param AiSettings         $settings Opt-in controls.
	 * @param AiRepository       $repository Owned encrypted sessions.
	 * @param AiAdapterInterface $adapter Remote inference only.
	 * @param AiEvidence         $evidence Minimal current read model.
	 */
	public function __construct( private AiSettings $settings, private AiRepository $repository, private AiAdapterInterface $adapter, private AiEvidence $evidence ) {}

	/** Sends one question after a fresh scope and consent check.
	 *
	 * @param string $uuid Private session.
	 * @param string $text User question.
	 * @throws \RuntimeException For disabled AI, unavailable context or exhausted history.
	 */
	public function send( string $uuid, string $text ): array {
		AiAccess::require_admin();
		$config = $this->settings->summary();
		if ( ! $config['enabled'] || ! $config['consent'] || ! $config['encryption'] || ! $config['has_key'] || '' === $config['model'] ) {
			throw new \RuntimeException( 'Enable AI with a key, selected model and consent in Settings.' );
		}
		$text  = AiRedaction::input( $text );
		$token = $this->repository->claim( $uuid );
		try {
			$session = $this->repository->read( $uuid );
			if ( count( $session['messages'] ) > 98 ) {
				throw new \RuntimeException( 'Conversation limit reached. Start a new chat.' );
			}
			$snapshot = $this->evidence->snapshot();
			$history  = array();
			foreach ( array_slice( $session['messages'], -10 ) as $message ) {
				if ( false === ( $message['in_scope'] ?? true ) || ( 'assistant' === $message['role'] && false === ( $message['answer']['in_scope'] ?? true ) ) ) {
					continue;
				}
				$content = 'user' === $message['role'] ? $message['text'] : wp_json_encode( $message['answer'] );
				if ( strlen( $content ) <= 16000 && ! AiRedaction::has_secret( $content ) ) {
					$history[] = array(
						'role'    => $message['role'],
						'content' => $content,
					);
				}
			}
			$history_size = strlen( wp_json_encode( $history ) );
			while ( $history_size > 30000 ) {
				array_shift( $history );
				$history_size = strlen( wp_json_encode( $history ) );
			}
			$truncated = count( $history ) < count( $session['messages'] );
			$history[] = array(
				'role'    => 'user',
				'content' => $text,
			);
			if ( AiScope::allows( $text, $session['messages'] ) ) {
				$current_config = $this->settings->summary();
				if ( ! $current_config['enabled'] || ! hash_equals( $config['revision'], $current_config['revision'] ) ) {
					throw new \RuntimeException( 'AI settings changed before dispatch. Reload this conversation.' );
				}
				$this->repository->renew( $uuid, $token );
				$this->repository->consume( 'ai-minute:' . get_current_user_id() . ':' . gmdate( 'Y-m-d-H-i' ), 5, time() + 120 );
				$this->repository->consume( 'ai-day:' . gmdate( 'Y-m-d' ), 100, time() + 2 * DAY_IN_SECONDS );
				$answer = $this->adapter->answer( $config['model'], $history, $snapshot );
			} else {
				$answer = AiScope::decline();
			}
			if ( $answer['in_scope'] ) {
				$answer['observed']   = AiEvidence::observations( $snapshot );
				$answer['references'] = array_values( array_unique( array_merge( $answer['references'], array( 'configuration', 'diagnostics', 'health', 'activity' ) ) ) );
			}
			$session['messages'][] = array(
				'role'     => 'user',
				'in_scope' => $answer['in_scope'],
				'text'     => $text,
				'at'       => gmdate( 'c' ),
			);
			$session['messages'][] = array(
				'role'              => 'assistant',
				'answer'            => $answer,
				'at'                => gmdate( 'c' ),
				'revision'          => $snapshot['revision'],
				'captured_at_utc'   => $snapshot['captured_at_utc'],
				'context_truncated' => $truncated,
			);
			$this->repository->save( $uuid, $token, $session );
			return $session;
		} finally {
			$this->repository->release( $uuid, $token );
		}
	}
}
