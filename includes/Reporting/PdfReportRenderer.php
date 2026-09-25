<?php
/**
 * Sandboxed PDF rendering of privacy-filtered snapshot data.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Reporting;

defined( 'ABSPATH' ) || exit;

/** Uses fixed markup and bundled fonts; no URLs or source HTML are interpreted. */
final class PdfReportRenderer {

	/**
	 * Renders an already privacy-filtered report without rereading evidence.
	 *
	 * @param array $data ReportExporter projection.
	 * @return string PDF attachment bytes.
	 * @throws \RuntimeException When rendering is unavailable.
	 */
	public function render( array $data ): string {
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			throw new \RuntimeException( 'PDF report unavailable.' );
		}
		try {
			// Do not silently reuse a different plugin's globally loaded renderer version.
			$loaded   = ( new \ReflectionClass( \Dompdf\Dompdf::class ) )->getFileName();
			$expected = realpath( dirname( __DIR__, 2 ) . '/vendor/dompdf/dompdf/src/Dompdf.php' );
			if ( false === $expected || realpath( $loaded ) !== $expected ) {
				throw new \RuntimeException();
			}
			$options = new \Dompdf\Options();
			$options->setIsRemoteEnabled( false );
			$options->setIsPhpEnabled( false );
			$options->setIsJavascriptEnabled( false );
			$options->setAllowedProtocols( array() );
			$options->setChroot( __DIR__ );
			$options->setDefaultFont( 'DejaVu Sans' );
			$options->setIsFontSubsettingEnabled( true );
			$options->setLogOutputFile( '' );
			$pdf = new \Dompdf\Dompdf( $options );
			$pdf->setPaper( 'A4', 'portrait' );
			$pdf->loadHtml( $this->html( $data ), 'UTF-8' );
			$pdf->render();
			$canvas = $pdf->getCanvas();
			$font   = $pdf->getFontMetrics()->getFont( 'DejaVu Sans', 'normal' );
			$canvas->page_text( 42, 810, 'Scalyn Mail Relay | Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, array( 0.35, 0.40, 0.46 ) );
			return $pdf->output();
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( 'PDF report unavailable.' );
		}
	}

	/**
	 * Builds fixed, escaped HTML; public for content and privacy verification.
	 *
	 * @param array $data Privacy-filtered report fields.
	 * @return string Complete renderer input.
	 */
	public function html( array $data ): string {
		$html     = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
@page { margin: 42pt 42pt 48pt; }
body { font-family: "DejaVu Sans", sans-serif; color: #203045; font-size: 9pt; line-height: 1.45; }
h1 { font-size: 23pt; margin: 0 0 6pt; color: #143c53; }
h2 { font-size: 14pt; color: #143c53; border-bottom: 1pt solid #bdd2dd; padding-bottom: 5pt; margin: 20pt 0 8pt; page-break-after: avoid; }
h3 { font-size: 10pt; margin: 12pt 0 4pt; page-break-after: avoid; }
p { margin: 5pt 0; }
.notice { background: #eef4f7; padding: 10pt; margin: 10pt 0; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; margin: 4pt 0 10pt; }
td { vertical-align: top; padding: 5pt 6pt; border-bottom: 0.5pt solid #dde5ea; overflow-wrap: break-word; }
td.label { width: 31%; color: #4a5969; }
.trend { font-size: 7pt; table-layout: auto; }
.trend th { text-align: left; padding: 5pt 3pt; background: #eef4f7; }
.trend td { padding: 5pt 3pt; }
thead { display: table-header-group; }
.muted { color: #576776; }
</style></head><body><h1>Scalyn Mail Relay</h1><p class="muted">Email operations report</p>
<div class="notice">Accepted means provider acknowledgement, not confirmed delivery or inbox placement. Configuration scores do not verify actual message authentication.</div>';
		$sections = array(
			'Capture details'                 => array_intersect_key( $data, array_flip( array( 'version', 'report_uuid', 'generated_at_utc' ) ) ),
			'Reporting period'                => $data['period'] ?? array(),
			'Privacy'                         => $data['privacy'] ?? array(),
			'Mail activity'                   => $data['mail'] ?? array(),
			'Health (site-wide)'              => $data['health'] ?? array(),
			'Diagnostic findings (site-wide)' => $data['diagnostics'] ?? array(),
			'Recommendations'                 => $data['recommendations'] ?? array(),
			'Evidence freshness'              => $data['freshness'] ?? array(),
			'Limitations'                     => $data['limitations'] ?? array(),
		);
		foreach ( $sections as $title => $section ) {
			$html .= '<h2>' . esc_html( $title ) . '</h2>' . $this->section( $section );
		}
		return $html . '</body></html>';
	}

	/**
	 * Keeps scalar groups readable and array records on independently flowing blocks.
	 *
	 * @param array $data A bounded snapshot section.
	 * @return string Escaped section markup.
	 */
	private function section( array $data ): string {
		if ( array() === $data ) {
			return '<p class="muted">No retained entries in this section; this is not a pass.</p>';
		}
		$html = '';
		$rows = '';
		foreach ( $data as $key => $value ) {
			$label = is_int( $key ) ? 'Entry ' . ( $key + 1 ) : ucwords( str_replace( '_', ' ', $key ) );
			if ( is_array( $value ) ) {
				if ( '' !== $rows ) {
					$html .= '<table>' . $rows . '</table>';
					$rows  = '';
				}
				$html .= '<h3>' . esc_html( $label ) . '</h3>' . ( 'daily_trend' === $key && array() !== $value ? $this->trend( $value ) : $this->section( $value ) );
			} else {
				$text = null === $value ? 'Unknown / unavailable (null)' : ( is_bool( $value ) ? ( $value ? 'Yes' : 'No' ) : (string) $value );
				if ( 'provider' === $key && null === $value ) {
					$text = 'All providers';
				} elseif ( 'provider' === $key && '' === $value ) {
					$text = 'Unattributed';
				}
				// Break long identifiers without removing any characters or permitting markup.
				$parts = preg_split( '/(.{32})/us', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
				$safe  = implode( '<wbr>', array_map( 'esc_html', false === $parts ? array( $text ) : $parts ) );
				$rows .= '<tr><td class="label">' . esc_html( $label ) . '</td><td>' . $safe . '</td></tr>';
			}
		}
		return $html . ( '' === $rows ? '' : '<table>' . $rows . '</table>' );
	}

	/**
	 * Prints daily aggregates as a compact table with repeated column headings.
	 *
	 * @param array $rows Bounded daily trend rows from the snapshot.
	 * @return string Escaped table including all daily fields.
	 */
	private function trend( array $rows ): string {
		$columns = array(
			'day'            => 'Day',
			'snapshot_count' => 'Count',
			'scored_count'   => 'Scored',
			'average_score'  => 'Average',
			'minimum_score'  => 'Min',
			'maximum_score'  => 'Max',
			'latest_at'      => 'Latest (site time)',
		);
		$html    = '<table class="trend"><thead><tr>';
		foreach ( $columns as $label ) {
			$html .= '<th>' . esc_html( $label ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $columns as $key => $label ) {
				$html .= '<td>' . esc_html( null === ( $row[ $key ] ?? null ) ? 'Unknown' : (string) $row[ $key ] ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table>';
	}
}
