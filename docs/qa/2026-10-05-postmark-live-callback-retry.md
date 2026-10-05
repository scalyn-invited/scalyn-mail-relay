# Postmark HTTPS staging callback QA — 2026-10-05

Owner: Bernie. Scope: the approved public HTTPS staging site and Scalyn Mail Relay plugin only.

## Controlled retry and duplicate protection

One additional wizard test email was explicitly authorized and sent. Message UUID: `d2abf8e7-d856-44ab-99db-f2b3a25a70e5`.

| Observation | UTC timestamp / result |
| --- | --- |
| Provider accepted the message | 2026-10-05 04:14:08 |
| Delivery callback processed; temporary probe changed its normal 200 response to 503 | 2026-10-05 04:14:11 |
| Repeat callback processed with normal 200 response | 2026-10-05 04:14:12 |
| Final UI delivery evidence | Delivered (recipient server), 1 of 1 recipients |
| Final timeline | One acceptance entry and one delivery report; no duplicate delivery entry |
| Sending status | Accepted, unchanged by callback evidence |

Procedure:

1. Verified the active staging plugin path and copied its bootstrap before editing.
2. Prepared a temporary staging-host-only probe with a fixed expiry. The audit hook bound it to the first authorized wizard test only. An atomic option allowed exactly one 503 after the normal receiver completed; other messages, routes, methods, event types and non-200 responses were untouched.
3. Kept normal HTTPS, Basic authentication, source IP, correlation, retention and persistence checks intact. No credentials, message bodies or recipient addresses were added to logs or QA marker storage.
4. Sent the single authorized message, observed the two response markers, and inspected the full message timeline. No manual callback replay or second send was performed.
5. Removed the fault trigger, deleted only the three disposable QA marker options, and observed the cleanup confirmation.
6. Restored the original bootstrap and compared the server editor's complete saved text with the pre-edit original: exact match. Reloaded WordPress and verified the normal timeline without QA notices. The backup was moved to recoverable cPanel Trash, not permanently deleted.

Evidence limit: response timestamps came from the temporary application response hook, not a packet capture or provider-side HTTP attempt export. A repeat authenticated callback and unchanged single delivery report were observed. This does not establish the provider's full backoff schedule or email-send retry behavior. Recipient-server delivery does not prove inbox placement for this additional message.

## Validation

- Temporary fixture: PHP syntax check passed; 15 standalone guard/one-shot tests passed.
- Existing focused suite: `php vendor/phpunit/phpunit/phpunit --filter 'PostmarkWebhook|DeliveryEventRepository'` — 41 tests, 279 assertions passed.
- No permanent production-code change was made for this test. Hosted CI was not run for this QA-only record.

## Controlled bounce follow-up: passed

After Bernie reported account approval and authorized another test, the Postmark UI explicitly confirmed the account was approved and live. One wizard test was sent to `hardbounce@bounce-testing.postmarkapp.com`, the provider's documented controlled bounce address. No configuration or plugin source changes were needed.

| Observation | Result |
| --- | --- |
| Scalyn message UUID | `eb6faecc-df77-49fc-b5a7-6934d5c62de8` |
| Postmark message ID | `4d505158-74f6-4536-844e-38d403f9a69e` |
| Accepted | 2026-10-05 04:51:08 UTC |
| Provider outcome | Hard Bounce at 04:51:23 UTC (12:51:23 PM in provider UI) |
| Webhook evidence received | 2026-10-05 04:51:25 UTC |
| Scalyn projection | Bounce reported; delivery confirmed for 0 of 1 recipients, bounce reported for 1 |
| Timeline | One acceptance and one bounce report; no duplicate bounce entry observed |
| Original sending status | Accepted, unchanged |
| Privacy | Recipient and subject remain Not recorded in Scalyn |

The Postmark message metadata matched the Scalyn UUID exactly. The provider also displayed its normal Reactivate action for the bounced test address; no reactivation or additional send was performed. This is a controlled provider test, not a bounce involving a real recipient. A duplicate bounce callback was not deliberately replayed in this follow-up; the separate delivery-callback repeat test above covers the observed duplicate-protection scenario.

References:

- [Postmark bounce testing documentation](https://postmarkapp.com/support/article/1239-how-to-test-bounces)
- [Staging bounce timeline](https://jarmerskitchen.newwebsite.live/wp-admin/admin.php?page=scalyn-mail-relay-logs&message_uuid=eb6faecc-df77-49fc-b5a7-6934d5c62de8)
- [Postmark message](https://account.postmarkapp.com/servers/21061737/streams/outbound/messages/4d505158-74f6-4536-844e-38d403f9a69e)

The account-approval blocker for controlled bounce QA is resolved. This record does not itself close all Milestone 8 tickets or substitute for owner review, the remaining ticket acceptance criteria, or hosted CI.
