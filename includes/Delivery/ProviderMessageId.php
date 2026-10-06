<?php
/**
 * Provider-specific identifier bounds shared by ingestion and persistence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Delivery;

defined( 'ABSPATH' ) || exit;

/** Never fuzzy matches identifiers. */
final class ProviderMessageId {
	/**
	 * Tests canonical identifiers.
	 *
	 * @param string $provider Trusted provider.
	 * @param mixed  $id Identifier.
	 */
	public static function valid( string $provider, mixed $id ): bool {
		if ( ! is_string( $id ) || strlen( $id ) > 255 ) {
			return false;
		}
		return 1 === preg_match(
			match ( $provider ) {
			'postmark' => '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',
			'smtp2go' => '/^[A-Za-z0-9-]{5,100}$/D',
			'brevo' => '/^<[A-Za-z0-9._-]+@[A-Za-z0-9.-]+>$/D',
			default => '/(?!)/',
			},
			$id
		);
	}
}
