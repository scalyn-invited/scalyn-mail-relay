<?php
/** AI shell; no private histories or credentials in initial markup.
 *
 * @package ScalynMailRelay
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap scalyn-mail-relay scalyn-ai" id="scalyn-ai" data-endpoint="<?php echo esc_url( rest_url( SCALYN_MAIL_RELAY_REST_NAMESPACE . '/ai' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-active-provider="<?php echo esc_attr( $config['provider'] ); ?>">
	<h1><?php esc_html_e( 'AI Assistant', 'scalyn-mail-relay' ); ?></h1>
	<p class="scalyn-lead"><?php esc_html_e( 'Ask about technical email setup, diagnostics, delivery, security and troubleshooting. AI is advisory; deterministic checks remain authoritative. Email drafting is not supported.', 'scalyn-mail-relay' ); ?></p>
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
		<?php if ( ! $config['enabled'] || ! $config['encryption'] ) : ?>
			<p><?php esc_html_e( 'AI chat is disabled. Select a model, save its provider key, verify model access and consent in Settings.', 'scalyn-mail-relay' ); ?></p>
		<?php endif; ?>
		<div class="scalyn-ai-workspace">
			<aside class="scalyn-card"><h2><?php esc_html_e( 'Your chats', 'scalyn-mail-relay' ); ?></h2><p class="description"><?php esc_html_e( '20 most recently active conversations.', 'scalyn-mail-relay' ); ?></p><button type="button" class="button" data-ai-action="create"><?php esc_html_e( 'New chat', 'scalyn-mail-relay' ); ?></button><p data-ai-usage></p><div data-ai-sessions></div></aside>
			<section class="scalyn-card scalyn-ai-conversation"><h2><?php esc_html_e( 'Conversation', 'scalyn-mail-relay' ); ?> <span class="scalyn-ai-provider" data-ai-conversation-provider></span></h2>
			<div data-ai-messages role="log" aria-live="polite" aria-busy="false"></div>
			<form data-ai-compose>
				<label for="scalyn-ai-question"><?php esc_html_e( 'Question about this plugin', 'scalyn-mail-relay' ); ?></label>
				<textarea id="scalyn-ai-question" maxlength="4000" rows="4" required <?php disabled( ! $config['enabled'] || ! $config['encryption'] ); ?>></textarea>
				<div class="scalyn-ai-compose-actions">
					<button class="button button-primary" type="submit" <?php disabled( ! $config['enabled'] || ! $config['encryption'] ); ?>><?php esc_html_e( 'Send', 'scalyn-mail-relay' ); ?></button>
					<div class="scalyn-actions"><button type="button" class="button" data-ai-action="rename"><?php esc_html_e( 'Rename', 'scalyn-mail-relay' ); ?></button><button type="button" class="button" data-ai-action="delete"><?php esc_html_e( 'Delete chat', 'scalyn-mail-relay' ); ?></button></div>
				</div>
			</form>
			<p data-ai-status role="status" aria-live="polite"></p>
			<div class="scalyn-ai-busy" data-ai-busy role="status" hidden><span class="scalyn-ai-busy__spinner" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Loading conversation…', 'scalyn-mail-relay' ); ?></span></div>
			</section>
		</div>
		<dialog class="scalyn-ai-dialog" data-ai-rename-dialog aria-labelledby="scalyn-ai-rename-heading">
			<form method="dialog" data-ai-rename-form>
				<h2 id="scalyn-ai-rename-heading"><?php esc_html_e( 'Rename chat', 'scalyn-mail-relay' ); ?></h2>
				<label for="scalyn-ai-rename-title"><?php esc_html_e( 'Conversation title', 'scalyn-mail-relay' ); ?></label>
				<input id="scalyn-ai-rename-title" data-ai-rename-title type="text" maxlength="80" required>
				<p class="scalyn-ai-dialog__error" data-ai-rename-error role="alert" hidden><?php esc_html_e( 'Enter a conversation title.', 'scalyn-mail-relay' ); ?></p>
				<div class="scalyn-actions"><button type="button" class="button" data-ai-rename-cancel><?php esc_html_e( 'Cancel', 'scalyn-mail-relay' ); ?></button><button type="submit" class="button button-primary"><?php esc_html_e( 'Save name', 'scalyn-mail-relay' ); ?></button></div>
			</form>
		</dialog>
		<dialog class="scalyn-ai-dialog" data-ai-delete-dialog aria-labelledby="scalyn-ai-delete-heading">
			<h2 id="scalyn-ai-delete-heading"><?php esc_html_e( 'Delete chat?', 'scalyn-mail-relay' ); ?></h2>
			<p><?php esc_html_e( 'Permanently delete this chat and its message history? This cannot be undone.', 'scalyn-mail-relay' ); ?></p>
			<p class="scalyn-ai-dialog__chat-title" data-ai-delete-title></p>
			<div class="scalyn-actions"><button type="button" class="button" data-ai-delete-cancel><?php esc_html_e( 'Cancel', 'scalyn-mail-relay' ); ?></button><button type="button" class="button scalyn-ai-dialog__delete" data-ai-delete-confirm><?php esc_html_e( 'Delete chat', 'scalyn-mail-relay' ); ?></button></div>
		</dialog>
		<p class="scalyn-finding__action"><?php esc_html_e( 'Private to your administrator account. Chats expire after 30 days without a new message. Never paste credentials, message bodies, subjects or recipients. Recognizable credentials are blocked, but detection is not perfect. Current evidence is refreshed with each question; older answers may be outdated.', 'scalyn-mail-relay' ); ?></p>
	</div>
	<form method="post" data-tab-shared-form="ai-settings mcp">
		<?php wp_nonce_field( 'scalyn_ai_settings' ); ?>
		<input type="hidden" name="revision" value="<?php echo esc_attr( $config['revision'] ); ?>">
		<div id="scalyn-panel-ai-settings" class="scalyn-provider-tab-panel" data-provider-panel="ai-settings">
		<section class="scalyn-card"><h2><?php esc_html_e( 'AI model and connection', 'scalyn-mail-relay' ); ?></h2>
		<p><?php echo esc_html( $config['encryption'] ? __( 'Server encryption available.', 'scalyn-mail-relay' ) : __( 'Server encryption unavailable. AI chat and new credential storage are blocked; email sending is unaffected.', 'scalyn-mail-relay' ) ); ?></p>
		<p><label for="scalyn-ai-model"><?php esc_html_e( 'Model', 'scalyn-mail-relay' ); ?></label><br><select id="scalyn-ai-model" name="model" data-ai-model data-encryption="<?php echo $config['encryption'] ? '1' : '0'; ?>" data-active-model="<?php echo esc_attr( $config['model'] ); ?>"><option value=""><?php esc_html_e( 'Choose a model to configure', 'scalyn-mail-relay' ); ?></option>
		<?php
		foreach ( \Scalyn\MailRelay\AI\AiCatalog::PROVIDERS as $provider => $label ) :
			?>
			<optgroup label="<?php echo esc_attr( $label ); ?>"><?php foreach ( \Scalyn\MailRelay\AI\AiCatalog::MODELS[ $provider ] as $model => $name ) : ?>
			<option value="<?php echo esc_attr( $model ); ?>" data-ai-provider="<?php echo esc_attr( $provider ); ?>" <?php selected( $config['model'], $model ); ?>><?php echo esc_html( $name . ( in_array( $model, $config['profiles'][ $provider ]['models'], true ) ? ' — access verified' : ' — setup required' ) ); ?></option>
			<?php endforeach; ?></optgroup><?php endforeach; ?></select></p>
		<p class="description"><?php esc_html_e( 'Models are shown before setup. Availability and free-tier eligibility depend on your API account; nothing is selected or upgraded automatically.', 'scalyn-mail-relay' ); ?></p>
		<?php
		foreach ( \Scalyn\MailRelay\AI\AiCatalog::PROVIDERS as $provider => $label ) :
			$profile = $config['profiles'][ $provider ];
			/* translators: %s: AI provider display name. */
			$key_label = $profile['has_key'] ? sprintf( __( 'Replace %s API key (leave blank to keep it)', 'scalyn-mail-relay' ), $label ) : sprintf( __( 'Enter %s API key', 'scalyn-mail-relay' ), $label );
			/* translators: %s: AI provider display name. */
			$consent_label = sprintf( __( 'I consent to sending redacted chat text and allowed plugin evidence to %s. This provider uses its own API account and may incur charges.', 'scalyn-mail-relay' ), $label );
			?>
		<fieldset data-ai-provider-settings="<?php echo esc_attr( $provider ); ?>" data-key-saved="<?php echo $profile['has_key'] ? '1' : '0'; ?>" data-verified-models="<?php echo esc_attr( wp_json_encode( $profile['models'] ) ); ?>">
		<legend><h3><?php echo esc_html( $label ); ?> <?php esc_html_e( 'settings', 'scalyn-mail-relay' ); ?></h3></legend>
		<p><?php echo esc_html( $profile['has_key'] ? __( 'An encrypted API key is saved.', 'scalyn-mail-relay' ) : __( 'No API key is saved.', 'scalyn-mail-relay' ) ); ?></p>
		<p><label for="scalyn-ai-key-<?php echo esc_attr( $provider ); ?>"><?php echo esc_html( $key_label ); ?></label><br><input id="scalyn-ai-key-<?php echo esc_attr( $provider ); ?>" name="key_<?php echo esc_attr( $provider ); ?>" type="password" autocomplete="new-password" maxlength="512" value="" data-ai-key></p>
		<p><label><input type="checkbox" name="remove_key_<?php echo esc_attr( $provider ); ?>" value="1" data-ai-remove> <?php esc_html_e( 'Remove this provider’s saved key', 'scalyn-mail-relay' ); ?></label></p>
		<p><label><input type="checkbox" name="consent_<?php echo esc_attr( $provider ); ?>" value="1" data-ai-consent <?php checked( $profile['consent'] ); ?>> <?php echo esc_html( $consent_label ); ?></label></p>
			<?php
			if ( 'gemini' === $provider ) :
				?>
				<p class="scalyn-finding__action"><?php esc_html_e( 'Gemini free-tier availability is model/account dependent. Unpaid-service content may be used to improve Google products. Do not send confidential information. Review Google’s current terms before consenting.', 'scalyn-mail-relay' ); ?></p>
				<?php
elseif ( 'openai' === $provider ) :
	?>
				<p class="description"><?php esc_html_e( 'OpenAI requests use store:false. This does not guarantee zero provider-side retention.', 'scalyn-mail-relay' ); ?></p>
				<?php
else :
	?>
	<p class="description"><?php esc_html_e( 'Use the full secret copied when you created the key in Anthropic Console. The existing-key list shows only a shortened hint; if you did not save the full secret, create a new key. API billing and provider retention terms apply.', 'scalyn-mail-relay' ); ?></p><?php endif; ?>
		</fieldset><?php endforeach; ?>
		<p><button type="submit" class="button" name="ai_action" value="models" data-ai-verify><?php esc_html_e( 'Verify saved key / model access', 'scalyn-mail-relay' ); ?></button></p>
		<p class="scalyn-finding__action" data-ai-setup-status><?php esc_html_e( 'Save the selected model and its key first, then verify access, review provider consent and explicitly enable chat. Model-list access does not prove available inference quota. Changing providers requires a new chat.', 'scalyn-mail-relay' ); ?></p>
		<p><label><input type="checkbox" name="enabled" value="1" data-ai-enable <?php checked( $config['enabled'] ); ?>> <?php esc_html_e( 'Enable AI chat', 'scalyn-mail-relay' ); ?></label></p>
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
