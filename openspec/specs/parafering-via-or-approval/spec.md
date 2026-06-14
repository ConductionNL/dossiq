# parafering-via-or-approval Specification

## Purpose
Define the procest-side contract for routing parafering (signing-route) chains through
OpenRegister's `approval-workflow` capability (ADR-022). Procest's consumer-facing API
surface is preserved; chain-state, role enforcement, advance-on-approval and decision
history are delegated to OpenRegister's `ApprovalChain` / `ApprovalStep` entities via the
`ParaferingApprovalBridge` seam. The deprecated `Parafeerroute` schema remains read-only
until sunset.

## Requirements
### Requirement: Parafering Initiation Creates an OR ApprovalChain

SHALL be the requirement that when a voorstel is submitted for parafering, procest creates
an OR `ApprovalChain` with one step per parafeerder/adviseur in the route. No new
`Parafeerroute` rows are created after this migration ships.

#### Scenario: Voorstel submission creates OR ApprovalChain

- GIVEN a voorstel object with UUID `voorstel-abc` stored in a procest OR register
- AND a parafeerroute configured with three parafeerders in order: teamleider, afdelingshoofd, directeur
- WHEN the steller submits the voorstel for parafering
- THEN an `ApprovalChain` SHALL be created in OR with three steps (order 1, 2, 3)
- AND each step `role` SHALL be the NC group ID for the respective parafeerder/role
- AND the chain SHALL be accessible via `GET /api/approval-chains`
- AND no new `Parafeerroute` object SHALL be created in OR

#### Scenario: Existing parafeerroute rows remain read-only

- GIVEN a legacy `Parafeerroute` object with UUID `legacy-route-001` exists in OR
- WHEN procest code runs after migration
- THEN the legacy object SHALL remain readable via `GET /api/objects/{register}/{schema}/legacy-route-001`
- AND procest SHALL NOT create new `Parafeerroute` objects for any new voorstel submissions

---

### Requirement: Step Transitions Emit Via OR's Approval-Workflow API

SHALL be the requirement that all parafering step decisions (paraferen, terugsturen, adviseren,
overslaan) are emitted through OR's approval-step decision endpoints.

#### Scenario: Paraferen advances via OR approve endpoint

- GIVEN an ApprovalStep with `status: pending` for `voorstel-abc`
- AND the requesting user is a member of the step's `role` group
- WHEN the parafeerder clicks "Paraferen"
- THEN procest SHALL call `POST /api/approval-steps/{id}/approve` (or equivalent OR DI class)
- AND OR SHALL set `status: approved` and advance the next waiting step to `pending`
- AND the response from procest's parafering endpoint SHALL reflect the updated state

#### Scenario: Terugsturen emits via OR reject endpoint

- GIVEN an ApprovalStep with `status: pending` for `voorstel-abc`
- WHEN the parafeerder clicks "Terugsturen" with comment "Financiele paragraaf ontbreekt"
- THEN procest SHALL call `POST /api/approval-steps/{id}/reject` with the comment
- AND OR SHALL set `status: rejected` with `decidedAt` and `decidedBy`
- AND no next step SHALL be advanced

#### Scenario: Advisory step uses approve endpoint with meta

- GIVEN an ApprovalStep for an advisory step with `status: pending`
- WHEN the adviseur submits advice text
- THEN procest SHALL call `POST /api/approval-steps/{id}/approve` with a JSON comment
  containing `{"text": "<advice>", "_meta": {"action": "advised", "advice": "<advice>"}}`
- AND OR SHALL advance the next waiting step

---

### Requirement: Notifications Observe OR ApprovalStep Events

MUST be the requirement that `ParaferingNotificationService` listens on OR's ApprovalStep
state changes to determine when to notify the next parafeerder, rather than operating on
parafeer-local events. The notification payload (actor name, step label, voorstel title)
is unchanged from the user perspective.

#### Scenario: Next parafeerder notified after step approval

- GIVEN ApprovalStep order-1 for `voorstel-abc` is approved by the teamleider
- AND OR advances ApprovalStep order-2 to `pending`
- WHEN OR dispatches an `ApprovalStepApprovedEvent`
- THEN `ParaferingNotificationService` SHALL send a Nextcloud notification to the NC user(s)
  in the group bound to step order-2
- AND the notification text SHALL identify the voorstel and the requesting step

#### Scenario: Steller notified on terugsturen

- GIVEN ApprovalStep order-2 for `voorstel-abc` is rejected with comment "Paragraaf ontbreekt"
- WHEN OR dispatches an `ApprovalStepRejectedEvent`
- THEN `ParaferingNotificationService` SHALL notify the voorstel's steller
- AND the notification SHALL include the rejecting actor's name and the comment text

---

### Requirement: No New Parafeerroute Rows After Migration

MUST NOT be violated: after this migration ships, no code path in procest creates new
`Parafeerroute` objects in OR. The schema is deprecated. All new parafering chains are
OR `ApprovalChain` objects.

#### Scenario: Procest code does not write to deprecated schema

- GIVEN the migration is deployed
- WHEN any procest endpoint is called that initiates or advances a parafering flow
- THEN no OR object of schema type `Parafeerroute` SHALL be created or updated
- AND the OR object store for `Parafeerroute` SHALL contain only pre-migration rows

---

### Requirement: Existing Parafeerroute Rows Remain Readable Until Sunset

SHALL be the requirement that existing `Parafeerroute` rows written before the migration
are preserved read-only and accessible via the OR API until the schema is sunset (one major
procest release after migration). No historical backfill into OR ApprovalChain tables occurs.

#### Scenario: Legacy parafeerroute readable via OR API

- GIVEN `Parafeerroute` objects exist from before the migration
- WHEN `GET /api/objects/{register}/{schema}` is called for the parafeerroute schema
- THEN all pre-migration objects SHALL be returned with `200 OK`
- AND no write operations (POST, PUT, PATCH) SHALL succeed on the deprecated schema

---

### Requirement: End-to-End Test Exercises OR Approval-Workflow Store

MUST be the requirement that the procest test suite includes at least one end-to-end test
that creates a parafering chain via procest's API and verifies the chain and step records
exist in OR's approval-workflow store.

#### Scenario: E2E parafering test uses OR approval store

- GIVEN the test environment has OR's approval-workflow enabled
- WHEN the E2E test submits a voorstel for parafering and approves all steps
- THEN the test SHALL assert that `GET /api/approval-chains` returns the chain
- AND the test SHALL assert that all steps have `status: approved` in OR's approval tables
- AND the test SHALL NOT assert against any procest-local `Parafeerroute` table

