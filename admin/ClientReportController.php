<?php
/**
 * Client-safe aggregate CSV export, separate from technical reports.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Agency\BrandingRepository;
use Scalyn\MailRelay\Audit\AuditEvent;
use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\HookNames;

defined( 'ABSPATH' ) || exit;

/** No arbitrary periods, provider scopes, references or message-level exports. */
final class ClientReportController {

	/** Checks request authority before any evidence reads.
	 *
	 * @param array  $input Unslashed request fields.
	 * @param string $method Server method.
	 * @return string Fixed CSV projection.
	 * @throws \RuntimeException When access is denied or data preparation fails.
	 */
	public function prepare( array $input, string $method ): string {
		if ( 'POST' !== $method || ! current_user_can( Capabilities::VIEW_CLIENT_REPORTS )
			|| ! is_string( $input['_wpnonce'] ?? null ) || ! wp_verify_nonce( $input['_wpnonce'], 'scalyn_client_report' ) ) {
			throw new \RuntimeException( 'Client report access denied.' );
		}
		try {
			$summary = ClientOverview::load();
			$body    = self::encode( $summary, ( new BrandingRepository() )->get()['name'] );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Client report unavailable.' );
		}
		try {
			do_action( HookNames::AUDIT_EVENT, new AuditEvent( 'client_report', 'prepared', wp_generate_uuid4() ) );
		} catch ( \Throwable $error ) {
			// Audit observers cannot leak details or turn preparation into a retry.
			return $body;
		}
		return $body;
	}

	/** Encodes only the fixed summary keys, with formula-safe CSV cells.
	 *
	 * @param array  $summary ClientOverview projection.
	 * @param string $brand Validated display name.
	 * @return string CSV, without raw evidence or identifiers.
	 */
	public static function encode( array $summary, string $brand ): string {
		$rows = array(
			'brand'                            => $brand,
			'configuration_health'             => $summary['health'],
			'configuration_score'              => $summary['score'] ?? 'unavailable',
			'health_recorded_site_time'        => $summary['recorded_at'] ?? 'unavailable',
			'activity_scope'                   => 'All retained site configurations; last seven days',
			'activity_start_site_time'         => $summary['activity']['start'] ?? 'unavailable',
			'activity_end_exclusive_site_time' => $summary['activity']['end'] ?? 'unavailable',
			'limitation'                       => 'Accepted is provider acknowledgement, not confirmed inbox delivery. Configuration health is not a delivery rate.',
		);
		foreach ( array( 'total', 'accepted', 'failed', 'other' ) as $key ) {
			$rows[ $key ] = $summary['activity'][ $key ] ?? 'unavailable';
		}
		$csv = "metric,value\r\n";
		foreach ( $rows as $key => $value ) {
			$value = (string) $value;
			if ( preg_match( '/^[\s\x00-\x20\x{FEFF}]*[=+@\-]/u', $value ) || preg_match( '/^[\t\r\n]/', $value ) ) {
				$value = "'" . $value;
			}
			$csv .= '"' . $key . '","' . str_replace( '"', '""', $value ) . '"' . "\r\n";
		}
		return $csv;
	}

	/** Emits a private attachment after complete preparation. */
	public function handle(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- prepare checks capability and nonce before reads.
			$input = wp_unslash( $_POST );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Server-controlled method, checked in prepare.
			$body = $this->prepare( $input, $_SERVER['REQUEST_METHOD'] ?? '' );
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'The summary could not be exported. Reload the page and check your access.', 'scalyn-mail-relay' ) );
		}
		if ( headers_sent() ) {
			wp_die( esc_html__( 'The summary cannot be downloaded because output has started.', 'scalyn-mail-relay' ) );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="email-summary.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Formula-safe CSV download, fixed projection and nosniff; HTML escaping would corrupt CSV.
		echo $body;
		exit;
	}
}
