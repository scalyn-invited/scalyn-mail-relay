<?php
/** Administrator-only AI settings and private chat shell.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\AI\AiAccess;
use Scalyn\MailRelay\AI\AiSettings;
use Scalyn\MailRelay\AI\OpenAiAdapter;

defined( 'ABSPATH' ) || exit;

/** Never echoes credentials or rejected form values. */
final class AiPage {
	/** Creates the page.
	 *
	 * @param AiSettings    $settings Safe configuration repository.
	 * @param OpenAiAdapter $adapter Model listing only after explicit administrator request.
	 */
	public function __construct( private AiSettings $settings, private OpenAiAdapter $adapter ) {}

	/** Renders the protected interface.
	 *
	 * @throws \InvalidArgumentException Internally translated into safe form feedback.
	 * @throws \RuntimeException Internally translated into safe form feedback.
	 */
	public function render(): void {
		if ( ! AiAccess::allowed() ) {
			wp_die( esc_html__( 'Administrator access is required.', 'scalyn-mail-relay' ) );
		}
		$notice = '';
		$error  = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Server-controlled method.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( 'scalyn_ai_settings' );
			try {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixed revision token, never echoed, nonce checked above.
				$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? wp_unslash( $_POST['revision'] ) : null;
				if ( null === $revision || ! hash_equals( $this->settings->summary()['revision'], $revision ) ) {
					throw new \RuntimeException( 'AI settings changed. Reload this page.' );
				}
				// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- Nonce checked above; strict action allowlist and repository validation below.
				$action = isset( $_POST['ai_action'] ) && is_string( $_POST['ai_action'] ) ? wp_unslash( $_POST['ai_action'] ) : '';
				if ( 'models' === $action ) {
					$this->settings->record_models( $this->adapter->models(), $revision );
					$notice = __( 'Account-visible supported models refreshed. Select a model explicitly. This tests key access, not a generated answer.', 'scalyn-mail-relay' );
				} elseif ( 'save' === $action ) {
					$input = array( 'revision' => $revision );
					foreach ( array( 'key', 'model' ) as $field ) {
						// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- Secret is never echoed; repository validates type and exact syntax.
						$input[ $field ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
					}
					foreach ( array( 'enabled', 'consent', 'mcp', 'remove_key' ) as $field ) {
						// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- Strict literal checkbox value, nonce checked above.
						$input[ $field ] = isset( $_POST[ $field ] ) && '1' === $_POST[ $field ];
					}
					$this->settings->save( $input );
					$notice = __( 'AI settings saved. No email was sent.', 'scalyn-mail-relay' );
				} else {
					throw new \InvalidArgumentException();
				}
			} catch ( \Throwable $exception ) {
				$error  = true;
				$notice = __( 'AI settings could not be saved or verified. Check encryption, key, model selection and consent. No rejected values are displayed.', 'scalyn-mail-relay' );
			}
		}
		$config = $this->settings->summary();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/ai.php';
	}
}
