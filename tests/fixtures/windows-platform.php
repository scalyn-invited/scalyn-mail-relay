<?php
/**
 * Isolated-process Windows branch simulation, not native Windows acceptance.
 * Load only in separate-process tests: constants/functions cannot be reset.
 */

namespace Scalyn\MailRelay\Admin {
	const PHP_OS_FAMILY = 'Windows';
}

namespace Scalyn\MailRelay\Core {
	const PHP_OS_FAMILY = 'Windows';

	// Windows has no POSIX extension, even when the Linux test runner does.
	function function_exists( string $function ): bool {
		return 'posix_geteuid' !== $function && \function_exists( $function );
	}
}
