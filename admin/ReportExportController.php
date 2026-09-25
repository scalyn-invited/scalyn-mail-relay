<?php
/**
 * Authorized report download boundary.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin;

use Scalyn\MailRelay\Core\Capabilities;
use Scalyn\MailRelay\Core\Plugin;
use Scalyn\MailRelay\Core\SettingsRepository;
use Scalyn\MailRelay\Database\ReportSnapshotRepository;
use Scalyn\MailRelay\Reporting\ReportExporter;
use Scalyn\MailRelay\Reporting\ReportPeriod;

defined( 'ABSPATH' ) || exit;

/** Downloads only; no server-side report storage. */
final class ReportExportController {

	/**
	 * Authorizes and builds the entire response before sending any download bytes.
	 *
	 * @param array  $input Unslashed POST fields.
	 * @param string $method HTTP method.
	 * @return array Body, MIME and fixed filename.
	 * @throws \RuntimeException For denied, invalid or unavailable reports.
	 */
	public function prepare( array $input, string $method ): array {
		if ( ! current_user_can( Capabilities::EXPORT_REPORTS ) || 'POST' !== $method ) {
			throw new \RuntimeException( 'Report export denied.', 403 );
		}
		if ( ! isset( $input['_wpnonce'] ) || ! is_string( $input['_wpnonce'] ) || ! wp_verify_nonce( $input['_wpnonce'], 'scalyn_export_report' ) ) {
			throw new \RuntimeException( 'Report export denied.', 403 );
		}
		foreach ( array( 'start', 'end', 'format', 'provider_scope', 'provider' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || strlen( $input[ $key ] ) > 100 ) {
				throw new \RuntimeException( 'Invalid report options.', 400 );
			}
		}
		if ( ! in_array( $input['format'], array( 'csv', 'json' ), true ) || ! in_array( $input['provider_scope'], array( 'all', 'unattributed', 'specific' ), true ) || ( isset( $input['references'] ) && '1' !== $input['references'] ) ) {
			throw new \RuntimeException( 'Invalid report options.', 400 );
		}
		$provider = null;
		if ( 'unattributed' === $input['provider_scope'] ) {
			$provider = '';
		} elseif ( 'specific' === $input['provider_scope'] ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $input['provider'] ) ) {
				throw new \RuntimeException( 'Invalid report options.', 400 );
			}
			$provider = $input['provider'];
		}
		try {
			$period = new ReportPeriod( $input['start'], $input['end'] );
		} catch ( \InvalidArgumentException $error ) {
			throw new \RuntimeException( 'Invalid report options.', 400 );
		}
		try {
			$container = Plugin::instance()->container();
			$snapshot  = $container->get( ReportSnapshotRepository::class )->capture( $period, $provider, $container->get( SettingsRepository::class )->get_diagnostic_schedule() );
			$body      = ( new ReportExporter() )->encode( $snapshot, $input['format'], isset( $input['references'] ) );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'Report export unavailable.', 503 );
		}
		return array(
			'body' => $body,
			'mime' => 'csv' === $input['format'] ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8',
			'name' => 'scalyn-mail-relay-report.' . $input['format'],
		);
	}

	/** Handles authenticated admin-post downloads and terminates the response. */
	public function handle(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- prepare enforces capability, nonce and strict field validation before reads.
			$input = wp_unslash( $_POST );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Server-controlled method, checked in prepare.
			$response = $this->prepare( $input, $_SERVER['REQUEST_METHOD'] ?? '' );
		} catch ( \RuntimeException $error ) {
			wp_die( esc_html__( 'Report could not be exported. Check your permission, reload the Reports page and verify the selected options. If the problem persists, try again later.', 'scalyn-mail-relay' ), '', array( 'response' => absint( $error->getCode() ) ) );
		}
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Report download unavailable because output has already started.', 'scalyn-mail-relay' ) );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $response['mime'] );
		header( 'Content-Disposition: attachment; filename="' . $response['name'] . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Serialized JSON or formula-protected CSV, attachment with nosniff; HTML escaping would corrupt the format.
		echo $response['body'];
		exit;
	}
}
