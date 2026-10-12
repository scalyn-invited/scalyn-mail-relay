<?php
/**
 * Allowlisted site-local agency identity.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Agency;

use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;

defined( 'ABSPATH' ) || exit;

/** Never accepts arbitrary CSS, HTML, remote logo URLs or active image formats. */
final class BrandingRepository {

	public const OPTION = 'scalyn_mail_relay_branding';

	/** Returns defaults when stored fields are invalid, including deleted logos.
	 *
	 * @return array Validated identity only.
	 */
	public function get(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		foreach ( $defaults as $key => $value ) {
			try {
				$defaults[ $key ] = $this->validate( $key, is_array( $stored ) ? ( $stored[ $key ] ?? $value ) : $value );
			} catch ( \InvalidArgumentException $error ) {
				// Invalid saved data never becomes CSS or markup.
				continue;
			}
		}
		return $defaults;
	}

	/** Fixed default brand, preserving the existing UI theme.
	 *
	 * @return array Default fields.
	 */
	public static function defaults(): array {
		return array(
			'name'          => 'Scalyn Mail Relay',
			'logo_id'       => 0,
			'primary'       => '#08766b',
			'secondary'     => '#17292e',
			'support_email' => '',
			'support_url'   => '',
			'docs_url'      => '',
		);
	}

	/** Saves one complete identity after authorization and strict validation.
	 *
	 * @param array $input Exact fields; logo_id must be an integer.
	 * @throws \RuntimeException When unauthorized or persistence fails.
	 * @throws \InvalidArgumentException When any field is invalid.
	 */
	public function save( array $input ): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( Capabilities::MANAGE_AGENCY ) ) {
			throw new \RuntimeException( 'Agency branding is not authorized.' );
		}
		$defaults = self::defaults();
		if ( count( $input ) !== count( $defaults ) || array_diff( array_keys( $defaults ), array_keys( $input ) ) ) {
			throw new \InvalidArgumentException( 'Invalid agency branding.' );
		}
		$next = array();
		foreach ( $defaults as $key => $value ) {
			$next[ $key ] = $this->validate( $key, $input[ $key ] );
		}
		$before = $this->get();
		if ( $before === $next ) {
			return;
		}
		if ( ! update_option( self::OPTION, $next, false ) && $this->get() !== $next ) {
			throw new \RuntimeException( 'Agency branding could not be saved.' );
		}
		$fields = array();
		foreach ( $next as $key => $value ) {
			if ( $before[ $key ] !== $value ) {
				$fields[] = 'branding.' . $key;
			}
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'agency_branding', 'changed', wp_generate_uuid4(), $fields ) );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed text; no identity or submitted values.
			error_log( 'Scalyn Mail Relay: branding audit persistence failed.' );
		}
	}

	/** Returns a raster media-library logo URL; never fetches an external resource.
	 *
	 * @return string URL or empty.
	 */
	public function logo_url(): string {
		$id  = $this->get()['logo_id'];
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : false;
		return is_string( $url ) ? $url : '';
	}

	/** Validates one field without lossy sanitization that could hide mistakes.
	 *
	 * @param string $key Fixed field.
	 * @param mixed  $value Submitted value.
	 * @return string|int Validated value.
	 * @throws \InvalidArgumentException When invalid.
	 */
	private function validate( string $key, mixed $value ): string|int {
		if ( 'logo_id' === $key ) {
			if ( ! is_int( $value ) || $value < 0 || ( $value > 0 && ( 'attachment' !== get_post_type( $value )
				|| ! in_array( get_post_mime_type( $value ), array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) ) ) {
				throw new \InvalidArgumentException( 'Choose a PNG, JPEG or WebP media attachment.' );
			}
			return $value;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 300 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid agency branding.' );
		}
		$value = trim( $value );
		if ( 'name' === $key ) {
			if ( '' === $value || strlen( $value ) > 80 || sanitize_text_field( $value ) !== $value ) {
				throw new \InvalidArgumentException( 'Use a plain-text brand name of up to 80 bytes.' );
			}
		} elseif ( in_array( $key, array( 'primary', 'secondary' ), true ) ) {
			if ( ! preg_match( '/^#[0-9a-f]{6}$/iD', $value ) || self::contrast_on_white( $value ) < 4.5 ) {
				throw new \InvalidArgumentException( 'Choose six-digit colors with at least 4.5:1 contrast against white.' );
			}
			$value = strtolower( $value );
		} elseif ( 'support_email' === $key ) {
			if ( '' !== $value && ! is_email( $value ) ) {
				throw new \InvalidArgumentException( 'Invalid support email.' );
			}
		} elseif ( '' !== $value ) {
			$parts = wp_parse_url( $value );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] )
				|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
				|| false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				throw new \InvalidArgumentException( 'Use an HTTPS link without credentials, query or fragment.' );
			}
		}
		return $value;
	}

	/** WCAG relative luminance contrast; colors are validated before calling.
	 *
	 * @param string $hex Six-digit color.
	 * @return float Contrast ratio against white.
	 */
	private static function contrast_on_white( string $hex ): float {
		$channels = array();
		foreach ( array( 1, 3, 5 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 1.05 / ( 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2] + 0.05 );
	}
}
