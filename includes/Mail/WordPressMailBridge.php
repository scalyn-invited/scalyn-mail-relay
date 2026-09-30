<?php
/**
 * Routes ordinary WordPress mail through the selected SendGrid provider.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Mail;

use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts wp_mail only when SendGrid is active. SMTP behavior is unchanged.
 * Unsupported mail shapes fail closed instead of silently falling back to PHP mail.
 */
final class WordPressMailBridge {

	/**
	 * Connects the bridge to provider settings and the shared dispatcher.
	 *
	 * @param SettingsRepository $settings Provider selection and public sender.
	 * @param MailDispatcher     $dispatcher Shared mail orchestrator.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly MailDispatcher $dispatcher
	) {}

	/** Registers the WordPress short-circuit at its normal priority. */
	public function register(): void {
		add_filter( 'pre_wp_mail', array( $this, 'maybe_send' ), 10, 2 );
	}

	/**
	 * Preserves an earlier short-circuit and leaves non-SendGrid mail untouched.
	 *
	 * @param mixed $pre  Prior pre_wp_mail result.
	 * @param mixed $atts Filtered WordPress mail arguments.
	 * @return mixed Null for core mail, otherwise the SendGrid acknowledgement boolean.
	 */
	public function maybe_send( mixed $pre, mixed $atts ): mixed {
		if ( null !== $pre || 'sendgrid' !== $this->settings->get_active_provider_id() ) {
			return $pre;
		}
		try {
			$message = $this->prepare( $atts );
			return $this->dispatcher->dispatch( $message )->success;
		} catch ( \Throwable $error ) {
			// No raw WordPress mail or provider exception is ever logged or surfaced.
			return false;
		}
	}

	/**
	 * Maps the common wp_mail shape without dropping unsupported headers or embeds.
	 * The SendGrid adapter performs the final address, size and attachment checks.
	 *
	 * @param mixed $atts WordPress arguments after the wp_mail filter.
	 * @return MailMessage Prepared message.
	 * @throws \InvalidArgumentException When a structure cannot be mapped safely.
	 */
	private function prepare( mixed $atts ): MailMessage {
		if ( ! is_array( $atts ) || ! is_string( $atts['subject'] ?? null ) || ! is_string( $atts['message'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Unsupported WordPress mail arguments.' );
		}
		$embeds = $atts['embeds'] ?? array();
		if ( ! empty( $embeds ) ) {
			throw new \InvalidArgumentException( 'Inline embeds are unsupported.' );
		}
		$to = $atts['to'] ?? array();
		$to = is_string( $to ) ? explode( ',', $to ) : $to;
		if ( ! is_array( $to ) || array() === $to || count( $to ) > 100 ) {
			throw new \InvalidArgumentException( 'Unsupported recipient list.' );
		}
		foreach ( $to as $address ) {
			if ( ! is_string( $address ) ) {
				throw new \InvalidArgumentException( 'Unsupported recipient.' );
			}
		}
		$headers = $atts['headers'] ?? array();
		$headers = is_string( $headers ) ? preg_split( '/\r\n|\r|\n/', $headers ) : $headers;
		if ( ! is_array( $headers ) ) {
			throw new \InvalidArgumentException( 'Unsupported mail headers.' );
		}
		$config       = $this->settings->get_sendgrid_settings();
		$from_email   = $config['from_email'];
		$content_type = apply_filters( 'wp_mail_content_type', 'text/plain' );
		$forwarded    = array();
		$from_seen    = false;
		$type_seen    = false;
		foreach ( $headers as $header ) {
			if ( ! is_string( $header ) || preg_match( '/[\r\n\x00]/', $header ) ) {
				throw new \InvalidArgumentException( 'Unsupported mail header.' );
			}
			$header = trim( $header );
			if ( '' === $header ) {
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z][A-Za-z0-9-]*):[ \t]*(.+)$/D', $header, $parts ) ) {
				throw new \InvalidArgumentException( 'Unsupported mail header.' );
			}
			$name = strtolower( $parts[1] );
			if ( 'from' === $name ) {
				if ( $from_seen || ! $this->matches_sender( trim( $parts[2] ), $from_email ) ) {
					throw new \InvalidArgumentException( 'WordPress sender does not match SendGrid sender.' );
				}
				$from_seen = true;
			} elseif ( 'content-type' === $name ) {
				if ( $type_seen ) {
					throw new \InvalidArgumentException( 'Repeated content type.' );
				}
				$content_type = trim( $parts[2] );
				$type_seen    = true;
			} else {
				// Unknown headers reach the adapter, which rejects rather than drops them.
				$forwarded[] = $header;
			}
		}
		if ( ! is_string( $content_type ) || ! preg_match( '~^(text/plain|text/html)(?:;[ \t]*charset=["\']?UTF-8["\']?)?$~iD', $content_type, $parts ) ) {
			throw new \InvalidArgumentException( 'Unsupported content type.' );
		}
		$attachments = $atts['attachments'] ?? array();
		$attachments = is_string( $attachments ) ? preg_split( '/\r\n|\r|\n/', $attachments ) : $attachments;
		if ( ! is_array( $attachments ) ) {
			throw new \InvalidArgumentException( 'Unsupported attachments.' );
		}
		$attachments = array_values( array_filter( $attachments, static fn( mixed $path ): bool => '' !== $path ) );
		foreach ( $attachments as $path ) {
			if ( ! is_string( $path ) ) {
				throw new \InvalidArgumentException( 'Unsupported attachment path.' );
			}
		}
		return new MailMessage(
			uuid: wp_generate_uuid4(),
			from: $from_email,
			to: array_values( $to ),
			subject: $atts['subject'],
			body: $atts['message'],
			content_type: strtolower( $parts[1] ),
			headers: $forwarded,
			attachments: $attachments,
			context: array(
				'source_type' => 'wordpress',
				'source_name' => 'wp_mail',
			)
		);
	}

	/**
	 * Only a configured exact sender can be used for this SendGrid identity.
	 *
	 * @param string $header Sender from the mail header.
	 * @param string $configured Configured sender address.
	 */
	private function matches_sender( string $header, string $configured ): bool {
		if ( preg_match( '/^[^<>\r\n]*<([^<>\r\n]+)>$/D', $header, $parts ) ) {
			$header = $parts[1];
		}
		return '' !== $configured && strcasecmp( trim( $header ), $configured ) === 0;
	}
}
