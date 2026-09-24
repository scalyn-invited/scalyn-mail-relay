<?php
/**
 * Privacy-aware serialization of a captured report.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Reporting;

defined( 'ABSPATH' ) || exit;

/** No database reads or file persistence; both formats consume one capture. */
final class ReportExporter {

	/**
	 * Serializes a trusted repository snapshot, excluding evidence identifiers by default.
	 *
	 * @param ReportSnapshot $snapshot Captured report.
	 * @param string         $format Either csv or json.
	 * @param bool           $references Explicit opt-in for operational identifiers.
	 * @return string Complete download body.
	 * @throws \RuntimeException On encoding failure or unsupported format.
	 */
	public function encode( ReportSnapshot $snapshot, string $format, bool $references = false ): string {
		$data            = $this->privacy( $snapshot->data, $references );
		$data['privacy'] = array( 'evidence_references_included' => $references );
		if ( 'json' === $format ) {
			$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				throw new \RuntimeException( 'Report export unavailable.' );
			}
			return $json;
		}
		if ( 'csv' !== $format ) {
			throw new \RuntimeException( 'Report export unavailable.' );
		}
		$rows = array( array( 'path', 'type', 'value' ) );
		$this->flatten( $data, '', $rows );
		$output = '';
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				// Quote every cell, double quotes and neutralize formula prefixes even after whitespace.
				if ( preg_match( '/^[\s\x00-\x20\x{FEFF}]*[=+@\-]/u', $cell ) || preg_match( '/^[\t\r\n]/', $cell ) ) {
					$cell = "'" . $cell;
				}
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$output .= implode( ',', $cells ) . "\r\n";
		}
		return $output;
	}

	/**
	 * Removes operational identifiers recursively without changing observed values.
	 *
	 * @param array $data Trusted snapshot fields.
	 * @param bool  $references Whether identifiers are requested.
	 * @return array Privacy-filtered copy.
	 */
	private function privacy( array $data, bool $references ): array {
		foreach ( $data as $key => $value ) {
			if ( ! $references && in_array( $key, array( 'id', 'message_uuid', 'diagnostic_uuid', 'score_uuid', 'run_uuid' ), true ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->privacy( $value, $references );
			}
		}
		return $data;
	}

	/**
	 * Flattens all sections, retaining explicit nulls and empty arrays.
	 *
	 * @param array  $data Report data.
	 * @param string $path Parent path.
	 * @param array  $rows Output rows.
	 */
	private function flatten( array $data, string $path, array &$rows ): void {
		foreach ( $data as $key => $value ) {
			$next = '' === $path ? (string) $key : $path . '.' . $key;
			if ( is_array( $value ) && array() !== $value ) {
				$this->flatten( $value, $next, $rows );
			} else {
				$rows[] = array( $next, gettype( $value ), is_array( $value ) ? '[]' : ( null === $value ? 'null' : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ) ) );
			}
		}
	}
}
