<?php
/** Records migration DDL in isolated tests; does not emulate MySQL. */
function dbDelta( $queries = '', $execute = true ): array {
	$GLOBALS['_test_dbdelta_queries'][] = $queries;
	return array();
}
