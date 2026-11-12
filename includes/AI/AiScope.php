<?php
/** Conservative pre-inference topic gate, supplementary to model validation.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

defined( 'ABSPATH' ) || exit;

/** Never claims keyword checks are a complete semantic security boundary. */
final class AiScope {
	/** Checks plugin topics and short relevant follow-ups.
	 *
	 * @param string $text Current question.
	 * @param array  $messages Private bounded history.
	 */
	public static function allows( string $text, array $messages ): bool {
		if ( preg_match( '/ignore (?:all |the )?(?:previous|prior|system)|reveal (?:your )?(?:system|hidden)|jailbreak|act as (?:a |an )?(?:different|unrestricted)/i', $text ) ) {
			return false;
		}
		$topics = '/\b(?:scalyn|plugin|mail|emails?|smtp|smtp2go|brevo|sendgrid|postmark|spf|dkim|dmarc|dns|tls|diagnostics?|deliverability|webhook|routing|retry|retries|failover|recovery|provider|configuration|nonce|retention|branding|mcp|openai|gemini|claude|ai assistant|health score|imap|pop3|gmail|outlook|inbox|spam|bounces?|mailer|mailbox)\b/i';
		if ( preg_match( '/\b(?:draft|rewrite|compose)\b.{0,40}\b(?:email|message|letter)\b/i', $text ) ) {
			return false;
		}
		if ( preg_match( $topics, $text ) ) {
			return true;
		}
		if ( preg_match( '/^(?:what (?:needs (?:fixing|to be fixed)|to be fixed|should (?:I|we) fix|is wrong)|any (?:issues|problems)|what next|next steps?|how (?:do I|to) (?:set ?up|configure)(?: (?:this|it))?)[?.!\s]*$/i', trim( $text ) ) ) {
			return true;
		}
		if ( strlen( $text ) <= 160 && preg_match( '/^(?:why|how|what (?:does (?:that|this|it)|should|can|do|is|are)|can (?:you|I)|explain|what next|fix)\b/i', trim( $text ) ) ) {
			foreach ( array_slice( $messages, -4 ) as $message ) {
				if ( 'user' === $message['role'] && ( true === ( $message['in_scope'] ?? false ) || preg_match( $topics, $message['text'] ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Fixed refusal never repeats unrelated model content. */
	public static function decline(): array {
		return array(
			'in_scope'            => false,
			'observed'            => 'I can help with technical email setup, diagnostics, delivery, security and troubleshooting, but not unrelated requests or email drafting.',
			'possible_causes'     => '',
			'next_steps'          => '',
			'references'          => array( 'help' ),
			'suggest_diagnostics' => false,
		);
	}
}
