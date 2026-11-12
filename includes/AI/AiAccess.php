<?php
/** Administrator-only AI boundary.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

defined( 'ABSPATH' ) || exit;

/** Never grants AI access through additive plugin profiles. */
final class AiAccess {

	/** Checks the native administrator capability. */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' );
	}

	/** Rejects unauthorized access before reading any private state.
	 *
	 * @throws \RuntimeException For unauthorized users.
	 */
	public static function require_admin(): void {
		if ( ! self::allowed() ) {
			throw new \RuntimeException( 'Administrator access is required.', 403 );
		}
	}
}
