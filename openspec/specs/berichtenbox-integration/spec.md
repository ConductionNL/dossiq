---
status: done
retrofit: true
---

# Berichtenbox Integration Specification

## Purpose

@e2e exclude Pure backend REST API integration; no Playwright UI surface.

Provide Dossiq with the ability to send citizen-facing messages to a citizen's Mijn Overheid Berichtenbox, list messages linked to a case, and poll for read status — abstracted behind a pluggable adapter so the production Berichtenbox API can be swapped in for the development mock.

## Requirements

### REQ-001: Berichtenbox send / list / poll REST endpoints

The system SHALL expose three `@NoAdminRequired` JSON endpoints on `BerichtenboxController` — `send`, `messages`, and `poll` — that route to `BerichtenboxService` for message dispatch, case-scoped message listing, and per-message read-status polling respectively.

#### Scenario: Send a message

- WHEN a user POSTs to `send` with `caseId`, `bsn`, `subject`, `body`, `berichtTypeCode`, and optional `attachmentFileId`
- THEN the controller SHALL return HTTP 400 `{success: false, error: 'caseId is required'}` when `caseId` is empty
- AND it SHALL otherwise delegate to `BerichtenboxService::sendMessage` and return `{success: true, message: <result>}` on success or `{success: false, error: <validation message>}` on validation failure

#### Scenario: List messages for a case

- WHEN a user calls `messages` with `caseId`
- THEN the controller SHALL return `{success: true, messages: [...]}` containing every Berichtenbox message stored in OpenRegister for that case

#### Scenario: Poll read status

- WHEN a user calls `poll/{messageId}`
- THEN the controller SHALL return `{success: true, message: <updated record>}` reflecting the current read status

#### Notes

- All three endpoints are `@NoAdminRequired` — they rely on case-level access checks performed downstream.

### REQ-002: BSN 11-proef + plain-text message validation

The system SHALL validate every outbound Berichtenbox message before dispatch: BSN MUST be a 9-digit string passing the Dutch 11-proef checksum; subject and body MUST be non-empty; body MUST contain no HTML markup.

#### Scenario: Reject invalid BSN

- WHEN `sendMessage` is called with a `bsn` that is empty, non-numeric, not 9 digits, or fails the 11-proef
- THEN the service SHALL return `{error: 'BSN is verplicht voor berichten via Mijn Overheid'}` (empty) or `{error: 'Ongeldig BSN-nummer'}` (invalid checksum) without invoking the adapter

#### Scenario: Reject missing subject or body

- WHEN `sendMessage` is called with an empty `subject` or `body`
- THEN the service SHALL return a validation-error payload (`'Onderwerp is verplicht'` / `'Berichttekst is verplicht'`) without invoking the adapter

#### Scenario: Reject HTML in body

- WHEN `sendMessage` is called with a `body` that differs from `strip_tags($body)`
- THEN the service SHALL return `{error: 'Berichttekst mag alleen platte tekst bevatten'}` without invoking the adapter

#### Notes

- The 11-proef weights digits 1-8 by `(9 - i)` and subtracts digit 9, accepting only `sum % 11 === 0` AND `sum !== 0`.
- Multiple errors are accumulated and joined with `'; '` in the returned `error` field.

### REQ-003: Pluggable Berichtenbox adapter contract

The system SHALL define a `BerichtenboxAdapterInterface` with two methods — `sendMessage(bsn, subject, body, typeCode, ?attachment): array` returning at minimum `{messageId, status}`, and `getReadStatus(messageId): array` returning at minimum `{read: bool, readAt: ?datetime}` — so that production Berichtenbox API adapters can be swapped in without touching `BerichtenboxService`.

#### Scenario: Service resolves adapter via factory

- WHEN `BerichtenboxService::sendMessage` or `pollReadStatus` needs to talk to Berichtenbox
- THEN it SHALL call the private `getAdapter()` factory which returns an implementation of `BerichtenboxAdapterInterface`

#### Scenario: MVP ships with mock adapter

- WHEN no production adapter is configured (current state)
- THEN `getAdapter()` SHALL return `MockAdapter` which generates a `mock-<hex>` message id, logs a redacted BSN, and reports messages as read 1h after send

#### Notes

- The current factory unconditionally instantiates `MockAdapter` — settings-based adapter selection is observed-but-stubbed and remains a TODO.
- `MockAdapter::sendMessage` logs only the first 4 BSN digits, masking the rest with `*****` — PII handling pattern future production adapters should preserve.

### REQ-004: Read-status polling with 7-day unread-flagging

When `pollReadStatus(messageId)` is called, the system SHALL look up the stored Berichtenbox message in OpenRegister, call the adapter's `getReadStatus` with the stored `externalMessageId`, update local status to `read` (with `readAt`) when the adapter reports read, and otherwise stamp `readPolledAt` and re-flag status as `unread_flagged` when the message has been unread for 7 or more days.

#### Scenario: Mark message as read

- GIVEN a stored message with a non-empty `externalMessageId`
- WHEN `pollReadStatus` runs and the adapter returns `{read: true, readAt: <iso8601>}`
- THEN the service SHALL update the stored object with `status='read'`, `readAt=<adapter value>`, and `readPolledAt=<now>` and persist via `saveObject`

#### Scenario: Flag long-unread message

- GIVEN a stored message with `sentAt` 7+ days ago and adapter `read=false`
- WHEN `pollReadStatus` runs
- THEN the service SHALL set `status='unread_flagged'`, stamp `readPolledAt`, and persist; for `< 7` days the status SHALL be left untouched while `readPolledAt` is stamped

#### Scenario: Skip when not yet dispatched

- WHEN the stored message has an empty `externalMessageId`
- THEN the service SHALL return the record unchanged without contacting the adapter

#### Notes

- The 7-day threshold is hardcoded; making it configurable is a known follow-up.
- When OpenRegister is unavailable the service SHORT-CIRCUITS with `{error: 'OpenRegister not available'}` — Berichtenbox messages are not persisted anywhere else.

### REQ-005: Daily background polling job

The system SHALL register a `BerichtenboxReadStatusJob` extending `TimedJob` with an interval of `86400` seconds (daily) that the Nextcloud cron picks up and runs server-side.

#### Scenario: Job interval

- WHEN `BerichtenboxReadStatusJob` is constructed
- THEN its parent `TimedJob` interval SHALL be set to `86400` seconds (24h)

#### Scenario: Run iterates unread messages

- WHEN the cron triggers `run($argument)`
- THEN the job SHALL log `'Dossiq: Running Berichtenbox read status poll'` and (future) iterate unread messages calling `BerichtenboxService::pollReadStatus` on each

#### Notes

- The current `run()` body is a logging-only scaffold — the per-message iteration is observed-but-stubbed. A real production rollout must implement the iteration or risk silently failing to update read status.
