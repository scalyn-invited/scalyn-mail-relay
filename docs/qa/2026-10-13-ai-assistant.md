# Milestone 10 AI implementation verification

Branch: `feature/m10-ai-assistant`, based on merged develop `43ecd02`. Existing local screenshot files were preserved and are excluded from public publication.

## Local automated and database evidence

- Full regression: 1,770 PHPUnit tests / 6,843 assertions passed without warnings after isolating the new test hook fixtures. The AI suite contributes 54 focused cases covering administrator-only access, encrypted credentials, model allowlisting, secret blocking, unsafe responses/citations, scope declines, owner/site isolation, claim fences, atomic limits, nonce/revision confirmation guards, MCP authentication/protocol/tool restrictions and repeatable migration failure recovery.
- JavaScript: 35 tests passed, including AI initialization without inference, cancellation of diagnostic confirmation, fresh-revision confirmation and text-node rendering.
- Full configured WPCS passed. PHP syntax passed for 368 files, JavaScript syntax for 12 files, and diff checks passed. Hosted CI is recorded on the PR head, not inferred from local results.
- `tests/manual/ai-storage-smoke.php`: twelve disposable real-SQL assertions passed. Verified repeatable schema, encrypted storage, owner filtering, concurrent claim rejection/renewal, quota caps, expiry, cleanup and deletion. Only randomly named fixture tables were created and removed. No AI API requests or mail were sent by the script.

## Local visual checks

- Administrator AI page loads with chat disabled, no saved key, explicit model selection and visible encryption/consent notices.
- Chats, Settings and MCP tabs select correctly; existing brand and navigation are preserved.
- Desktop Settings and 390 x 844 mobile MCP layouts inspected. Mobile body width 375 within viewport 390. Temporary viewport reset and QA tab closed.
- Local screenshots: `2026-10-13-ai-settings-desktop.png`, `2026-10-13-ai-mcp-mobile.png`. These are local evidence, not public repository assets.
- Synthetic encrypted chat rendered its advisory sections, evidence timestamp/revision, context-omission notice and local references on desktop and mobile. Keyboard Tab moved from Rename to Delete chat. At 390 px, content remained within 375 px. The exact synthetic conversation was removed after review; no real chat was altered. Chat screenshots remain local only.

## Explicit limitations and outstanding acceptance

No live OpenAI key was entered or API calls made. API adapter/answer behavior was mocked. A live model may produce mistakes; passing mock fixtures is not proof of adversarial robustness for a deployed model. The first sidebar read is bounded to twenty recent sessions. Streamed token output and attachment/voice chat are not included.

Public HTTPS MCP authentication and a real Codex/client connection were not exercised; OAuth and ChatGPT connector support are not claimed. Approved staging, a privately configured Application Password and explicit sharing authorization are required for that gate. Live diagnostic execution through the AI UI was not performed; confirmation/request boundaries are covered by tests, and the established diagnostic service remains the execution path.

No production/staging deployment or automatic merge. Owner acceptance and live gates remain open. Normal mail, provider credentials, routes and recovery policy were not changed during UI QA.
