<?php
/**
 * Trusted request attribution for audit events.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Audit;

defined( 'ABSPATH' ) || exit;

/** Captures runtime identity without reading submitted actor/source fields. */
final readonly class AuditActor {

	public const SOURCES = array( 'manual', 'rest', 'scheduled', 'cli', 'application', 'unknown' );

	/**
	 * Creates an actor.
	 *
	 * @param int    $user_id WordPress user ID; zero means unattributed.
	 * @param string $source Allowlisted execution context.
	 * @throws \InvalidArgumentException When attribution is invalid.
	 */
	public function __construct( public int $user_id, public string $source ) {
		if ( $user_id < 0 || ! in_array( $source, self::SOURCES, true ) || ( 'scheduled' === $source && 0 !== $user_id ) ) {
			throw new \InvalidArgumentException( 'Invalid audit actor.' );
		}
	}

	/** Captures the actor at event creation, including scheduled execution priority. */
	public static function capture(): self {
		if ( wp_doing_cron() ) {
			return new self( 0, 'scheduled' );
		}
		$user_id = max( 0, (int) get_current_user_id() );
		$source  = match ( true ) {
			defined( 'WP_CLI' ) && WP_CLI => 'cli',
			defined( 'REST_REQUEST' ) && REST_REQUEST => 'rest',
			is_admin() && $user_id > 0 => 'manual',
			default => 'application',
		};
		return new self( $user_id, $source );
	}
}
