<?php
/**
 * Immutable, versioned report capture shared by future exporters.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Reporting;

defined( 'ABSPATH' ) || exit;

/** Contains only captured scalar/array data, never live repository references. */
final class ReportSnapshot {

	/**
	 * Creates a value object from the report repository's private projection.
	 *
	 * @param array $data Captured report payload.
	 */
	public function __construct( public readonly array $data ) {}
}
