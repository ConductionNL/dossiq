› **Scope note (2026-05-11):** see investigation block below. Clean delegation requires a coordinated rewrite of `ParaferingController` + `ParaferingService` + `ParaferingNotificationService` + the frontend that does not fit a single PR. This commit records the umbrella rule + migration plan; implementation lands as a follow-up sequence.

# Tasks: migrate-parafering-to-or-approval-workflow

All tasks are in the `procest` repo. Each task includes an estimate (S = half-day,
M = 1–2 days, L = 3+ days).

> **Investigation (2026-05-11):** `ParaferingService` works on array-shaped
> voorstel objects and never sees the underlying `ObjectEntity`. OR's
> `ApprovalService::initializeChain/approveStep/rejectStep` needs an
> `ObjectEntity`, which only the controller layer can resolve at persistence
> time. Migration therefore requires the four-step sequence below; each step
> would be its own follow-up PR:
>
> 1. `ParaferingController` — pass the persisted `ObjectEntity` into the service.
> 2. `ParaferingService` — accept the entity, call `ApprovalService`.
> 3. `ParaferingNotificationService` — convert from imperative
>    `notifyStepActivated()` callers to event listeners on
>    `ApprovalStepInitiatedEvent` / `ApprovalStepApprovedEvent` / `ApprovalStepRejectedEvent`.
> 4. Frontend Vue — observe the OR event stream rather than the in-array
>    `auditTrail` shape.
>
> Per ADR-022, every NEW parafering route MUST go through OR's
> `ApprovalService`. The legacy in-array path stays read-only-compatible
> during the transition window so existing voorstellen remain visible.

---

## [procest] Pre-migration Verification

### P0. Confirm OR DI class and event contract (S)

- [ ] P0.1 Confirm the exact PHP DI class (or REST API fallback) for ApprovalChain CRUD
  available for injection in procest (from umbrella task OR-1.1). Document the confirmed
  class name as a comment in the design.md DEFERRED_QUESTIONS section.
  - **Acceptance:** `design.md` DEFERRED_QUESTIONS section updated with confirmed class name.

- [ ] P0.2 OR dispatches typed events on ApprovalStep state change: `ApprovalStepApprovedEvent`,
  `ApprovalStepRejectedEvent`, and `ApprovalStepInitiatedEvent`, defined in
  `openregister/openspec/changes/add-approval-step-events`. No polling required.
  - **Acceptance:** RESOLVED — design.md DEFERRED_QUESTIONS §2 updated accordingly.

---

## [procest] Service Rewrite

### P1. Rewrite ParaferingService to delegate to OR ApprovalChain (M)

- [ ] P1.1 Replace `ParaferingService::createParafeerroute()` with a method that calls
  OR's ApprovalChain CRUD to create a chain with steps mapped from the route configuration.
  - **Acceptance:** Calling `createParafeerroute()` results in an OR `ApprovalChain` object
    visible at `GET /api/approval-chains`; no `Parafeerroute` object is created.

- [ ] P1.2 Replace `ParaferingService::advanceStep()` (or equivalent) with a method that
  calls `POST /api/approval-steps/{id}/approve` via OR's API or DI class.
  - **Acceptance:** Calling the advance method results in the OR step moving to `approved`
    and the next step moving to `pending`.

- [ ] P1.3 Replace `ParaferingService::returnVoorstel()` (or equivalent) with a method that
  calls `POST /api/approval-steps/{id}/reject` via OR.
  - **Acceptance:** Calling the return method results in the OR step moving to `rejected`
    with the comment stored.

- [ ] P1.4 Replace `ParaferingService::skipStep()` (or equivalent) with a method that calls
  `POST /api/approval-steps/{id}/approve` with JSON comment `{"_meta":{"action":"skipped"},"text":"<reason>"}`.
  - **Acceptance:** Skip is recorded as an OR step approval with the skip meta in the comment.

- [ ] P1.5 Replace `ParaferingService::delegateParafering()` (or equivalent) with a method
  that calls OR's approve endpoint with JSON comment carrying `actorType`, `onBehalfOf`,
  and `mandate` in `_meta`.
  - **Acceptance:** Delegate parafering is recorded as an OR step approval with delegation
    meta in the comment field.

### P2. Remove bespoke step-routing state machine from ParaferingService (M)

- [ ] P2.1 Delete all internal step-state-transition logic from `ParaferingService` that
  duplicates what OR's advance-on-approval provides (e.g. manual `pending`/`waiting` flips,
  role membership checks, "next step" cursor logic).
  - **Acceptance:** `ParaferingService` contains no bespoke step-routing state machine;
    `composer check:strict` passes.

---

## [procest] Controller

### P3. Verify ParaferingController endpoint surface is unchanged (S)

- [ ] P3.1 Confirm that all existing `ParaferingController` routes, request parameters,
  and response shapes are preserved after the service rewrite. Fix any drift between the
  controller and the rewritten service.
  - **Acceptance:** Existing procest parafering API integration tests pass without modification.

---

## [procest] Notification Service

### P4. Update ParaferingNotificationService to observe OR ApprovalStep events (M)

- [ ] P4.1 Update `ParaferingNotificationService` to register as an `IEventListener` on OR's
  `ApprovalStepApprovedEvent` and `ApprovalStepRejectedEvent` (defined in
  `openregister/openspec/changes/add-approval-step-events`). Register both listeners in
  `Application.php`; no polling is required.
  - **Acceptance:** After a step is approved via OR, the next parafeerder receives a
    Nextcloud notification; after a step is rejected, the steller receives a notification.

- [ ] P4.2 Remove any listeners or observers on parafeer-local events that no longer exist
  after the service rewrite.
  - **Acceptance:** No dead event listeners remain; `composer check:strict` passes.

---

## [procest] Schema Deprecation

### P5. Deprecate Parafeerroute schema in procest_register.json (S)

- [ ] P5.1 Add `"deprecated": true` and `"deprecatedSince": "<migration-release>"` to the
  `Parafeerroute` schema object in `lib/Settings/procest_register.json`.
  - **Acceptance:** The schema is annotated as deprecated; existing rows remain readable;
    `openspec validate --strict migrate-parafering-to-or-approval-workflow` passes.

- [ ] P5.2 Update the repair step (or install listener) to skip `Parafeerroute` schema
  registration on new installs after migration.
  - **Acceptance:** Fresh procest install does not create a `Parafeerroute` schema in OR.

---

## [procest] Tests

### P6. Write end-to-end test for parafering via OR approval-workflow store (M)

- [ ] P6.1 Write an E2E test (PHPUnit + OR integration) that: (a) submits a voorstel for
  parafering via procest's API, (b) approves all steps via procest's API, (c) asserts that
  `GET /api/approval-chains` returns the chain with all steps `approved`.
  - **Acceptance:** Test passes; test asserts against OR's approval store, not against any
    procest-local `Parafeerroute` table.

- [ ] P6.2 Verify existing procest parafering unit tests still pass after the service rewrite.
  Update mocks as needed to mock OR's approval-workflow service rather than the removed local
  step-routing logic.
  - **Acceptance:** `composer check:strict` passes; no skipped tests.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.
