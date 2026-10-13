<?php
/** AI shell; no private histories or credentials in initial markup.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay scalyn-ai" id="scalyn-ai" data-endpoint="<?php echo esc_url( rest_url( SCALYN_MAIL_RELAY_REST_NAMESPACE . '/ai' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
	<h1><?php esc_html_e( 'AI Assistant', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Ask about this plugin, recorded diagnostics and remediation. AI is advisory; deterministic checks remain authoritative.', 'scalyn-mail-relay' ); ?></p>
	<?php
	\Scalyn\MailRelay\Admin\Components\PageTabs::render(
		array(
			'chats'       => __( 'Chats', 'scalyn-mail-relay' ),
			'ai-settings' => __( 'Settings', 'scalyn-mail-relay' ),
			'mcp'         => __( 'MCP', 'scalyn-mail-relay' ),
		)
	);
	?>
	<?php
	if ( $notice ) :
		?>
		<p class="scalyn-finding__action" role="<?php echo $error ? 'alert' : 'status'; ?>"><?php echo esc_html( $notice ); ?></p><?php endif; ?>
	<div id="scalyn-panel-chats" class="scalyn-provider-tab-panel" data-provider-panel="chats">
		<p class="scalyn-finding__action"><?php esc_html_e( 'Private to your administrator account. Chats expire after 30 days without a new message. Never paste credentials, message bodies, subjects or recipients. Recognizable credentials are blocked, but detection is not perfect. Current evidence is refreshed with each question; older answers may be outdated.', 'scalyn-mail-relay' ); ?></p>
		<?php if ( ! $config['enabled'] || ! $config['encryption'] ) : ?>
			<p><?php esc_html_e( 'AI chat is disabled. Configure encrypted credentials, refresh models, select a model and consent in Settings.', 'scalyn-mail-relay' ); ?></p>
		<?php endif; ?>
		<div class="scalyn-ai-workspace">
			<aside class="scalyn-card"><h2><?php esc_html_e( 'Your chats', 'scalyn-mail-relay' ); ?></h2><p class="description"><?php esc_html_e( '20 most recently active conversations.', 'scalyn-mail-relay' ); ?></p><button type="button" class="button" data-ai-action="create"><?php esc_html_e( 'New chat', 'scalyn-mail-relay' ); ?></button><p data-ai-usage></p><div data-ai-sessions></div></aside>
			<section class="scalyn-card"><h2><?php esc_html_e( 'Conversation', 'scalyn-mail-relay' ); ?></h2>
			<div class="scalyn-actions"><button type="button" class="button" data-ai-action="rename"><?php esc_html_e( 'Rename', 'scalyn-mail-relay' ); ?></button><button type="button" class="button" data-ai-action="delete"><?php esc_html_e( 'Delete chat', 'scalyn-mail-relay' ); ?></button></div>
			<div data-ai-messages role="log" aria-live="polite"></div>
			<form data-ai-compose><label for="scalyn-ai-question"><?php esc_html_e( 'Question about this plugin', 'scalyn-mail-relay' ); ?></label><textarea id="scalyn-ai-question" maxlength="4000" rows="4" required <?php disabled( ! $config['enabled'] || ! $config['encryption'] ); ?>></textarea><button class="button button-primary" type="submit" <?php disabled( ! $config['enabled'] || ! $config['encryption'] ); ?>><?php esc_html_e( 'Send', 'scalyn-mail-relay' ); ?></button></form>
			<p data-ai-status role="status" aria-live="polite"></p>
			<button type="button" class="button" data-ai-action="diagnostics"><?php esc_html_e( 'Run diagnostics…', 'scalyn-mail-relay' ); ?></button>
			<p class="description"><?php esc_html_e( 'A separate confirmation is required. No setting changes, email sends or message retries are available through AI.', 'scalyn-mail-relay' ); ?></p>
			</section>
		</div>
	</div>
	<form method="post" data-tab-shared-form="ai-settings mcp">
		<?php wp_nonce_field( 'scalyn_ai_settings' ); ?>
		<input type="hidden" name="revision" value="<?php echo esc_attr( $config['revision'] ); ?>">
		<div id="scalyn-panel-ai-settings" class="scalyn-provider-tab-panel" data-provider-panel="ai-settings">
		<section class="scalyn-card"><h2><?php esc_html_e( 'OpenAI connection', 'scalyn-mail-relay' ); ?></h2>
		<p><?php echo esc_html( $config['encryption'] ? __( 'Server encryption available.', 'scalyn-mail-relay' ) : __( 'Server encryption unavailable. AI chat and new credential storage are blocked; email sending is unaffected.', 'scalyn-mail-relay' ) ); ?></p>
		<p><?php echo esc_html( $config['has_key'] ? __( 'An encrypted API key is saved.', 'scalyn-mail-relay' ) : __( 'No API key is saved.', 'scalyn-mail-relay' ) ); ?></p>
		<p><label for="scalyn-ai-key"><?php esc_html_e( 'Replace OpenAI API key (leave blank to keep it)', 'scalyn-mail-relay' ); ?></label><br><input id="scalyn-ai-key" name="key" type="password" autocomplete="new-password" maxlength="512" value=""></p>
		<p><label><input type="checkbox" name="remove_key" value="1"> <?php esc_html_e( 'Remove the saved key', 'scalyn-mail-relay' ); ?></label></p>
		<p><label for="scalyn-ai-model"><?php esc_html_e( 'Model', 'scalyn-mail-relay' ); ?></label><br><select id="scalyn-ai-model" name="model"><option value=""><?php esc_html_e( 'Choose a verified available model', 'scalyn-mail-relay' ); ?></option>
		<?php
		foreach ( $config['models'] as $model ) :
			?>
			<option value="<?php echo esc_attr( $model ); ?>" <?php selected( $config['model'], $model ); ?>><?php echo esc_html( $model ); ?></option><?php endforeach; ?></select></p>
		<p><button type="submit" class="button" name="ai_action" value="models"><?php esc_html_e( 'Refresh models / test saved key', 'scalyn-mail-relay' ); ?></button></p>
		<p class="scalyn-finding__action"><?php esc_html_e( 'Save a key first, then refresh models and explicitly choose one. Only adapter-supported, account-visible models appear. Refreshing tests key access; it does not test an actual generated answer. No model is selected or upgraded automatically.', 'scalyn-mail-relay' ); ?></p>
		<p><label><input type="checkbox" name="consent" value="1" <?php checked( $config['consent'] ); ?>> <?php esc_html_e( 'I consent to sending redacted chat text and allowed plugin evidence to OpenAI. store:false does not guarantee zero provider-side retention. This uses my OpenAI API account and may incur charges.', 'scalyn-mail-relay' ); ?></label></p>
		<p><label><input type="checkbox" name="enabled" value="1" <?php checked( $config['enabled'] ); ?>> <?php esc_html_e( 'Enable AI chat', 'scalyn-mail-relay' ); ?></label></p>
		<p><?php esc_html_e( 'Limits: 5 requests/minute per administrator; 100 requests/site/UTC day; 1,200 output tokens/reply. No automatic retries. Usage counts are not dollar-cost estimates.', 'scalyn-mail-relay' ); ?></p>
		</section></div>
		<div id="scalyn-panel-mcp" class="scalyn-provider-tab-panel" data-provider-panel="mcp">
		<section class="scalyn-card"><h2><?php esc_html_e( 'Read-only MCP', 'scalyn-mail-relay' ); ?></h2>
		<p><label><input type="checkbox" name="mcp" value="1" <?php checked( $config['mcp'] ); ?>> <?php esc_html_e( 'Enable authenticated read-only MCP for trusted external clients', 'scalyn-mail-relay' ); ?></label></p>
		<p><?php esc_html_e( 'Endpoint', 'scalyn-mail-relay' ); ?>: <code><?php echo esc_html( rest_url( SCALYN_MAIL_RELAY_REST_NAMESPACE . '/ai/mcp' ) ); ?></code></p>
		<p><?php esc_html_e( 'Requires public HTTPS and a WordPress administrator Application Password. Enter credentials directly into a compatible client using its Authorization header; never put passwords in a URL. Revoke passwords under Users → Profile. Application Passwords carry the account’s broader WordPress privileges: connect only trusted clients.', 'scalyn-mail-relay' ); ?></p>
		<p><?php esc_html_e( 'Supports stateless Streamable HTTP JSON responses, protocol 2025-06-18 and 2025-03-26, with 60 requests/minute per administrator. Tools expose only plugin help, configuration summaries, recorded diagnostics, configuration health and aggregate activity. No chat access or diagnostic execution. OAuth and ChatGPT connector compatibility are not promised.', 'scalyn-mail-relay' ); ?></p>
		<p class="scalyn-finding__action"><?php esc_html_e( 'The MCP server restricts its own tools, not what an external AI client discusses. Enabling MCP permits authenticated redacted evidence sharing independently of OpenAI chat consent.', 'scalyn-mail-relay' ); ?></p>
		</section></div>
		<p class="scalyn-actions"><button type="submit" class="button button-primary" name="ai_action" value="save"><?php esc_html_e( 'Save AI settings', 'scalyn-mail-relay' ); ?></button></p>
	</form>
</div>
