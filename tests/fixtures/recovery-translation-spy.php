<?php
/**
 * Translation spy loaded only inside the isolated recovery schedule test.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Recovery;

/**
 * Supplies translation arguments observed in this isolated process.
 *
 * @return array
 */
function &recovery_translation_calls(): array {
	static $calls = array();
	return $calls;
}

/**
 * Records namespace-local requests, matching the identity WordPress test stub.
 *
 * @param string $text Text to translate.
 * @param string $domain Translation domain.
 * @return string
 */
function __( string $text, string $domain = 'default' ): string {
	$calls   =& recovery_translation_calls();
	$calls[] = array( $text, $domain );
	return $text;
}
