<?php
/** Bounded authenticated message snapshots, never metadata exports.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Recovery;

use Scalyn\MailRelay\Core\CredentialCipher;
use Scalyn\MailRelay\Mail\MailMessage;

defined( 'ABSPATH' ) || exit;

/** Only opt-in queue ownership invokes this private content boundary. */
final class RecoveryPayload {

	public const MAX_BYTES = 5242880;

	/**
	 * Uses the separately configured server key; never WordPress salts as fallback.
	 *
	 * @param CredentialCipher $cipher Server cipher.
	 */
	public function __construct( private readonly CredentialCipher $cipher ) {}

	/**
	 * Captures attachments before CF7 deletes its original files; paths are not stored.
	 *
	 * @param MailMessage $message Prepared private mail.
	 * @param array       $plan Exact ordered targets, encrypted along with all addresses.
	 * @param string      $origin Registered origin.
	 * @return string Authenticated encrypted envelope.
	 * @throws \RuntimeException For unsupported or oversized content.
	 */
	public function seal( #[\SensitiveParameter] MailMessage $message, array $plan, string $origin ): string {
		if ( count( $plan ) < 1 || count( $plan ) > 3 || count( $message->to ) > 100 || count( $message->headers ) > 50 || count( $message->attachments ) > 10 || strlen( $message->body ) > self::MAX_BYTES || ! isset( \Scalyn\MailRelay\Mail\MailOrigin::types()[ $origin ] ) ) {
			throw new \RuntimeException( 'Message is unsupported for recovery storage.' );
		}
		$files = array();
		$bytes = strlen( $message->body ) + strlen( $message->subject );
		if ( $bytes > self::MAX_BYTES ) {
			throw new \RuntimeException( 'Recovery content exceeds the supported limit.' );
		}
		foreach ( $message->attachments as $index => $path ) {
			if ( ! is_string( $path ) || ! preg_match( '~^(?:[A-Za-z]:[\\\\/]|/)~', $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				throw new \RuntimeException( 'Recovery attachment is unavailable.' );
			}
			$name = $message->attachment_names[ $index ] ?? basename( $path );
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._ -]{0,199}$/D', $name ) || filesize( $path ) > self::MAX_BYTES - $bytes ) {
				throw new \RuntimeException( 'Recovery attachment exceeds the supported bounds.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local attachment snapshot, never a URL.
			$content = file_get_contents( $path, false, null, 0, self::MAX_BYTES - $bytes + 1 );
			if ( false === $content || strlen( $content ) > self::MAX_BYTES - $bytes ) {
				throw new \RuntimeException( 'Recovery attachment could not be captured.' );
			}
			$bytes  += strlen( $content );
			$files[] = array(
				'name'    => $name,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Binary attachment encoding inside authenticated encrypted payload only.
				'content' => base64_encode( $content ),
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Reject invalid UTF-8 rather than silently alter private mail.
		$json = json_encode(
			array(
				'version' => 1,
				'logical' => $message->uuid,
				'from'    => $message->from,
				'to'      => $message->to,
				'subject' => $message->subject,
				'body'    => $message->body,
				'type'    => $message->content_type,
				'headers' => $message->headers,
				'files'   => $files,
				'plan'    => $plan,
				'origin'  => $origin,
			),
			JSON_THROW_ON_ERROR
		);
		if ( strlen( $json ) > 8388608 ) {
			throw new \RuntimeException( 'Recovery payload exceeds the supported limit.' );
		}
		return $this->cipher->encrypt( $json, 'recovery-payload' );
	}

	/**
	 * Decrypts only in the worker. The caller owns and closes temporary handles.
	 *
	 * @param string $envelope Protected payload, never rendered.
	 * @param string $logical Expected queue identity, prevents swapping rows.
	 * @return array Private decoded payload.
	 * @throws \RuntimeException For unavailable or invalid content.
	 */
	public function open( #[\SensitiveParameter] string $envelope, string $logical ): array {
		$data = json_decode( $this->cipher->decrypt( $envelope, 'recovery-payload' ), true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ( $data['logical'] ?? null ) !== $logical || ! is_array( $data['plan'] ?? null ) || count( $data['plan'] ) < 1 || count( $data['plan'] ) > 3 ) {
			throw new \RuntimeException( 'Recovery payload is unavailable.' );
		}
		return $data;
	}

	/**
	 * Restores copied attachments in OS-private auto-deleting temporary files.
	 * Original names remain private adapter inputs, never log metadata.
	 *
	 * @param array  $data Authenticated payload.
	 * @param string $attempt Unique submission UUID.
	 * @param int    $number Bounded submission number.
	 * @param array  $handles Output handles to close in caller's finally, also on error.
	 * @return MailMessage Restored transport input.
	 * @throws \RuntimeException For unavailable private temporary storage.
	 */
	public function message( #[\SensitiveParameter] array $data, string $attempt, int $number, array &$handles ): MailMessage {
		$paths = array();
		$names = array();
		foreach ( $data['files'] as $file ) {
			// tmpfile is exclusive and deleted on close/process exit. Never a web directory.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tmpfile -- Secure per-process attachment handles with automatic cleanup.
			$handle = tmpfile();
			if ( false === $handle ) {
				throw new \RuntimeException( 'Private recovery attachment storage is unavailable.' );
			}
			$handles[] = $handle;
			$path      = stream_get_meta_data( $handle )['uri'];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict temporary content before writing.
			if ( ! chmod( $path, 0600 ) ) {
				throw new \RuntimeException( 'Private recovery attachment permissions are unavailable.' );
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Authenticated binary attachment, never executable content.
			$content = base64_decode( $file['content'], true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private auto-deleting stream, no persistent plaintext copies.
			if ( false === $content || fwrite( $handle, $content ) !== strlen( $content ) ) {
				throw new \RuntimeException( 'Recovery attachment could not be restored.' );
			}
			$paths[] = $path;
			$names[] = $file['name'];
		}
		list( $source, $type ) = explode( '/', $data['origin'] );
		return new MailMessage(
			$attempt,
			$data['from'],
			$data['to'],
			$data['subject'],
			$data['body'],
			$data['type'],
			$data['headers'],
			$paths,
			array(
				'logical_uuid'   => $data['logical'],
				'attempt_number' => $number,
				'origin'         => $data['origin'],
				'source_type'    => $source,
				'source_name'    => $type,
			),
			$names
		);
	}
}
