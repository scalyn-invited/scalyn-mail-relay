<?php
/**
 * Credential-free named configuration management.
 *
 * @package ScalynMailRelay
 */

namespace Scalyn\MailRelay\Admin\Components;

use Scalyn\MailRelay\Core\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/** Reuses the same explicit workflow in Providers and wizard step 2. */
final class ConfigurationList {
	/**
	 * Supplies the approved settings read model.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( private readonly SettingsRepository $settings ) {}

	/**
	 * Renders no credentials and performs no writes.
	 *
	 * @param bool $wizard Whether returning to the wizard (consumed by the view).
	 */
	public function render( bool $wizard = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Consumed by the required view.
		if ( ! current_user_can( \Scalyn\MailRelay\Core\Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}
		$configurations = $this->settings->get_configurations();
		$locked         = \Scalyn\MailRelay\Agency\ManagedAccess::is_locked( 'providers' );
		require SCALYN_MAIL_RELAY_PATH . 'admin/views/configurations.php';
	}
}
