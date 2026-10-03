<?php
/**
 * Unregistered Postmark request gate; never acknowledges unpersisted evidence.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Providers\Postmark;

defined( 'ABSPATH' ) || exit;

/** Internal results only: do not serialize ready events as public REST responses. */
final class WebhookIngress {

	/**
	 * Checks request shape, authentication and budget before parsing or correlation.
	 *
	 * @param array              $request Method, headers (name => list of values), body,
	 *                                    https and peer_ip. Last two are trusted server facts.
	 * @param array              $source Trusted local id, server_id, stream, username,
	 *                                   password, allowed_ips and enabled configuration.
	 * @param callable           $consume_budget Atomic per-source budget: true permits,
	 *                                          false throttles; unavailable storage throws.
	 * @param callable           $resolve Source-bound retained association resolver.
	 * @param \DateTimeImmutable $received_at Server UTC receipt time.
	 * @return array Internal disposition with optional error HTTP status or safe event.
	 */
	public function inspect( #[\SensitiveParameter] array $request, #[\SensitiveParameter] array $source, #[\SensitiveParameter] callable $consume_budget, #[\SensitiveParameter] callable $resolve, \DateTimeImmutable $received_at ): array {
		if ( 'POST' !== ( $request['method'] ?? null ) ) {
			return $this->reject( 405 );
		}
		$headers = $request['headers'] ?? null;
		$body    = $request['body'] ?? null;
		if ( ! is_array( $headers ) || count( $headers ) > 64 || ! is_string( $body ) ) {
			return $this->reject( 400 );
		}
		if ( strlen( $body ) > WebhookNormalizer::MAX_BYTES ) {
			return $this->reject( 413 );
		}
		$normalized = array();
		$bytes      = 0;
		foreach ( $headers as $name => $values ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9-]{1,100}$/D', $name ) || ! is_array( $values ) || 1 !== count( $values ) || ! isset( $values[0] ) || ! is_string( $values[0] ) || preg_match( '/[\r\n\x00]/', $values[0] ) ) {
				return $this->reject( 400 );
			}
			$name = strtolower( $name );
			if ( isset( $normalized[ $name ] ) ) {
				return $this->reject( 400 );
			}
			$bytes += strlen( $name ) + strlen( $values[0] );
			if ( $bytes > 16384 ) {
				return $this->reject( 431 );
			}
			$normalized[ $name ] = $values[0];
		}
		if ( ! preg_match( '/^application\/json(?:;\s*charset=utf-8)?$/iD', $normalized['content-type'] ?? '' ) || isset( $normalized['content-encoding'] ) ) {
			return $this->reject( 415 );
		}
		if ( isset( $normalized['content-length'] ) && ( ! preg_match( '/^(0|[1-9][0-9]{0,8})$/D', $normalized['content-length'] ) || strlen( $body ) !== (int) $normalized['content-length'] ) ) {
			return $this->reject( 400 );
		}
		try {
			if ( ! is_string( $source['id'] ?? null ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $source['id'] ) || ! is_int( $source['server_id'] ?? null ) || 1 > $source['server_id'] || ! is_string( $source['stream'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,100}$/D', $source['stream'] ) || ! is_bool( $source['enabled'] ?? null ) ) {
				return $this->reject( 503 );
			}
			$https         = $request['https'] ?? false;
			$authenticated = ( new WebhookAuthenticator() )->verify( $normalized['authorization'] ?? '', $source['username'] ?? '', $source['password'] ?? '', true === $https, $request['peer_ip'] ?? '', $source['allowed_ips'] ?? array() );
			if ( ! $authenticated ) {
				return $this->reject( 401 );
			}
			if ( ! $source['enabled'] ) {
				return array( 'disposition' => 'ignored' );
			}
			try {
				$budget = $consume_budget( strtolower( $source['id'] ) );
			} catch ( \Throwable $error ) {
				return $this->reject( 503 );
			}
			if ( true !== $budget ) {
				return $this->reject( false === $budget ? 429 : 503 );
			}
			$event = ( new WebhookNormalizer() )->normalize( $body, $source['id'], $source['server_id'], $source['stream'], $received_at, $resolve );
			return null === $event ? array( 'disposition' => 'ignored' ) : array(
				'disposition' => 'ready',
				'event'       => $event,
			);
		} catch ( \InvalidArgumentException $error ) {
			return $this->reject( 400 );
		} catch ( \Throwable $error ) {
			// Never return/log/chains exceptions that might include source credentials or payloads.
			return $this->reject( 503 );
		}
	}

	/**
	 * Returns a fixed credential-free rejection; no raw exception or payload.
	 *
	 * @param int $status HTTP status for a future adapter.
	 * @return array Internal failure decision.
	 */
	private function reject( int $status ): array {
		return array(
			'disposition' => 'rejected',
			'http_status' => $status,
		);
	}
}
