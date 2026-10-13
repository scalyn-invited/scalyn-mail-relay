<?php
/** Bounded text handling; not a claim of perfect secret detection.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

defined( 'ABSPATH' ) || exit;

/** Rejects recognizable secrets and masks addresses in free text. */
final class AiRedaction {

	/** Checks recognizable credential and private-key patterns.
	 *
	 * @param string $text Untrusted text.
	 */
	public static function has_secret( string $text ): bool {
		return 1 === preg_match( '/sk-[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9]+|-----BEGIN [A-Z ]*PRIVATE KEY|(?:password|api[_ -]?key|token|authorization)\s*[:=]\s*\S{4,}/i', $text );
	}

	/** Validates text before local persistence or transmission.
	 *
	 * @param string $text User input.
	 * @throws \InvalidArgumentException For oversized inputs or recognizable secrets.
	 */
	public static function input( string $text ): string {
		if ( '' === trim( $text ) || strlen( $text ) > 16000 || mb_strlen( $text, 'UTF-8' ) > 4000 || self::has_secret( $text ) || preg_match( '/^(?:Received|DKIM-Signature|Authentication-Results|Return-Path|Message-ID|From|To|Subject):/mi', $text ) ) {
			throw new \InvalidArgumentException( 'Enter up to 4,000 characters without credentials or private keys.' );
		}
		$text = wp_strip_all_tags( $text );
		return preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[address removed]', $text );
	}
}
