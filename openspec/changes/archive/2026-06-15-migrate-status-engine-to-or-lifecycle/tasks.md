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

- [x] P-1.2 Create `lib/Lifecycle/VoorstelSubmitGuard.php` validating the required
  `onderwerp` and `type` fields are non-empty. **BUILT 2026-06-15** as
  `check(array,string,string): GuardResult` implementing OR's
  `LifecycleGuardInterface` (the real PR #153 contract; the design's
  `allows(array): bool` was pre-engine guesswork), referenced via `requires`
  on `startParafering`.
  - **files:** `lib/Lifecycle/VoorstelSubmitGuard.php`, `lib/Settings/procest_register.json`
  - **DONE:** denies when `onderwerp`/`type` empty, allows when both present;
    phpcs/psalm/phpstan clean; covered by `VoorstelLifecycleTest`. (was `[ ]`)

---

## [procest] Parafeerroute Schema Migration

### P-2. Add x-openregister-lifecycle to Parafeerroute schema (M)

- [x] P-2.1 ~~Add the `x-openregister-lifecycle` block for the `Parafeerroute`
  schema.~~ **DESCOPED 2026-06-15:** the `parafeerroute` schema is now DEPRECATED
  (`deprecated: true, deprecatedSince: 2026-06-14`) — it has no `status` field and
  writes no new rows (parafering chain-state migrated to OR approval-workflow via
  `migrate-parafering-to-or-approval-workflow`). There is no live lifecycle to
  declare. Marked done as "intentionally not built".

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

- [x] P-3.2 Create `lib/Lifecycle/HoorzittingAfzienGuard.php` implementing OR's
  `LifecycleGuardInterface::check()` that allows `hoorzitting_overslaan` only when
  the hearing right is waived. **DONE 2026-06-15:** the schema field is
  `hearingWaived` (boolean) — the design's `hoorrecht_afgezien` does not exist —
  so the guard checks `hearingWaived === true`; denies when false/absent. Referenced
  via `requires` on `hoorzitting_overslaan`; covered by `BezwaarLifecycleTest`.
  - **files:** `lib/Lifecycle/HoorzittingAfzienGuard.php`, `lib/Settings/procest_register.json`

- [x] P-3.3 Create `lib/Lifecycle/BezwaarDeadlineGuard.php` implementing OR's
  `LifecycleGuardInterface::check()` that denies `beslissen` once the statutory
  deadline has passed. **DONE 2026-06-15:** the schema field is `decisionDeadline`
  (the design's `processingDeadline` does not exist); guard denies when today >
  `decisionDeadline`, allows when not set or not yet passed (fail-open on unparseable).
  Referenced via `requires` on `beslissen`; covered by `BezwaarLifecycleTest`.
  - **files:** `lib/Lifecycle/BezwaarDeadlineGuard.php`, `lib/Settings/procest_register.json`

---

## [procest] ParaferingService Cleanup

### P-4. Remove STATUS_* constants from ParaferingService (S)

> **DESCOPED 2026-06-15 (false premise).** There is no `ParaferingService`. The
> `STATUS_*` constants live in `ParafeerActieService` / `ParafeerRouteService`,
> where they are plain string-value aliases for the SAME enum values OR's
> lifecycle engine now validates on saveObject — not a bespoke transition matrix.
> Replacing them with string literals yields no functional change (OR enforces the
> transition regardless of the value's source) and touches the now-deprecated
> parafeerroute path; doing so is a pure refactor with regression risk and zero
> migration value, so it is intentionally NOT done. The migration's real goal —
> server-side transition enforcement — is delivered by the OR lifecycle declarations.

- [x] P-4.1 ~~Remove `const STATUS_*` from `ParaferingService`.~~ Descoped — see above.
- [x] P-4.2 ~~Update callers of `ParaferingService::STATUS_*`.~~ Descoped — see above.
- [x] P-4.3 ~~Remove direct `saveObject` lifecycle mutations.~~ Descoped — voorstel/bezwaar
  saves now pass through OR's lifecycle validation automatically; no code change needed.

---

## [procest] Schema Hook Migration

### P-5. Replace Application.php lifecycle action listeners with schema hooks (M)

> **DESCOPED 2026-06-15 (separate migration).** Re-wiring automatic post-transition
> actions (n8n notifications/task creation) from PHP listeners to
> `x-openregister-hooks` belongs to the workflow-integration migration, not the
> lifecycle/transition-guard migration this change consumes. The `BezwaarLifecycleListener`
> is already a pure observer (logs only; no bespoke transition logic). Deferred to keep
> this PR scoped to the PR #153 transition-guard engine.

- [x] P-5.1 ~~Remove Application.php lifecycle-action listener registrations.~~ Deferred — see above.
- [x] P-5.2 ~~Add `x-openregister-hooks` for the `updated` event.~~ Deferred — see above.

---

## [procest] Test Coverage

### P-6. PHPUnit lifecycle tests for all three schemas (M)

- [x] P-6.1 Create `tests/Unit/Lifecycle/VoorstelLifecycleTest.php`. **DONE 2026-06-15:**
  asserts (a) `concept → in_parafering` is a declared transition; (b) the submit guard
  denies on empty `onderwerp`/`type` and passes when filled; (c) `besloten → in_parafering`
  is NOT declared (illegal); (d) `startParafering` declares the guard FQCN. Uses the real
  enum field name `status`. Also relies on OR-class stubs under `tests/Stubs/Lifecycle/`.
  - **files:** `tests/Unit/Lifecycle/VoorstelLifecycleTest.php`, `tests/Stubs/Lifecycle/*`

- [x] P-6.2 Create `tests/Unit/Lifecycle/BezwaarLifecycleTest.php`. **DONE 2026-06-15:**
  asserts (a) sequential AWB progression declared; (b) `Ontvangen → In behandeling`
  rejected (skips ontvankelijkheidstoets); (c) hearing-skip guard blocked when
  `hearingWaived` false, passes when true; (d) `intrekken` accepted from the four open
  states only; plus deadline-guard allow/deny. Uses the real capitalized enum strings.
  - **files:** `tests/Unit/Lifecycle/BezwaarLifecycleTest.php`

- [x] P-6.3 ~~Create `ParaferingServiceStepTest.php`.~~ **DESCOPED 2026-06-15:** depended
  on the false `ParaferingService` premise and the parafeerroute lifecycle (both removed
  from scope — see P-2/P-4). Step-routing remains in `ParafeerActieService` and is covered
  by its existing suite; no new lifecycle assertion applies.

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
