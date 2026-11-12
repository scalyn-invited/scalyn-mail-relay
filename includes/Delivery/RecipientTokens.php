<?php
/**
 * Versioned, privacy-preserving matching of parsed recipients.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Delivery;

defined( 'ABSPATH' ) || exit;

/** No raw addresses, key material or tokens may be exposed by admin read models. */
final class RecipientTokens {

	/**
	 * Matches one parsed address without provider-specific mailbox guessing.
	 *
	 * @param string $key Dedicated random 32-byte matching key.
	 * @param string $version Immutable key UUID.
	 * @param string $source Source UUID.
	 * @param string $attempt Send-attempt UUID.
	 * @param string $address Parsed address, not a display-name mailbox.
	 * @return string Pseudonymous HMAC token, not anonymous data.
	 * @throws \InvalidArgumentException When any input is invalid.
	 */
	public static function create( #[\SensitiveParameter] string $key, string $version, string $source, string $attempt, #[\SensitiveParameter] string $address ): string {
		foreach ( array( $version, $source, $attempt ) as $id ) {
			if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id ) ) {
				throw new \InvalidArgumentException( 'Recipient matching inputs are invalid.' );
			}
		}
		if ( 32 !== strlen( $key ) || strlen( $address ) > 254 || ! filter_var( $address, FILTER_VALIDATE_EMAIL ) || preg_match( '/[\r\n\x00]/', $address ) ) {
			throw new \InvalidArgumentException( 'Recipient matching inputs are invalid.' );
		}
		$separator = strrpos( $address, '@' );
		$canonical = substr( $address, 0, $separator ) . '@' . strtolower( substr( $address, $separator + 1 ) );
		return hash_hmac( 'sha256', wp_json_encode( array( 'scalyn-recipient-v1', strtolower( $version ), strtolower( $source ), strtolower( $attempt ), $canonical ) ), $key );
	}
}
