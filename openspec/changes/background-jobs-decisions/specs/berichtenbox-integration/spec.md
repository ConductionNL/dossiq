## MODIFIED Requirements

### Requirement: REQ-001: Berichtenbox send / list / poll REST endpoints

The system SHALL expose two `@NoAdminRequired` JSON endpoints on `BerichtenboxController`, `send` and `messages`, that route to `BerichtenboxService` for message dispatch and case-scoped message listing. There SHALL be no read-status endpoint: Logius Berichtenbox has no read status (integriq spec `berichtenbox-client`).

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
@e2e tests/e2e/digital-post.spec.ts

- WHEN a caller asks `/api/berichtenbox/messages/{messageId}` or `/api/berichtenbox/poll/{messageId}` for a read status
- THEN no Berichtenbox controller SHALL answer
- AND delivery status SHALL reach the record by event, through `DigitalPostDeliveredListener`

#### Notes

- Both endpoints are `@NoAdminRequired`; they rely on case-level access checks performed downstream.
- The poll endpoint (`berichtenbox#poll`, `GET /api/berichtenbox/messages/{messageId}`) was removed on 2026-10-08 together with the read-status job. The client had already stopped calling it on 2026-09-19, and `tests/vitest/berichtenboxApiRoutes.spec.js` reads the client against `appinfo/routes.php`.

### Requirement: REQ-003: Pluggable Berichtenbox adapter contract

The system SHALL define a `BerichtenboxAdapterInterface` with one method, `sendMessage(bsn, subject, body, typeCode, ?attachment): array` returning at minimum `{messageId, status}`, so that production Berichtenbox API adapters can be swapped in without touching `BerichtenboxService`. The seam SHALL NOT ask for a read status, because the Berichtenbox has none.

#### Scenario: The adapter is injected, not built inside the service
@e2e exclude a container binding read at boot; covered by tests/Unit/AppInfo/AdapterHonestyTest.php, testTheRegistrarBindsBothSeams

- WHEN `BerichtenboxService::sendMessage` needs to talk to Berichtenbox
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
- THEN the seam SHALL bind `MockAdapter`, which generates a `mock-<hex>` message id and logs a redacted BSN
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
- `getReadStatus` was removed on 2026-10-08: the Berichtenbox has no read status to report.

## REMOVED Requirements

### Requirement: REQ-004: Read-status polling with 7-day unread-flagging

**Reason**: Logius Berichtenbox has no read status (integriq spec `berichtenbox-client`), so there is nothing to poll and an unread flag would be invented.
**Migration**: None. Delivery status keeps arriving by event through `DigitalPostDeliveredListener`.

### Requirement: REQ-005: Daily background polling job

**Reason**: `BerichtenboxReadStatusJob` had no read status to poll and was never scheduled.
**Migration**: The repair step `RetireUnscheduledBackgroundJobs` removes it from any job list that still has it.
