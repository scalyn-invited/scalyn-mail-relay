<?php
/** Content-free link from a submission log to its logical recovery history.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

defined( 'ABSPATH' ) || exit;

/** Legacy rows remain unattributed; no guessing based on provider or time. */
final class AttemptHistory {

	/**
	 * Resolves through the repository only, under existing log/settings capabilities.
	 *
	 * @param string $attempt Validated log UUID.
	 */
	public static function render( string $attempt ): void {
		if ( ! current_user_can( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS ) || version_compare( (string) get_option( 'scalyn_mail_relay_db_version', '0.0.0' ), '0.12.0', '<' ) ) {
			return;
		}
		try {
			$logical = ( new \Scalyn\MailRelay\Database\SubmissionAttemptRepository() )->logical_for( $attempt );
			if ( '' === $logical ) {
				return;
			}
			?>
			<p><a class="button" href="<?php echo esc_url( add_query_arg( 'recovery_message', $logical, admin_url( 'admin.php?page=scalyn-mail-relay-alerts' ) ) . '#scalyn-submission-history' ); ?>"><?php esc_html_e( 'View logical-message attempts', 'scalyn-mail-relay' ); ?></a></p>
			<?php
		} catch ( \Throwable $error ) {
			// Missing history remains unknown; ordinary timeline rendering continues.
			unset( $error );
		}
	}
}
