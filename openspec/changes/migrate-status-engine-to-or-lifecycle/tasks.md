# Tasks: migrate-status-engine-to-or-lifecycle

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> **Scope adjustment (2026-05-11):** investigation found that procest has NO
> `StatusTransitionService`, no `StateMachine*`, and no `WorkflowEngine*`
> service classes. State semantics are scattered as `STATUS_*` constants and
> conditional checks across `ParaferingService` (`STATUS_CONCEPT`,
> `STATUS_IN_PARAFERING`, etc.) and the bezwaar/voorstel workflow files —
> not a centralised engine that can be replaced in one PR.
>
> Per ADR-031 (schema-declarative business logic) the right migration is to
> move each schema's state machine into its `x-openregister-lifecycle`
> extension and let OR's lifecycle engine handle transitions. That requires
> per-schema work that does not fit a single focused PR.
>
> This commit records the umbrella rule + the per-schema migration plan: each
> stateful schema (voorstel, bezwaar, parafeerroute, hoorzitting) gets its own
> follow-up PR that adds `x-openregister-lifecycle` + removes the matching
> PHP `STATUS_*` constants.

---

## [procest] Voorstel Schema Migration

### P-1. Add x-openregister-lifecycle to Voorstel schema (M)

- [x] P-1.1 Add the `x-openregister-lifecycle` block for the `Voorstel` schema to
  `lib/Settings/procest_register.json`. The block MUST declare:
  - `property: "lifecycle"`, `initial: "concept"`
  - Transitions: `indienen` (concept → in_parafering, requires `VoorstelSubmitGuard`),
    `terugsturen` (in_parafering → teruggestuurd), `completeren` (in_parafering → geparafeerd),
    `afwijzen` (concept|in_parafering → afgewezen), `heropenen` (teruggestuurd → concept)
  - **files:** `lib/Settings/procest_register.json`
  - **Acceptance:** `openspec validate --strict` passes; lifecycle block is present on the
    `Voorstel` schema; repair step registers the schema without errors on the dev environment.

- [ ] P-1.2 Create `lib/Lifecycle/VoorstelSubmitGuard.php` with a single method
  `allows(array $object): bool` that validates the required `onderwerp` and `type`
  fields are non-empty.
  - **files:** `lib/Lifecycle/VoorstelSubmitGuard.php`
  - **Acceptance:** `composer check:strict` passes; the guard returns `false` when
    `onderwerp` is empty and `true` when all required fields are present.

---

## [procest] Parafeerroute Schema Migration

### P-2. Add x-openregister-lifecycle to Parafeerroute schema (M)

- [x] P-2.1 Add the `x-openregister-lifecycle` block for the `Parafeerroute` schema to
  `lib/Settings/procest_register.json`. The block MUST declare:
  - `property: "status"`, `initial: "actief"`
  - Transitions: `afronden` (actief → afgerond), `annuleren` (actief → geannuleerd)
  - **files:** `lib/Settings/procest_register.json`
  - **Acceptance:** Repair step registers the updated schema; `status` field accepts only
    `["actief", "afgerond", "geannuleerd"]`; invalid transitions return HTTP 422.

---

## [procest] Bezwaar Schema Migration

### P-3. Add x-openregister-lifecycle to Bezwaar schema (L)

- [x] P-3.1 Add the `x-openregister-lifecycle` block for the `Bezwaar` schema to
  `lib/Settings/procest_register.json`. The block MUST declare all ten AWB transitions
  from the `bezwaar-lifecycle` spec, with the `hoorzitting_overslaan` transition
  requiring `HoorzittingAfzienGuard`.
  - **files:** `lib/Settings/procest_register.json`
  - **Acceptance:** All ten transitions registered; sequential AWB progression allowed;
    out-of-sequence transitions (e.g. ontvangen → in_behandeling) rejected with HTTP 422.

- [ ] P-3.2 Create `lib/Lifecycle/HoorzittingAfzienGuard.php` with a single method
  `allows(array $object): bool` that returns `true` if `hoorrecht_afgezien === true`.
  - **files:** `lib/Lifecycle/HoorzittingAfzienGuard.php`
  - **Acceptance:** Guard returns `false` when `hoorrecht_afgezien` is `false` or absent;
    `composer check:strict` passes.

- [ ] P-3.3 Create `lib/Lifecycle/BezwaarDeadlineGuard.php` with a single method
  `allows(array $object): bool` that checks whether `processingDeadline` has not
  been exceeded for deadline-sensitive transitions.
  - **files:** `lib/Lifecycle/BezwaarDeadlineGuard.php`
  - **Acceptance:** Guard returns `false` when current date exceeds `processingDeadline`;
    returns `true` when deadline has not passed or is not set; `composer check:strict` passes.

---

## [procest] ParaferingService Cleanup

### P-4. Remove STATUS_* constants from ParaferingService (S)

- [ ] P-4.1 Remove the four `const STATUS_*` declarations from
  `lib/Service/ParaferingService.php`:
  - `STATUS_CONCEPT`, `STATUS_IN_PARAFERING`, `STATUS_TERUGGESTUURD`, `STATUS_GEPARAFEERD`
  - Update all references in `ParaferingService` itself to use string literals.
  - **files:** `lib/Service/ParaferingService.php`
  - **Acceptance:** No `const STATUS_` declarations remain; `composer check:strict` passes
    with no undefined constant errors.

- [ ] P-4.2 Update all callers of `ParaferingService::STATUS_*` constants across the codebase
  (controllers, listeners, tests) to use the string literals that match the
  `x-openregister-lifecycle` enum values.
  - **files:** any PHP file that references `ParaferingService::STATUS_*`
  - **Acceptance:** `grep -rn "STATUS_CONCEPT\|STATUS_IN_PARAFERING\|STATUS_TERUGGESTUURD\|STATUS_GEPARAFEERD"
    lib/` returns zero results; `composer check:strict` passes.

- [ ] P-4.3 Remove or refactor direct `saveObject` calls in `ParaferingService` that set
  `lifecycle` or `status` fields on voorstel/parafeerroute/bezwaar objects. Replace
  with PATCH requests through OR's object endpoint so OR's lifecycle engine validates
  the transition.
  - **files:** `lib/Service/ParaferingService.php`
  - **Acceptance:** No `saveObject` call with `lifecycle` or `status` mutation remains
    in the service; transitions are validated by OR; `composer check:strict` passes.

---

## [procest] Schema Hook Migration

### P-5. Replace Application.php lifecycle action listeners with schema hooks (M)

- [ ] P-5.1 Remove Application.php event listener registrations for any
  `ObjectCreatedEvent`/`ObjectUpdatedEvent` listeners that trigger automatic actions
  (notifications, task creation) on voorstel/parafeerroute/bezwaar lifecycle transitions.
  - **files:** `lib/AppInfo/Application.php`
  - **Acceptance:** No such listener registrations remain; `composer check:strict` passes.

- [ ] P-5.2 Add `x-openregister-hooks` entries to the affected schemas in
  `lib/Settings/procest_register.json` for the `updated` event, targeting the existing
  n8n workflows for parafering notification and completion actions. Use `mode: "async"`.
  - **files:** `lib/Settings/procest_register.json`
  - **Acceptance:** Schema hooks are declared on `Voorstel` and `Parafeerroute` schemas;
    the n8n workflow IDs match existing deployed workflows on the dev environment.

---

## [procest] Test Coverage

### P-6. PHPUnit lifecycle tests for all three schemas (M)

- [ ] P-6.1 Create `tests/Unit/Lifecycle/VoorstelLifecycleTest.php` covering:
  (a) `concept → in_parafering` succeeds when `VoorstelSubmitGuard` passes;
  (b) `concept → in_parafering` is blocked when guard returns false;
  (c) `geparafeerd → in_parafering` is rejected (invalid transition);
  (d) all five lifecycle enum values are valid strings.
  - **files:** `tests/Unit/Lifecycle/VoorstelLifecycleTest.php`
  - **Acceptance:** All test cases pass; `composer test` exits 0.

- [ ] P-6.2 Create `tests/Unit/Lifecycle/BezwaarLifecycleTest.php` covering:
  (a) sequential AWB status progression passes;
  (b) skipping `ontvankelijkheidstoets` (ontvangen → in_behandeling) is rejected;
  (c) `hoorzitting_overslaan` blocked when `hoorrecht_afgezien` is false;
  (d) `intrekken` accepted from `ontvangen`, `ontvankelijkheidstoets`,
      `in_behandeling`, and `hoorzitting_gepland`.
  - **files:** `tests/Unit/Lifecycle/BezwaarLifecycleTest.php`
  - **Acceptance:** All test cases pass; `composer test` exits 0.

- [ ] P-6.3 Create `tests/Unit/Lifecycle/ParaferingServiceStepTest.php` confirming
  that step-routing methods (`activateNextStep`, `getActiveStep`, `recordStepAction`)
  do NOT set `lifecycle` or `status` on the parent voorstel or parafeerroute objects.
  - **files:** `tests/Unit/Lifecycle/ParaferingServiceStepTest.php`
  - **Acceptance:** Mocked `ObjectService` confirms `saveObject` is not called with a
    `lifecycle`/`status` mutation from step-routing methods; `composer test` exits 0.

## REAL BLOCKER (re-spec 2026-06-15)

The boilerplate deferral note below ("target leaf not yet released") is STALE
and was a misdiagnosis. There is no "lifecycle leaf" to wait on — the blocker is
that OR has **no lifecycle / transition-guard engine** yet:

> Migrating procest's status engine (`status-transition-engine`,
> `StatusTransitionService`, the transition guards under
> `lib/Service/Transitions/`) to OR lifecycle needs OR to own:
> 1. a declarative **state/transition model** on the schema (allowed states +
>    allowed transitions), AND
> 2. a **runtime transition-guard engine** that enforces those transitions on
>    `saveObject` (rejecting an illegal state change server-side), with hooks for
>    app-supplied guard conditions (quorum, photo-gate, role checks).

OR currently has no such engine — status changes are plain field writes. Until
OR ships the lifecycle/transition-guard engine, procest's in-app status engine
stays the source of truth. NOT buildable today.

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
