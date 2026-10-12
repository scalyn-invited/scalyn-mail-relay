<?php
/**
 * Managed policy and additive user access controls.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Pages;

use Scalyn\MailRelay\Agency\AgencyRepository;
use Scalyn\MailRelay\Agency\ManagedAccess;
use Scalyn\MailRelay\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Nonces protect requests; the repository independently checks authorization. */
final class AgencyPage {

	/**
	 * Uses a policy repository, never direct option writes.
	 *
	 * @param AgencyRepository $repository Policy and profile service.
	 */
	public function __construct( private readonly AgencyRepository $repository ) {}

	/**
	 * Renders safe settings and fixed feedback; never echoes rejected input.
	 *
	 * @throws \RuntimeException In isolated tests when wp_die rejects access or nonce.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_AGENCY ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage agency settings.', 'scalyn-mail-relay' ) );
		}
		$notice = '';
		$error  = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server-controlled method only.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'scalyn_agency_settings' );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict allowlist below; no arbitrary dispatch.
			$action = isset( $_POST['agency_action'] ) && is_string( $_POST['agency_action'] ) ? wp_unslash( $_POST['agency_action'] ) : '';
			try {
				if ( 'policy' === $action ) {
					$input = array();
					foreach ( array( 'managed', 'smtp', 'providers', 'critical' ) as $field ) {
						$input[ $field ] = isset( $_POST[ $field ] ) && '1' === $_POST[ $field ];
					}
					$this->repository->save_policy( $input );
				} elseif ( 'profile' === $action ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict digit validation before integer conversion.
					$user    = isset( $_POST['access_user_id'] ) && is_string( $_POST['access_user_id'] ) ? wp_unslash( $_POST['access_user_id'] ) : '';
					$profile = isset( $_POST['access_profile'] ) && is_string( $_POST['access_profile'] ) ? sanitize_key( wp_unslash( $_POST['access_profile'] ) ) : '';
					if ( ! preg_match( '/^[1-9][0-9]{0,9}$/D', $user ) ) {
						throw new \RuntimeException();
					}
					$this->repository->assign_profile( (int) $user, $profile );
				} else {
					throw new \RuntimeException();
				}
				$notice = __( 'Agency settings saved.', 'scalyn-mail-relay' );
			} catch ( \Throwable $exception ) {
				$error  = true;
				$notice = __( 'No changes saved. Check the policy, site member ID, access profile and your permissions, then try again.', 'scalyn-mail-relay' );
			}
		}
		$policy   = $this->repository->policy();
		$profiles = ManagedAccess::profiles();
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/agency.php';
	}
}
