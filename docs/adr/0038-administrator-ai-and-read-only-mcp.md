# ADR 0038: Administrator AI assistant and read-only MCP

Status: accepted implementation direction, approved by Bernie on 2026-10-13.

AI is a separate opt-in advisory subsystem. Administrators (`manage_options`), not additive agency/support/client profiles, may configure it and use private site/user-bound sessions. OpenAI Responses is the first adapter; no mail provider interface changes. API keys use existing server-key authenticated encryption with an AI-specific context and never enter summaries, audit records, client markup or errors. Models are account-visible IDs intersected with an explicitly supported adapter allowlist. No model is selected automatically.

Chat requires encryption, a credential, a selected verified model and explicit external-data consent. The adapter uses only api.openai.com, bounded requests, no redirects, no external tools and `store:false`. This is not a zero-retention promise. Existing email delivery is independent of AI availability.

Chats are encrypted, site/user-bound, limited to 100 messages, and expire thirty days after the last message. Reads enforce expiry even if cron is delayed. Normalized evidence, never raw provider responses, recipients, subjects, message bodies or raw headers, is projected through one evidence service. Every question refreshes revision/freshness context. Instructions in evidence are untrusted; structured answer sections and citations are validated. Unrelated questions are declined. No guardrail is represented as perfect protection.

Only an explicit separate administrator confirmation may invoke the existing diagnostic service, with nonce and revision revalidation. It sends no test email. AI output cannot invoke settings, sending, retries or recovery actions.

MCP is separately disabled by default. Streamable HTTP serves bounded read-only help/configuration/diagnostic/health/activity tools, authenticated using WordPress Application Passwords over HTTPS. Origin, protocol and administrator checks are applied on each request. Application Passwords retain the principal's broader WordPress rights; use trusted clients and revoke unused passwords. No OAuth or ChatGPT connector compatibility is promised. External clients cannot read private chats or execute diagnostics through MCP. The plugin cannot constrain an external client's general conversations.

Limits: five AI requests per administrator per minute, 100 per site per UTC day, 1,200 output tokens, one active request per conversation, 4,000 characters per input. Retries never automatically duplicate billable calls. No cloud monitoring, multisite support, external MCP clients inside chat, files, web browsing or automatic repairs.

Use repositories for settings, encrypted sessions/messages and atomic counters. Migrations must be additive/idempotent; retention and opt-in uninstall deletion apply. Audit fixed operational outcomes only. Local/mock, real WordPress, remote CI, approved live OpenAI/MCP and owner acceptance are separate gates.

Sources verified 2026-10-13: https://developers.openai.com/api/reference/resources/responses/methods/create ; https://developers.openai.com/api/reference/resources/models/methods/list ; https://developers.openai.com/api/docs/guides/your-data ; https://modelcontextprotocol.io/specification/2025-06-18/basic/transports ; https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
