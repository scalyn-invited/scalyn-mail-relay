<?php
/**
 * Explicit diagnostic coverage, including for historical snapshots.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/** Never infers receiver authentication or delivery from configuration checks. */
final class VerificationScope {

	/** Displays fixed limitations without using stored claims of authentication. */
	public static function render(): void {
		echo '<div class="scalyn-card scalyn-verification-scope">';
		echo '<h2>' . esc_html__( 'Message authentication and delivery: not verified', 'scalyn-mail-relay' ) . '</h2>';
		echo '<p>' . esc_html__( 'Even 100/100 is a configuration-check score, not an authentication result or a delivery success rate. Unknown or unavailable checks are excluded from the number. DNS record presence and SMTP acceptance cannot establish that a recipient accepted the message.', 'scalyn-mail-relay' ) . '</p>';
		echo '<ul>';
		foreach ( array(
			__( 'SPF authorization for the actual outbound IP and envelope sender: not verified.', 'scalyn-mail-relay' ),
			__( 'DKIM signature on the actual email: not verified.', 'scalyn-mail-relay' ),
			__( 'DMARC alignment of the actual email: not verified.', 'scalyn-mail-relay' ),
			__( 'Recipient-server acceptance and inbox placement: not verified.', 'scalyn-mail-relay' ),
		) as $limitation ) {
			echo '<li>' . esc_html( $limitation ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'These DNS checks use the configured From domain, or the site-domain fallback. The envelope-sender domain used for SPF and the DKIM signing domain may differ. Compare the domain in each finding with the identities in the bounce or receiver authentication results.', 'scalyn-mail-relay' ) . '</p>';
		echo '<p>' . esc_html__( 'For a missing message, inspect the provider delivery log or bounce report. A Gmail 550 5.7.26 rejection means the receiver rejected authentication; fix the sending identity with your provider instead of relying on the score. Older findings labelled valid are still only limited DNS checks.', 'scalyn-mail-relay' ) . '</p>';
		echo '</div>';
	}
}
