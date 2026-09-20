---
status: done
retrofit: true
---

# Berichtenbox Integration Specification

## Purpose

Send a citizen a message in their Mijn Overheid Berichtenbox, list the messages on a case, and follow what became of them. The transport is integriq's. dossiq composes the letter, hands it over through the adapter seam, and records what came back, including a refusal.

## Requirements

### REQ-001: Berichtenbox send / list / poll REST endpoints

The system SHALL expose three `@NoAdminRequired` JSON endpoints on `BerichtenboxController` — `send`, `messages`, and `poll` — that route to `BerichtenboxService` for message dispatch, case-scoped message listing, and per-message read-status polling respectively.

#### Scenario: Send a message
@e2e exclude a REST endpoint with no gesture of its own; asserted in tests/Unit/Controller/BerichtenboxControllerContractTest.php, testSendReturns200WithTheDispatchedMessageRecord

- WHEN a user POSTs to `send` with `caseId`, `bsn`, `subject`, `body`, `berichtTypeCode`, and optional `attachmentFileId`
- THEN the controller SHALL return HTTP 400 `{success: false, error: 'caseId is required'}` when `caseId` is empty
- AND it SHALL otherwise delegate to `BerichtenboxService::sendMessage` and return `{success: true, message: <result>}` on success or `{success: false, error: <validation message>}` on validation failure

#### Scenario: List messages for a case
@e2e exclude a REST endpoint with no gesture of its own; asserted in tests/Unit/Controller/BerichtenboxControllerContractTest.php, testMessagesReturnsTheCorrespondenceForTheNamedCase

- WHEN a user calls `messages` with `caseId`
- THEN the controller SHALL return `{success: true, messages: [...]}` containing every Berichtenbox message stored in OpenRegister for that case

#### Scenario: Poll read status
@e2e exclude a REST endpoint with no gesture of its own; asserted in tests/Unit/Controller/BerichtenboxControllerContractTest.php, testPollReturnsTheReadStatusForAnAuthorizedCaller

- WHEN a caller issues `GET /api/berichtenbox/messages/{messageId}`, the route named `berichtenbox#poll`
- THEN the controller SHALL return `{success: true, message: <updated record>}` reflecting the current read status
- AND the browser SHALL NOT call it: `src/services/berichtenboxApi.js` exposes `sendMessage` and `listMessages` only, because read status reaches the record by event through `DigitalPostDeliveredListener` rather than by a poll a handler triggers

#### Notes

- All three endpoints are `@NoAdminRequired` — they rely on case-level access checks performed downstream.
- The poll scenario used to read `poll/{messageId}`, which was never a route. When #627 routed the controller it chose `GET /api/berichtenbox/messages/{messageId}`, and the wording here was left behind. `src/services/berichtenboxApi.js` had been written from the old wording and posted to the path that did not exist. Corrected 2026-09-19, with `tests/vitest/berichtenboxApiRoutes.spec.js` reading the client against `appinfo/routes.php` so the same drift cannot go unseen again.

### REQ-002: BSN 11-proef + plain-text message validation

The system SHALL validate every outbound Berichtenbox message before dispatch: BSN MUST be a 9-digit string passing the Dutch 11-proef checksum; subject and body MUST be non-empty; body MUST contain no HTML markup.

#### Scenario: Reject invalid BSN
@e2e exclude server-side input validation with no browser surface; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- WHEN `sendMessage` is called with a `bsn` that is empty, non-numeric, not 9 digits, or fails the 11-proef
- THEN the service SHALL return `{error: 'BSN is verplicht voor berichten via Mijn Overheid'}` (empty) or `{error: 'Ongeldig BSN-nummer'}` (invalid checksum) without invoking the adapter

#### Scenario: Reject missing subject or body
@e2e exclude server-side input validation with no browser surface; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- WHEN `sendMessage` is called with an empty `subject` or `body`
- THEN the service SHALL return a validation-error payload (`'Onderwerp is verplicht'` / `'Berichttekst is verplicht'`) without invoking the adapter

#### Scenario: Reject HTML in body
@e2e exclude server-side input validation with no browser surface; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- WHEN `sendMessage` is called with a `body` that differs from `strip_tags($body)`
- THEN the service SHALL return `{error: 'Berichttekst mag alleen platte tekst bevatten'}` without invoking the adapter

#### Notes

- The 11-proef weights digits 1-8 by `(9 - i)` and subtracts digit 9, accepting only `sum % 11 === 0` AND `sum !== 0`.
- Multiple errors are accumulated and joined with `'; '` in the returned `error` field.

### REQ-003: Pluggable Berichtenbox adapter contract

The system SHALL define a `BerichtenboxAdapterInterface` with two methods — `sendMessage(bsn, subject, body, typeCode, ?attachment): array` returning at minimum `{messageId, status}`, and `getReadStatus(messageId): array` returning at minimum `{read: bool, readAt: ?datetime}` — so that production Berichtenbox API adapters can be swapped in without touching `BerichtenboxService`.

#### Scenario: The adapter is injected, not built inside the service
@e2e exclude a container binding read at boot; covered by tests/Unit/AppInfo/AdapterHonestyTest.php, testTheRegistrarBindsBothSeams

- WHEN `BerichtenboxService::sendMessage` or `pollReadStatus` needs to talk to Berichtenbox
- THEN it SHALL use the `BerichtenboxAdapterInterface` its constructor was given
- AND the binding SHALL come from `SubstitutableAdapterRegistrar`, which reads the `berichtenbox_adapter` app-config key
- AND an integrator SHALL be able to substitute a real adapter WITHOUT editing dossiq

#### Scenario: No adapter configured binds the integriq adapter, which refuses
@e2e exclude a container binding read at boot; covered by AdapterHonestyTest through the registrar

- WHEN `berichtenbox_adapter` is empty
- THEN the seam SHALL bind `IntegriqAdapter`, which dispatches integriq's send event and refuses with a named reason when it cannot send
- AND the Integrations page SHALL NOT read Simulated, because nothing is simulating

#### Scenario: The mock is selectable, and says so when it answers
@e2e exclude the same container binding; covered by AdapterHonestyTest and ConnectionsDeclarationTest

- WHEN `berichtenbox_adapter` names `mock` or `MockAdapter`
- THEN the seam SHALL bind `MockAdapter`, which generates a `mock-<hex>` message id, logs a redacted BSN, and reports messages as read 1h after send
- AND the registrar SHALL log a translated warning saying messages are simulated and nothing reaches Mijn Overheid
- AND the Integrations page SHALL carry a Berichtenbox card reading Simulated

#### Scenario: A named class that cannot serve the seam is an error
@e2e exclude the same container binding; covered by AdapterHonestyTest

- WHEN `berichtenbox_adapter` names a class that is absent or does not implement `BerichtenboxAdapterInterface`
- THEN the seam SHALL log an ERROR naming the setting and the class
- AND it SHALL bind the default adapter, because refusing to boot the app over one config value helps nobody

#### Notes

- Dossiq ships NO Berichtenbox transport and should not. The Berichtenbox is a per-customer contract with Logius, and outbound delivery is integriq's (ADR-041, `dossiq-delivers-nothing`). Dossiq owns composing the message and recording what happened to it.
- The defect this requirement was rewritten to close was NOT the missing transport. `getAdapter()` built `MockAdapter` inline behind the comment "For MVP, always use mock adapter": no registration, no config switch, and everything around it real. A send returned a message id and nothing left the instance.
- `MockAdapter::sendMessage` logs only the first 4 BSN digits, masking the rest with `*****` — PII handling pattern future production adapters should preserve.

### REQ-004: Read-status polling with 7-day unread-flagging

When `pollReadStatus(messageId)` is called, the system SHALL look up the stored Berichtenbox message in OpenRegister, call the adapter's `getReadStatus` with the stored `externalMessageId`, update local status to `read` (with `readAt`) when the adapter reports read, and otherwise stamp `readPolledAt` and re-flag status as `unread_flagged` when the message has been unread for 7 or more days.

#### Scenario: Mark message as read
@e2e exclude a polling path the cron drives, with no browser gesture; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- GIVEN a stored message with a non-empty `externalMessageId`
- WHEN `pollReadStatus` runs and the adapter returns `{read: true, readAt: <iso8601>}`
- THEN the service SHALL update the stored object with `status='read'`, `readAt=<adapter value>`, and `readPolledAt=<now>` and persist via `saveObject`

#### Scenario: Flag long-unread message
@e2e exclude a polling path the cron drives, with no browser gesture; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- GIVEN a stored message with `sentAt` 7+ days ago and adapter `read=false`
- WHEN `pollReadStatus` runs
- THEN the service SHALL set `status='unread_flagged'`, stamp `readPolledAt`, and persist; for `< 7` days the status SHALL be left untouched while `readPolledAt` is stamped

#### Scenario: Skip when not yet dispatched
@e2e exclude a polling path the cron drives, with no browser gesture; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- WHEN the stored message has an empty `externalMessageId`
- THEN the service SHALL return the record unchanged without contacting the adapter

#### Notes

- The 7-day threshold is hardcoded; making it configurable is a known follow-up.
- When OpenRegister is unavailable the service SHORT-CIRCUITS with `{error: 'OpenRegister not available'}` — Berichtenbox messages are not persisted anywhere else.

### REQ-005: Daily background polling job

The system SHALL register a `BerichtenboxReadStatusJob` extending `TimedJob` with an interval of `86400` seconds (daily) that the Nextcloud cron picks up and runs server-side.

#### Scenario: Job interval
@e2e exclude a TimedJob interval constant with no browser gesture; no unit test reaches it either, and that gap is reported as inherited debt rather than hidden

- WHEN `BerichtenboxReadStatusJob` is constructed
- THEN its parent `TimedJob` interval SHALL be set to `86400` seconds (24h)

#### Scenario: Run iterates unread messages
@e2e exclude a cron run with no browser gesture; covered by tests/Unit/BackgroundJob/BerichtenboxReadStatusJobTest.php, testEveryPendingMessageIsPolledByItsUuid

- WHEN the cron triggers `run($argument)`
- THEN the job SHALL log `'Dossiq: Running Berichtenbox read status poll'` and (future) iterate unread messages calling `BerichtenboxService::pollReadStatus` on each

#### Notes

- The current `run()` body is a logging-only scaffold — the per-message iteration is observed-but-stubbed. A real production rollout must implement the iteration or risk silently failing to update read status.

### Requirement: The Berichtenbox adapter reaches integriq (REQ-BB-20)

A letter dossiq sends leaves the building. `lib/Service/BerichtenboxAdapter/`
SHALL carry an adapter that dispatches integriq's digital post send event and
returns the reference integriq answers with, selectable through the existing
`berichtenbox_adapter` app config key. dossiq SHALL carry no Berichtenbox
protocol, no credentials and no provider choice.

#### Scenario: A sent message carries integriq's reference
@e2e exclude Backend adapter, covered by PHPUnit.

- **GIVEN** integriq is installed with its digital post seam available
- **AND** `berichtenbox_adapter` selects the integriq adapter
- **WHEN** a handler sends a message on a case
- **THEN** the send event SHALL be dispatched with the case, the recipient and
  the message
- **AND** the reference integriq answers with SHALL be stored on the message

### Requirement: The adapter refuses rather than simulating (REQ-BB-21)

When integriq is absent, or reports its digital post seam unavailable, the
adapter SHALL refuse with a named error. It SHALL NOT fall back to the mock
adapter. A simulated delivery that reads as a real one is how a citizen stops
being notified without anyone noticing.

#### Scenario: No integriq, no send
@e2e exclude Backend adapter, covered by PHPUnit.

- **GIVEN** `berichtenbox_adapter` selects the integriq adapter
- **AND** integriq is not installed
- **WHEN** a handler sends a message
- **THEN** the send SHALL be refused with an error naming integriq
- **AND** no message SHALL be recorded as sent

#### Scenario: An unavailable seam is refused, not mocked
@e2e exclude Backend adapter, covered by PHPUnit.

- **GIVEN** integriq is installed and reports its digital post seam unavailable
- **WHEN** a handler sends a message
- **THEN** the send SHALL be refused naming the unavailable seam
- **AND** the mock adapter SHALL NOT be used

### Requirement: integriq is resolved by fleet id, never by a literal (REQ-BB-22)

The adapter SHALL resolve integriq through `FleetAppId`, which answers to
both the current and the previous app id. It SHALL NOT contain a literal app
id or a literal integriq class name, because an id that nothing answers to
makes the integration a silent no-op rather than an error.

#### Scenario: A renamed integriq still resolves
@e2e exclude Backend resolution, covered by PHPUnit.

- **GIVEN** integriq installed under either of its two ids
- **WHEN** the adapter resolves it
- **THEN** it SHALL find the app under either id

### Requirement: Delivery arrives as an event (REQ-BB-23)

dossiq SHALL listen for integriq's delivery event and SHALL update the stored
message on the case, writing a timeline entry. dossiq SHALL NOT poll the
provider itself.

#### Scenario: A delivered letter updates the case
@e2e exclude Backend listener, covered by PHPUnit.

- **GIVEN** a message sent through the integriq adapter
- **WHEN** integriq dispatches its delivery event for that reference
- **THEN** the stored message's status SHALL read delivered
- **AND** the case timeline SHALL carry an entry naming the delivery

### Requirement: Digital post is sent through integriq and never simulated by dossiq

dossiq SHALL send a citizen's digital post by dispatching integriq's send
event and reading its result. A tracked message id SHALL be recorded as
sent. A structured refusal SHALL be recorded as not sent, with the reason.
A result slot that came back unanswered SHALL be treated as a refusal.
dossiq SHALL ship no digital post transport of its own. It keeps the
adapter seam and its mock, and what changes is that the mock is no longer
what an instance gets by forgetting: the default adapter dispatches
integriq's command and refuses with a named reason when integriq is absent.
That is the union of this change and `digital-post-reaches-integriq`, which
asked for the seam to be kept and the mock to stay selectable for
development. Deleting the seam outright was the one thing the two changes
disagreed on, and keeping it costs nothing once the default refuses: a
fallback that reports a delivery is indistinguishable from a delivery only
while it is the fallback.

#### Scenario: A letter integriq accepted is recorded as sent
@e2e exclude a cross-app dispatch with no reachable transport on this instance; covered by BerichtenboxServiceTest over a doubled dispatcher

- **GIVEN** a case with a recipient who has a digital post address
- **WHEN** a handler sends a letter and integriq answers with a tracked id
- **THEN** the message SHALL be recorded as sent, carrying that id
- **AND** the case timeline SHALL show it

#### Scenario: A refusal is never recorded as a delivery
@e2e exclude the same cross-app dispatch; covered by the same test with a refusing result

- **GIVEN** an instance whose digital post provider is not configured
- **WHEN** a handler sends a letter
- **THEN** the message SHALL be recorded as not sent
- **AND** the recorded reason SHALL be the one integriq gave

#### Scenario: An unanswered result is a refusal, not a send
@e2e exclude a defensive branch over the event contract; covered by the same test with an empty result slot

- **GIVEN** integriq is installed and its listener writes nothing into the result
- **WHEN** a handler sends a letter
- **THEN** the message SHALL be recorded as not sent
- **AND** the reason SHALL say that no provider answered

#### Scenario: An unset digital post source is refused before anything is dispatched
@e2e exclude a cross-app dispatch that must not happen; covered by IntegriqAdapterTest with the source config empty and the dispatcher expecting no call

- **GIVEN** an instance with integriq installed and no digital post source set
- **WHEN** a handler sends a letter
- **THEN** the send SHALL be refused without dispatching anything
- **AND** the refusal SHALL name the app-config key an administrator sets,
  because integriq resolves an empty source to nothing before it looks
  anything up and its own refusal names no key

#### Scenario: The integrations page reports a simulation only when a mock answers
@e2e exclude a declaration file read at boot by integriq's connection registry; covered by ConnectionsDeclarationTest and tests/vitest/integrationsPage.spec.js

- **GIVEN** an instance that has never set the Berichtenbox adapter key
- **WHEN** an administrator reads the integrations page
- **THEN** the Berichtenbox row SHALL NOT read Simulated, because the bound
  adapter refuses rather than simulating
- **AND** the row SHALL name the digital post source key that is still missing

#### Scenario: Without integriq the send is refused and names the missing app
@e2e exclude a missing-app branch that needs integriq uninstalled; covered by the service test with the fleet probe answering false

- **GIVEN** an instance with no integriq installed
- **WHEN** a handler sends a letter
- **THEN** the send SHALL be refused
- **AND** the refusal SHALL name integriq, resolved through the fleet app
  id rather than a literal name that a rename would silence

### Requirement: A handler can send digital post from the case

A case detail page SHALL offer Send digital post, opening the compose
dialog. The action SHALL be offered on a case whose recipient has a digital
post address, and the dialog SHALL show any refusal in the words the
provider gave, so a handler learns which credential is missing rather than
that sending failed.

#### Scenario: The compose dialog opens from the case

- **GIVEN** a case whose requester has a digital post address
- **WHEN** a handler opens the case
- **THEN** Send digital post SHALL be among the header actions
- **AND** pressing it SHALL open the compose dialog with that recipient

#### Scenario: The refusal reaches the handler in full

- **GIVEN** an instance whose provider refuses because a certificate is missing
- **WHEN** a handler sends a letter from the compose dialog
- **THEN** the dialog SHALL show the provider's own reason
- **AND** it SHALL NOT close as though the letter went out

### Requirement: Delivery and read status come back as events

dossiq SHALL listen for integriq's delivered event and SHALL update the
message and the case timeline on every status change, including a failure.
Sent and delivered SHALL be told apart on the case, because a letter in
flight and a letter received are different answers to what a citizen knows.

#### Scenario: A failed delivery is shown as failed
@e2e exclude a cross-app event with no browser gesture; covered by DigitalPostDeliveredListenerTest

- **GIVEN** a message recorded as sent
- **WHEN** integriq reports its status as failed
- **THEN** the case SHALL show it as failed
- **AND** the status SHALL NOT be dropped for being a status nobody wanted
