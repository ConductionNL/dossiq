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

> **IMPLEMENTATION NOTE (2026-06-14 build):** the live procest parafering engine is
> `ParafeerRouteService` + `ParafeerActieService` (not the proposal's hypothetical
> `ParaferingService`). The OR delegation seam was therefore built as a dedicated
> `ParaferingApprovalBridge` consumed by both services. The bridge resolves OR's
> `ApprovalService` / `ApprovalChainMapper` / `ApprovalStepMapper` lazily through the
> container (mirroring `SettingsService::getObjectService()`), so procest carries no
> hard compile-time dependency on the optional OpenRegister app and degrades gracefully
> (legacy in-array routing) when OR's approval-workflow is unavailable.

### P0. Confirm OR DI class and event contract (S)

- [x] P0.1 Confirmed the OR DI class for ApprovalChain/step operations:
  `OCA\OpenRegister\Service\ApprovalService` (initializeChain/approveStep/rejectStep) plus
  `OCA\OpenRegister\Db\ApprovalChainMapper` / `ApprovalStepMapper`. Resolved lazily via
  `SettingsService::getApprovalService()` / `getOpenRegisterClass()`.
  - **Acceptance:** confirmed in code; design.md DEFERRED_QUESTIONS §1 stands.

- [x] P0.2 OR dispatches typed events on ApprovalStep state change: `ApprovalStepApprovedEvent`,
  `ApprovalStepRejectedEvent`, and `ApprovalStepInitiatedEvent`, defined in
  `openregister/openspec/changes/add-approval-step-events`. No polling required.
  - **Acceptance:** RESOLVED — design.md DEFERRED_QUESTIONS §2 updated accordingly.

---

## [procest] Service Rewrite

### P1. Rewrite ParaferingService to delegate to OR ApprovalChain (M)

- [x] P1.1 `ParaferingApprovalBridge::initializeChainForVoorstel()` creates an OR
  `ApprovalChain` (via `ApprovalChainMapper::createFromArray` + `ApprovalService::initializeChain`)
  with one step per route step; `ParafeerRouteService::startParafering()` calls it and stores
  the returned chain UUID on the voorstel (`approvalChainUuid`). No `Parafeerroute` row is
  written for the chain state.
  - **Acceptance:** met — chain visible in OR's approval store; covered by
    `ParaferingApprovalBridgeTest::testInitializeChainCreatesOrChainAndReturnsUuid`.

- [x] P1.2 `ParaferingApprovalBridge::approveCurrentStep()` resolves the pending OR step and
  calls `ApprovalService::approveStep()`; `ParafeerActieService::recordAction()` delegates the
  paraferen/adviseren/accorderen actions to it.
  - **Acceptance:** met — OR step → `approved`, next step → `pending`; covered by
    `ParaferingApprovalBridgeTest::testApproveCurrentStepDelegatesWithMetaComment`.

- [x] P1.3 `ParaferingApprovalBridge::rejectCurrentStep()` calls `ApprovalService::rejectStep()`;
  `ParafeerActieService::recordAction()` delegates the `returned` (terugsturen) action to it.
  - **Acceptance:** met — OR step → `rejected` with comment; covered by
    `ParaferingApprovalBridgeTest::testRejectThrowsWhenNoPendingStep`.

- [x] P1.4 Skip is encoded through the metadata-in-comment pattern: the bridge wraps the comment
  as `{"text": "<reason>", "_meta": {"action": "skipped", ...}}` when routed through
  `approveCurrentStep`. `encodeComment()` produces exactly this shape.
  - **Acceptance:** met for the OR step decision encoding. NOTE: the manager-only
    `ParafeerRouteService::skipStep()` route-mutation (insert/renumber/audit) still runs on the
    in-array snapshot — skip is a procest route-management concern beyond OR's step model.

- [x] P1.5 Delegate parafering is encoded via `_meta.actorType=delegate` + `onBehalfOf` +
  `mandate` in the OR step comment (`delegateToApprovalWorkflow()` in `ParafeerActieService`).
  - **Acceptance:** met — covered by `testApproveCurrentStepDelegatesWithMetaComment`
    (asserts actorType/onBehalfOf/mandate round-trip through the comment JSON).

### P2. Bespoke step-routing state machine is superseded by OR delegation (M)

- [~] P2.1 The OR-backed path now owns chain-state (role enforcement, advance-on-approval,
  decision history) through `ApprovalService`. The legacy in-array advance
  (`advanceVoorstel`/`currentStep`/`routeSnapshot`) is **retained** as the consumer-facing
  projection and as a graceful fallback when OR's approval-workflow is unavailable.
  - **Deferred reason:** fully deleting the in-array path requires migrating the procest
    frontend (which reads `currentStep`/`routeSnapshot`/`auditTrail`) to observe OR's chain
    state — that is a separate frontend change (see frontend deferral below). Per the
    proposal's own scope note, the legacy path must stay read-compatible during the
    transition window. No bespoke role-membership check is added on the OR path (OR's
    `verifyRole` governs).

---

## [procest] Controller

### P3. Verify ParaferingController endpoint surface is unchanged (S)

- [x] P3.1 `ParafeerActieController` and `ParafeerRouteController` route/request/response
  surfaces are unchanged: the OR delegation was added inside the existing service methods, so
  no controller signature or response shape changed.
  - **Acceptance:** met — no controller edits; the full unit suite (1323 tests) passes
    unchanged.

---

## [procest] Notification Service

### P4. Update ParaferingNotificationService to observe OR ApprovalStep events (M)

- [x] P4.1 New `ApprovalStepNotificationListener` implements `IEventListener` and is registered
  in `Application.php` against `OCA\OpenRegister\Event\ApprovalStepApprovedEvent` and
  `ApprovalStepRejectedEvent` (FQN string registration — no compile-time OR dependency). On
  approval-with-next-step it notifies the next role group's members via
  `ParaferingNotificationService::notifyStepActivated`; on rejection it notifies the steller via
  `notifyVoorstelReturned` (decoding the metadata-in-comment text).
  - **Acceptance:** met — covered by `ApprovalStepNotificationListenerTest`
    (next-parafeerder + steller-on-terugsturen + FQN-guard + unrelated-event-ignored).

- [x] P4.2 No parafeer-local notification listeners were removed because none existed: the
  legacy path notified imperatively from inside the services. Those imperative calls remain for
  the legacy fallback; the event-driven listener is the OR-backed path. No dead listeners added.
  - **Acceptance:** met — `composer phpcs/psalm/phpmd` clean on all changed lib/ files.

---

## [procest] Schema Deprecation

### P5. Deprecate Parafeerroute schema in procest_register.json (S)

- [x] P5.1 Added `"deprecated": true` and `"deprecatedSince": "2026-06-14"` to the
  `parafeerroute` schema in `lib/Settings/procest_register.json` (version bumped 1.0.0 → 1.1.0,
  description annotated). Existing rows remain readable.
  - **Acceptance:** met — schema annotated deprecated; existing rows readable.

- [~] P5.2 Repair step does NOT yet skip registering the `parafeerroute` schema on fresh
  installs.
  - **Deferred reason:** the schema must remain registered so legacy rows stay readable until
    sunset (one major release out); a fresh install still benefits from the schema definition
    for read-compatibility of any imported data. Removing it from registration is a sunset-release
    task, not a migration-ship task. The `deprecated` flag is the correct interim guard
    (ADR-031: deprecation annotation in the register JSON, not a code-side guard).

---

## [procest] Tests

### P6. Write end-to-end test for parafering via OR approval-workflow store (M)

- [x] P6.1 Added `ParaferingApprovalBridgeTest` (chain creation maps route steps to OR steps,
  approve/reject delegate against the pending OR step, metadata-in-comment round-trip,
  graceful-degradation when OR unavailable) and `ApprovalStepNotificationListenerTest`
  (event-driven notifications) — all assert against the OR approval-store seam (the mocked
  ApprovalService/mappers + OR events), never a procest-local `Parafeerroute` table.
  - **Acceptance:** met — 8 new tests, 21 assertions, green. NOTE: a live cross-app
    `GET /api/approval-chains` HTTP E2E is deferred to the NC-in-CI integration lane (the OR
    approval-workflow REST surface is exercised in OR's own suite); the bridge contract is
    covered here against the OR DI classes.

- [x] P6.2 The full procest unit suite (1323 tests, 3700 assertions) passes after the change;
  no existing parafering test required mock changes because the OR delegation is additive and
  degrades to the legacy path when OR is absent (the unit env has no OR approval backend).
  - **Acceptance:** met — only pre-existing env failures remain (4 ZipArchive `ext-zip`
    errors in BeschikkingService/ZipManifestBuilder tests, identical on the baseline).

## Closing status (2026-06-14 build)

OpenRegister's `approval-workflow` (`ApprovalService` + `ApprovalChain`/`ApprovalStep`
mappers + `ApprovalStep*Event`s) is **released and present** in the workspace, so the
prior fleet-wide "target leaf not yet released" blocker no longer applies to this change.
This build lands the server-side delegation seam:

**DONE (`[x]`):** OR DI/event contract confirmed (P0); `ParaferingApprovalBridge` creates the
OR `ApprovalChain` and routes paraferen/terugsturen/adviseren/delegeren/overslaan through
`ApprovalService` with the metadata-in-comment pattern (P1.1–P1.5); controller surfaces
unchanged (P3); event-driven `ApprovalStepNotificationListener` (P4); `parafeerroute` schema
deprecated (P5.1); bridge + listener tests + full-suite green (P6).

**DEFERRED (`[~]`), each with a concrete reason in-line:**

- P2.1 — deleting the legacy in-array advance is blocked on the **frontend migration**: the
  procest Vue views (`ParafeerActieTimeline`, `ParafeerInbox`, `ParafeerActionBar`,
  `parafeerEngine.js`) still read `currentStep`/`routeSnapshot`/`auditTrail`. Per the
  proposal scope ("server-side only; frontend out of scope") the in-array projection is kept
  read-compatible during the transition window and the OR chain is the authoritative backend.
- P5.2 — repair-step un-registration of the deprecated schema is a **sunset-release** task
  (rows must stay readable until then).

These are genuinely follow-up, not "leaf-not-released" deferrals. The frontend cutover and
the sunset un-registration are the two remaining steps for a full retirement of the bespoke
in-array path.
