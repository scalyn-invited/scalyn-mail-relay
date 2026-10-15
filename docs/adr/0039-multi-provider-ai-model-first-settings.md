# ADR 0039: Model-first AI settings and isolated vendor profiles

Status: accepted direction approved by Bernie on 2026-10-15; implementation and live validation remain separate.

Extend the existing AI-specific contract with Gemini and Claude adapters, never mail ProviderInterface. A grouped supported-model catalogue is visible before credentials. Catalogue visibility is not account access: saving a candidate cannot enable chat until that vendor's encrypted key, account-visible supported-model verification and vendor-specific sharing consent exist. No automatic model upgrades, provider fallback or request retries. Site/admin budgets are shared across vendors.

Keep independent encrypted profiles for OpenAI, Gemini and Claude inside the existing repository-managed option. Preserve legacy OpenAI ciphertext and its authenticated context; normalize legacy settings without writes on reads, and persist the versioned format only on an explicit protected save. New contexts ai-gemini and ai-claude must not decrypt another provider's credentials. No table/schema change is required. Preserve the independent MCP opt-in. Key replacement/removal invalidates that profile's verified models and disables chat until explicitly re-enabled. Switching providers disables chat until an explicit save satisfying the new vendor's requirements.

Use fixed official HTTPS origins, credential headers, no redirects, bounded payloads and structured output. A shared answer contract enforces references, section types, content/secret bounds and fixed refusals after each adapter's response parsing. No provider error prose crosses UI/REST/audit boundaries. Existing redaction, evidence projection, advisory wording, diagnostic confirmation and read-only MCP remain authoritative. Google free-tier data handling and each provider's separate API billing must be disclosed; no zero-retention guarantee is made.

Bind nonempty conversations to their first answering provider; legacy nonempty histories belong to OpenAI. A provider change requires a new chat before any inference or quota consumption, including when switching back later. Earlier chats remain readable privately. Persist provider/model provenance on each assistant message. Encrypted retention, site/owner binding and uninstall opt-in are unchanged.

Model lists and generated answers are separate checks. A successful model-list request does not prove available inference quota. Mock tests are default; live tests require private key entry and explicit vendor/data consent. Owner review, live integrations and hosted CI are not inferred from local tests.

Starter models use explicit supported text/schema APIs. Gemini 3.5 Flash-Lite/3.8 Flash use minimal thinking. Claude Haiku 5.5 uses disabled thinking; Sonnet 5.5 uses between_tools because disabled is rejected. Both use low effort and no tools. Total output caps stay at 1,200 tokens; truncated replies fail safely rather than increasing limits or retrying.

Sources inspected: https://ai.google.dev/gemini-api/docs/models ; https://ai.google.dev/gemini-api/docs/structured-output ; https://ai.google.dev/api/models ; https://platform.claude.com/docs/en/models/overview ; https://platform.claude.com/docs/en/build-with-claude/structured-outputs ; https://platform.claude.com/docs/en/api/models/list ; https://developers.openai.com/api/docs/guides/structured-outputs
