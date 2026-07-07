<?php
/**
 * Uninstall handler for Scalyn Mail Relay.
 *
 * @package ScalynMailRelay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Future uninstall cleanup should respect user settings.
// Do not remove data unless the admin has opted into full data removal.
