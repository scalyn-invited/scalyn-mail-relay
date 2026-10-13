<?php
/** Private encrypted conversations and atomic bounded request budgets.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\AI;

use Scalyn\MailRelay\Core\CredentialCipher;

defined( 'ABSPATH' ) || exit;

/** Every session query is site-prefixed and owner scoped. */
final class AiRepository {

	/** Creates the repository.
	 *
	 * @param CredentialCipher $cipher Existing server-key encryption.
	 */
	public function __construct( private CredentialCipher $cipher ) {}

	/** Resolves authenticated ownership before a query.
	 *
	 * @throws \RuntimeException For unavailable secure storage.
	 */
	private function owner(): int {
		AiAccess::require_admin();
		$owner = get_current_user_id();
		if ( $owner < 1 || ! $this->cipher->is_available() ) {
			throw new \RuntimeException( 'Private AI storage is unavailable.' );
		}
		return $owner;
	}

	/** Checks identifier shape without disclosing another user's session.
	 *
	 * @param string $uuid Session ID.
	 * @throws \RuntimeException For invalid identifiers.
	 */
	private function valid( string $uuid ): void {
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid ) ) {
			throw new \RuntimeException( 'Conversation is unavailable.', 404 );
		}
	}

	/** Starts a private conversation without a paid API request.
	 *
	 * @throws \RuntimeException When insertion fails.
	 */
	public function create(): string {
		global $wpdb;
		$owner = $this->owner();
		$this->consume( 'chat-create-minute:' . $owner . ':' . gmdate( 'Y-m-d-H-i' ), 10, time() + 120 );
		$uuid = wp_generate_uuid4();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned encrypted insert.
		$payload = $this->encode(
			array(
				'title'    => 'New chat',
				'messages' => array(),
			)
		);
		$result  = $this->quiet(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Repository-owned encrypted insert, error output suppressed.
			static fn() => $wpdb->insert(
				$wpdb->prefix . 'scalyn_ai_sessions',
				array(
					'uuid'        => $uuid,
					'owner'       => $owner,
					'payload'     => $payload,
					'claim'       => '',
					'claim_until' => '1970-01-01 00:00:00',
					'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
					'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
				)
			)
		);
		if ( 1 !== $result ) {
			throw new \RuntimeException( 'Conversation could not be created.' );
		}
		return $uuid;
	}

	/** Lists up to twenty current sessions belonging to the requesting administrator.
	 *
	 * @throws \RuntimeException When reading fails.
	 */
	public function listing(): array {
		global $wpdb;
		$owner = $this->owner();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded owner-scoped read, no shared cache.
		$rows = $this->quiet( static fn() => $wpdb->get_results( $wpdb->prepare( 'SELECT uuid,payload,updated_at FROM %i WHERE owner=%d AND expires_at>%s ORDER BY updated_at DESC,uuid DESC LIMIT 20', $wpdb->prefix . 'scalyn_ai_sessions', $owner, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ) );
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Conversation list is unavailable.' );
		}
		return array_map(
			fn( $row ) => array(
				'uuid'       => $row['uuid'],
				'title'      => $this->decode( $row['payload'] )['title'],
				'updated_at' => $row['updated_at'],
			),
			$rows
		);
	}

	/** Returns a private non-expired conversation.
	 *
	 * @param string $uuid Session ID.
	 * @throws \RuntimeException For expired or unavailable conversations.
	 */
	public function read( string $uuid ): array {
		global $wpdb;
		$owner = $this->owner();
		$this->valid( $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact owner and expiry are mandatory.
		$row = $this->quiet( static fn() => $wpdb->get_row( $wpdb->prepare( 'SELECT payload FROM %i WHERE uuid=%s AND owner=%d AND expires_at>%s', $wpdb->prefix . 'scalyn_ai_sessions', $uuid, $owner, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A ) );
		if ( ! is_array( $row ) || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( 'Conversation is unavailable.', 404 );
		}
		return $this->decode( $row['payload'] );
	}

	/** Claims a session before reading/modifying its message history.
	 *
	 * @param string $uuid Session ID.
	 * @throws \RuntimeException For an unavailable or busy session.
	 */
	public function claim( string $uuid ): string {
		global $wpdb;
		$owner = $this->owner();
		$this->valid( $uuid );
		$token = wp_generate_uuid4();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic compare-and-set; no network-held transaction.
		$changed = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'UPDATE %i SET claim=%s,claim_until=%s WHERE uuid=%s AND owner=%d AND expires_at>%s AND claim_until<%s', $wpdb->prefix . 'scalyn_ai_sessions', $token, gmdate( 'Y-m-d H:i:s', time() + 180 ), $uuid, $owner, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ) ) ) );
		if ( 1 !== $changed ) {
			throw new \RuntimeException( 'Conversation is busy or unavailable. Do not resubmit a pending question.', 409 );
		}
		return $token;
	}

	/** Saves only the current claim's history; rename does not extend expiry.
	 *
	 * @param string $uuid Session ID.
	 * @param string $token Claim.
	 * @param array  $data Protected history.
	 * @param bool   $message Whether a new message extends retention.
	 * @throws \RuntimeException When the owner/claim write fails.
	 */
	public function save( string $uuid, string $token, array $data, bool $message = true ): void {
		global $wpdb;
		$owner = $this->owner();
		$this->valid( $uuid );
		$payload = $this->encode( $data );
		$expiry  = $message ? ',expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),updated_at=UTC_TIMESTAMP()' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed expiry fragment, prepared values and claim-bound write.
		$changed = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( "UPDATE %i SET payload=%s {$expiry} WHERE uuid=%s AND owner=%d AND claim=%s AND claim_until>UTC_TIMESTAMP() AND expires_at>UTC_TIMESTAMP()", $wpdb->prefix . 'scalyn_ai_sessions', $payload, $uuid, $owner, $token ) ) );
		if ( 1 !== $changed ) {
			throw new \RuntimeException( 'Conversation could not be saved.' );
		}
	}

	/** Releases only the matching claim.
	 *
	 * @param string $uuid Session ID.
	 * @param string $token Claim token.
	 */
	public function release( string $uuid, string $token ): void {
		global $wpdb;
		$owner = $this->owner();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact owner and claim match.
		$this->quiet( static fn() => $wpdb->query( $wpdb->prepare( "UPDATE %i SET claim='',claim_until='1970-01-01 00:00:00' WHERE uuid=%s AND owner=%d AND claim=%s", $wpdb->prefix . 'scalyn_ai_sessions', $uuid, $owner, $token ) ) );
	}

	/** Fences inference immediately before dispatch, renewing only a live owner claim.
	 *
	 * @param string $uuid Session ID.
	 * @param string $token Claim token.
	 * @throws \RuntimeException For a lost or expired claim.
	 */
	public function renew( string $uuid, string $token ): void {
		global $wpdb;
		$owner = $this->owner();
		$this->valid( $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic live-claim fence before a billable request.
		$changed = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'UPDATE %i SET claim_until=GREATEST(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 180 SECOND),DATE_ADD(claim_until,INTERVAL 1 SECOND)) WHERE uuid=%s AND owner=%d AND claim=%s AND claim_until>UTC_TIMESTAMP() AND expires_at>UTC_TIMESTAMP()', $wpdb->prefix . 'scalyn_ai_sessions', $uuid, $owner, $token ) ) );
		if ( 1 !== $changed ) {
			throw new \RuntimeException( 'Conversation claim expired. Reload before asking another question.', 409 );
		}
	}

	/** Deletes only an owned, idle conversation after explicit UI confirmation.
	 *
	 * @param string $uuid Session ID.
	 * @throws \RuntimeException For unavailable or busy sessions.
	 */
	public function delete( string $uuid ): void {
		global $wpdb;
		$owner = $this->owner();
		$this->valid( $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Owner-scoped deletion, cannot race an active inference.
		$result = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE uuid=%s AND owner=%d AND claim_until<UTC_TIMESTAMP()', $wpdb->prefix . 'scalyn_ai_sessions', $uuid, $owner ) ) );
		if ( 1 !== $result ) {
			throw new \RuntimeException( 'Conversation is busy or unavailable.' );
		}
	}

	/** Atomically consumes one bounded request without trusting browser counters.
	 *
	 * @param string $scope Fixed server-generated bucket.
	 * @param int    $limit Budget.
	 * @param int    $expiry Expiry timestamp.
	 * @throws \InvalidArgumentException For an invalid bucket.
	 * @throws \RuntimeException For exhausted or unavailable budgets.
	 */
	public function consume( string $scope, int $limit, int $expiry ): void {
		global $wpdb;
		AiAccess::require_admin();
		if ( ! preg_match( '/^[a-z0-9:_-]{1,96}$/D', $scope ) || $limit < 1 || $limit > 100 ) {
			throw new \InvalidArgumentException( 'Invalid usage bucket.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Seed a fixed bucket without resetting existing usage.
		$seeded = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (scope,used,expires_at) VALUES (%s,0,%s)', $wpdb->prefix . 'scalyn_ai_usage', $scope, gmdate( 'Y-m-d H:i:s', $expiry ) ) ) );
		if ( false === $seeded ) {
			throw new \RuntimeException( 'AI usage storage is unavailable.', 429 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic conditional increment; cannot bypass cap with MYSQL_CLIENT_FOUND_ROWS.
		$changed = $this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used=used+1 WHERE scope=%s AND used<%d', $wpdb->prefix . 'scalyn_ai_usage', $scope, $limit ) ) );
		if ( 1 !== $changed ) {
			throw new \RuntimeException( 'AI/MCP request limit reached or usage storage unavailable.', 429 );
		}
	}

	/** Returns an operational count, never a dollar estimate. */
	public function usage(): int {
		global $wpdb;
		AiAccess::require_admin();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact bounded usage read.
		$count = $this->quiet( static fn() => $wpdb->get_var( $wpdb->prepare( 'SELECT used FROM %i WHERE scope=%s', $wpdb->prefix . 'scalyn_ai_usage', 'ai-day:' . gmdate( 'Y-m-d' ) ) ) );
		return max( 0, min( 100, (int) $count ) );
	}

	/** Cleanup does not require a logged-in administrator. */
	public function cleanup(): void {
		global $wpdb;
		foreach ( array( 'scalyn_ai_sessions', 'scalyn_ai_usage' ) as $suffix ) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded owned retention; session expiry also enforced on reads.
				$this->quiet( static fn() => $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<=UTC_TIMESTAMP() LIMIT 100', $wpdb->prefix . $suffix ) ) );
			} catch ( \Throwable $error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fixed safe failure; other plugin cleanup must continue.
				error_log( 'Scalyn Mail Relay: AI retention unavailable.' );
			}
		}
	}

	/** Encodes only bounded repository-owned plaintext.
	 *
	 * @param array $data Conversation.
	 * @throws \RuntimeException For an oversized conversation.
	 */
	private function encode( array $data ): string {
		$data['owner'] = $this->owner();
		$data['site']  = get_current_blog_id();
		$json          = wp_json_encode( $data );
		if ( ! is_string( $json ) || strlen( $json ) > 1000000 || count( $data['messages'] ?? array() ) > 100 ) {
			throw new \RuntimeException( 'Conversation limit reached. Start a new chat.' );
		}
		return $this->cipher->encrypt( $json, 'ai-session' );
	}

	/** Authenticates storage before returning its exact contract.
	 *
	 * @param string $payload Ciphertext.
	 * @throws \RuntimeException For invalid private content.
	 */
	private function decode( string $payload ): array {
		$data = json_decode( $this->cipher->decrypt( $payload, 'ai-session' ), true );
		if ( ! is_array( $data ) || ( $data['owner'] ?? null ) !== $this->owner() || ( $data['site'] ?? null ) !== get_current_blog_id() || ! is_string( $data['title'] ?? null ) || ! is_array( $data['messages'] ?? null ) || count( $data['messages'] ) > 100 ) {
			throw new \RuntimeException( 'Conversation content is unavailable.' );
		}
		return $data;
	}

	/** Isolates database diagnostics from responses, logs and cron output.
	 *
	 * @param callable $operation Owned query.
	 * @throws \RuntimeException For database failures.
	 */
	private function quiet( callable $operation ): mixed {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$result = $operation();
			if ( ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException();
			}
			return $result;
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Private AI storage is unavailable.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}
}
