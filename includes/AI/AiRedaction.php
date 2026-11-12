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
		// Detection-only canonicalization; never return decoded markup to callers.
		for ( $pass = 0; $pass < 3; ++$pass ) {
			if ( self::contains_secret( $text ) ) {
				return true;
			}
			$decoded = str_replace( "\xc2\xa0", ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			// Screen before stripping can erase script/style/comment contents.
			if ( self::contains_secret( $decoded ) || self::contains_secret( wp_strip_all_tags( $decoded ) ) ) {
				return true;
			}
			if ( $decoded === $text ) {
				return false;
			}
			// Retain unstripped contents for the next bounded decoding pass.
			$text = $decoded;
		}
		// Excessively nested encoding is rejected rather than partially screened.
		return self::contains_secret( $text ) || html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $text;
	}

	/**
	 * Screens one representation without altering caller-visible text.
	 *
	 * @param string $text Detection representation.
	 */
	private static function contains_secret( string $text ): bool {
		if ( 1 === preg_match( '/sk-[A-Za-z0-9_-]{16,}|AIza[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9]+|-----BEGIN [A-Z ]*PRIVATE KEY|(?:password|api[_ -]?key|token|authorization)\s*[:=]\s*\S{4,}/i', $text ) ) {
			return true;
		}
		// Explicit assignments fail closed regardless of the first value word's length.
		// Only complete allowlisted status statements are exempt, never prefixes.
		preg_match_all( '/\b(?:password|passphrase|passwd|pwd|api[_ -]?key|(?:access|refresh|auth)[_ -]?token|token|client[_ -]?secret|authorization)\b\s+(?:is(?:\s+set\s+to)?|equals)\s+(?:Bearer\s+)?(?:"([^"\r\n]+)"|\'([^\'\r\n]+)\'|(\S+))/i', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $matches as $match ) {
			if ( '' !== ( $match[1][0] ?? '' ) || '' !== ( $match[2][0] ?? '' ) ) {
				return true;
			}
			$status = substr( $text, $match[3][1] );
			// Match the entire suffix: a complete status, optionally ONE fixed help question.
			// No arbitrary words, domains or extra sentences are allowed by this exception.
			$condition = '(?:(?:incorrect|invalid|missing|required|expired|unavailable|disabled|enabled|optional|unknown|unchanged|configured)(?: for SMTP| when (?:connecting|authenticating)(?: to (?:SMTP|IMAP|POP3|Gmail))?| to (?:connect|authenticate)(?: to (?:SMTP|IMAP|POP3|Gmail))?| after (?:migration|an? (?:update|upgrade)|a password reset))?|not set|used for authentication|long enough)';
			$question  = '(?:What should I (?:do|try|check)|Can you help(?: me)?|How (?:can|do) I (?:fix|resolve) (?:this|it))';
			$separator = '(?:[.!?][ \	\\r\\n]{1,8}|[ \	]{0,8}\\r?\\n[ \	]{0,8})';
			if ( ! preg_match( '/\\A' . $condition . '(?:[.!?]?\\s*|' . $separator . $question . '\\?[ \	\\r\\n]{0,8})\\z/i', $status ) ) {
				return true;
			}
		}
		return false;
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
		if ( self::has_secret( $text ) ) {
			throw new \InvalidArgumentException( 'Enter up to 4,000 characters without credentials or private keys.' );
		}
		return preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[address removed]', $text );
	}
}
