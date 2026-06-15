# migrate-status-engine-to-or-lifecycle Specification

---
status: proposed
---

## Purpose

Migrate procest's fixed-enum state machines for the `voorstel` and `bezwaar`
schemas from app-side enforcement to OpenRegister's declarative
`x-openregister-lifecycle` engine. After this change OR validates every status
transition on `saveObject` server-side (rejecting an illegal transition before
it is persisted) and delegates non-trivial preconditions back to procest through
the `requires` guard seam (FQCN classes implementing OR's
`LifecycleGuardInterface`). Implements the procest-specific obligations of the
`consume-or-workflow-engine-fleet-wide` umbrella change against the lifecycle +
transition-guard engine that landed in OpenRegister PR #153.

@e2e exclude Backend lifecycle-engine migration with no new UI surface — the declarative transition table, OR's saveObject rejection of illegal transitions, and the procest `requires` guard classes (VoorstelSubmitGuard, HoorzittingAfzienGuard, BezwaarDeadlineGuard) are covered by PHPUnit (`tests/Unit/Lifecycle/VoorstelLifecycleTest.php`, `tests/Unit/Lifecycle/BezwaarLifecycleTest.php`), not Playwright; the case-detail status UI is unchanged.

## ADDED Requirements

### Requirement: Voorstel Lifecycle MUST Be Declared as an OR Schema Extension

The `voorstel` schema in `lib/Settings/procest_register.json` MUST declare its
status state machine via an `x-openregister-lifecycle` block under
`configuration`, so OR's lifecycle engine validates every transition on
`saveObject`. The `startParafering` transition MUST reference the procest guard
`OCA\Procest\Lifecycle\VoorstelSubmitGuard` through the `requires` key.

#### Scenario: Voorstel lifecycle declares the status field and transitions

- GIVEN the `voorstel` schema in `procest_register.json`
- WHEN its `configuration.x-openregister-lifecycle` block is inspected
- THEN `field` MUST equal `"status"` and `initial` MUST equal `"concept"`
- AND the transition `startParafering` MUST move `concept` → `in_parafering`
- AND the `startParafering` transition MUST declare
  `requires: "OCA\\Procest\\Lifecycle\\VoorstelSubmitGuard"`

#### Scenario: OR rejects an illegal voorstel transition on save

- GIVEN a voorstel object with `status: "besloten"`
- WHEN a save attempts to set `status: "in_parafering"`
- THEN OR's `LifecycleValidationListener` MUST reject the save with a
  `lifecycle-invalid-transition` error
- AND the voorstel MUST remain in state `"besloten"`

#### Scenario: VoorstelSubmitGuard blocks an incomplete submission

- GIVEN a voorstel in state `"concept"` whose `onderwerp` is empty
- WHEN a save attempts the `startParafering` transition (`status: "in_parafering"`)
- THEN OR MUST invoke `OCA\Procest\Lifecycle\VoorstelSubmitGuard::check()`
- AND the guard MUST return a denying `GuardResult`
- AND OR MUST reject the save with a `lifecycle-guard-denied` error
- AND the voorstel MUST remain in state `"concept"`

---

### Requirement: Bezwaar Lifecycle MUST Mirror the AWB Chapter 6/7 Sequence

The `bezwaar` schema MUST declare its AWB objection state machine via an
`x-openregister-lifecycle` block whose `field` is `status` and whose transitions
match the `bezwaar-lifecycle` spec, using the schema's existing status enum
values verbatim. The `hoorzitting_overslaan` transition MUST require
`OCA\Procest\Lifecycle\HoorzittingAfzienGuard`; the `beslissen` transition MUST
require `OCA\Procest\Lifecycle\BezwaarDeadlineGuard`.

#### Scenario: All AWB bezwaar transitions are declared

- GIVEN the `bezwaar` schema in `procest_register.json`
- WHEN its `configuration.x-openregister-lifecycle.transitions` map is inspected
- THEN it MUST declare `ontvankelijkheidstoets_starten`, `in_behandeling_nemen`,
  `hoorzitting_plannen`, `hoorzitting_afronden`, `advies_uitbrengen`,
  `beslissen`, `afronden`, `niet_ontvankelijk_verklaren`, `intrekken`, and
  `hoorzitting_overslaan`
- AND `initial` MUST equal `"Ontvangen"`

#### Scenario: OR rejects an out-of-sequence bezwaar transition

- GIVEN a bezwaar in state `"Ontvangen"`
- WHEN a save attempts to set `status: "In behandeling"` directly (skipping
  `"Ontvankelijkheidstoets"`)
- THEN OR's `LifecycleValidationListener` MUST reject the save with a
  `lifecycle-invalid-transition` error
- AND the bezwaar MUST remain in state `"Ontvangen"`

#### Scenario: Hearing-skip requires the hearing right to be waived

- GIVEN a bezwaar in state `"In behandeling"` with `hearingWaived: false`
- WHEN a save attempts the `hoorzitting_overslaan` transition
  (`status: "Advies uitgebracht"`)
- THEN OR MUST invoke `OCA\Procest\Lifecycle\HoorzittingAfzienGuard::check()`
- AND the guard MUST return a denying `GuardResult`
- AND OR MUST reject the save with a `lifecycle-guard-denied` error

---

### Requirement: PHP Guard Classes Implement the OR Lifecycle Guard Interface

Each procest guard class MUST implement `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface::check()`, live under `lib/Lifecycle/`, and be read-only. The guards `VoorstelSubmitGuard`, `HoorzittingAfzienGuard`, and `BezwaarDeadlineGuard` MUST NOT mutate the object or call `ObjectService::saveObject()`.

#### Scenario: Guards are read-only precondition checkers

- GIVEN any of the three `lib/Lifecycle/*Guard.php` classes
- WHEN the class is inspected
- THEN it MUST implement `check(array $object, string $action, string $userId): GuardResult`
- AND it MUST NOT call any persistence method
- AND it MUST return `GuardResult::allow()` or `GuardResult::deny(...)` only

---

### Requirement: PHPUnit Tests MUST Cover Each Lifecycle Transition

Each migrated schema MUST have PHPUnit coverage proving (a) a valid transition is
declared, (b) an illegal transition is not declared, and (c) the guard seam
blocks and passes per its precondition.

#### Scenario: Lifecycle tests assert valid, illegal, and guarded transitions

- GIVEN `tests/Unit/Lifecycle/VoorstelLifecycleTest.php` and
  `tests/Unit/Lifecycle/BezwaarLifecycleTest.php`
- WHEN the unit suite runs
- THEN a valid transition (concept → in_parafering; sequential AWB steps) MUST
  assert true
- AND an illegal transition (besloten → in_parafering; Ontvangen → In behandeling)
  MUST assert false
- AND the submit, hearing-skip, and deadline guards MUST be exercised for both
  their allowing and denying branches

## Non-Requirements

- This change does NOT migrate the case-level status engine
  (`StatusTransitionService`). `case.status` is a UUID reference to a
  per-caseType `statusType` object, so its valid states and transitions are
  defined dynamically by each `workflowTemplate` rather than by a fixed enum.
  OR's `x-openregister-lifecycle` requires a static transition table on the
  schema and therefore cannot express a per-caseType dynamic state machine.
  This is a residual OR gap; the bespoke `StatusTransitionService` remains the
  source of truth for `case.status` and is unchanged.
- This change does NOT migrate the deprecated `parafeerroute` schema (no `status`
  field; superseded by OR approval-workflow) nor the `advisoryReport`/`bacAdviceRequest`
  committee state machine.
- This change does NOT modify the `status-transition-engine`,
  `bezwaar-lifecycle`, `workflow-definition-model`, or `parafeerroute-engine`
  spec bodies; only the runtime enforcement of the fixed-enum voorstel/bezwaar
  lifecycles moves to OR.
- This change does NOT alter procest's public REST API surface.

## Dependencies

- OpenRegister PR #153 — lifecycle transition-guard engine + `LifecycleGuardInterface`
  (`OCA\OpenRegister\Lifecycle\LifecycleGuardInterface`, `GuardResult`), the
  `LifecycleValidationListener` that enforces transitions on save, and the
  `property`/string-`from` annotation aliases.
- `openregister/openspec/specs/object-lifecycle` — OR's lifecycle pipeline.

## Cross-References

- **procest/openspec/specs/bezwaar-lifecycle** — AWB status sequence source of
  truth (spec body unchanged; transitions now enforced by OR on saveObject).
- **procest/openspec/specs/status-transition-engine** — case-level guard
  evaluation model; unchanged (case.status remains app-enforced — see
  Non-Requirements).
