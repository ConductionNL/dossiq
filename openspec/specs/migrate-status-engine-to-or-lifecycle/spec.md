# migrate-status-engine-to-or-lifecycle Specification

## Purpose
TBD - created by archiving change migrate-status-engine-to-or-lifecycle. Update Purpose after archive.
## Requirements
### Requirement: Voorstel Lifecycle MUST Be Declared as Schema Extension

The voorstel schema in `lib/Settings/procest_register.json` MUST include an
`x-openregister-lifecycle` extension declaring the five valid states and all
allowed transitions. The `ParaferingService` PHP status constants for voorstel
lifecycle SHALL be removed.

#### Scenario: Voorstel lifecycle extension registered in repair step

- **GIVEN** the procest app repair step runs
- **WHEN** `ConfigurationService::importFromApp()` processes `procest_register.json`
- **THEN** the `Voorstel` schema in OR MUST include an `x-openregister-lifecycle`
  block with `property: "lifecycle"`, `initial: "concept"`, and transitions for
  `indienen`, `terugsturen`, `completeren`, `afwijzen`, and `heropenen`
- **AND** the `lifecycle` property MUST be an enum with values
  `["concept", "in_parafering", "teruggestuurd", "geparafeerd", "afgewezen"]`

#### Scenario: OR rejects invalid voorstel lifecycle transition

- **GIVEN** a voorstel object with `lifecycle: "geparafeerd"` (terminal state)
- **WHEN** a PATCH request attempts to set `lifecycle: "in_parafering"`
- **THEN** OR's lifecycle engine MUST reject the request with HTTP 422
- **AND** the voorstel object MUST remain in state `"geparafeerd"` in the database
- **AND** no `AuditTrail` entry for this attempted transition SHALL be created

#### Scenario: OR emits lifecycle transition audit entry

- **GIVEN** a voorstel in state `"concept"` successfully transitions to
  `"in_parafering"` via the `indienen` transition
- **WHEN** `GET /api/audit-trails?objectUuid={voorstelUuid}` is called
- **THEN** an audit trail entry MUST exist with `action` containing
  `"lifecycle-transition"` (or procest-namespaced equivalent)
- **AND** the entry's `changed` JSON column MUST record both the old and new
  lifecycle state
- **AND** no separate procest-local audit record for this transition SHALL exist

#### Scenario: VoorstelSubmitGuard evaluated before indienen transition

- **GIVEN** a voorstel in state `"concept"` is missing a required field (`onderwerp`)
- **WHEN** a PATCH request attempts the `indienen` transition (`lifecycle: "in_parafering"`)
- **THEN** OR's lifecycle engine MUST invoke
  `OCA\Procest\Lifecycle\VoorstelSubmitGuard::allows()`
- **AND** the guard MUST return `false` for missing required fields
- **AND** OR MUST respond with HTTP 422 and include the guard's rejection message
- **AND** the voorstel MUST remain in state `"concept"`

---

### Requirement: Parafeerroute Schema MUST Declare Route-Level Lifecycle States

The parafeerroute schema MUST declare route-level states (`actief`, `afgerond`,
`geannuleerd`) as an `x-openregister-lifecycle` extension. Step-routing logic
(which `parafeerstap` is currently active) remains in `ParaferingService` PHP
methods, as it orchestrates related objects rather than a single-object state machine.

#### Scenario: Parafeerroute lifecycle registered in repair step

- **GIVEN** the procest app repair step runs
- **WHEN** `ConfigurationService::importFromApp()` processes `procest_register.json`
- **THEN** the `Parafeerroute` schema MUST include an `x-openregister-lifecycle`
  block with `property: "status"`, `initial: "actief"`, and transitions for
  `afronden` (actief → afgerond) and `annuleren` (actief → geannuleerd)
- **AND** the `status` property MUST be an enum with values
  `["actief", "afgerond", "geannuleerd"]`

#### Scenario: ParaferingService step routing does not set route lifecycle

- **GIVEN** `ParaferingService::activateNextStep()` is called to advance a step
- **WHEN** the method executes
- **THEN** it MUST NOT set the parafeerroute's `status` field directly via
  `ObjectService::saveObject()`
- **AND** it MUST NOT reference `STATUS_*` constants for the route-level lifecycle
- **AND** route completion (`status: "afgerond"`) MUST only be triggered via a PATCH
  request that passes through OR's lifecycle engine

---

### Requirement: Bezwaar Lifecycle MUST Mirror AWB Chapter 6/7 Sequence

The bezwaar schema MUST declare all ten AWB status types from the `bezwaar-lifecycle`
spec as an `x-openregister-lifecycle` extension with enforced transition ordering.
The `hoorzitting_overslaan` transition (hearing waiver) MUST require a PHP guard.

#### Scenario: All ten bezwaar status transitions registered

- **GIVEN** the procest app repair step runs
- **WHEN** `ConfigurationService::importFromApp()` processes `procest_register.json`
- **THEN** the `Bezwaar` schema MUST include an `x-openregister-lifecycle` block
  with all transitions described in `bezwaar-lifecycle/spec.md` including
  `ontvankelijkheidstoets_starten`, `in_behandeling_nemen`, `hoorzitting_plannen`,
  `hoorzitting_afronden`, `advies_uitbrengen`, `beslissen`, `afronden`,
  `niet_ontvankelijk_verklaren`, `intrekken`, and `hoorzitting_overslaan`

#### Scenario: Bezwaar cannot skip ontvankelijkheidstoets without a valid transition

- **GIVEN** a bezwaar in state `"ontvangen"`
- **WHEN** a PATCH request attempts to set `status: "in_behandeling"` directly
  (skipping `"ontvankelijkheidstoets"`)
- **THEN** OR's lifecycle engine MUST reject the request with HTTP 422
- **AND** the bezwaar MUST remain in state `"ontvangen"`

#### Scenario: Hearing waiver skip requires HoorzittingAfzienGuard pass

- **GIVEN** a bezwaar in state `"in_behandeling"` where the bezwaarmaker has NOT
  filed a hearing waiver (`hoorrecht_afgezien: false`)
- **WHEN** a PATCH request attempts the `hoorzitting_overslaan` transition
  (`status: "advies_uitgebracht"`)
- **THEN** `OCA\Procest\Lifecycle\HoorzittingAfzienGuard::allows()` MUST be invoked
- **AND** the guard MUST return `false`
- **AND** OR MUST respond with HTTP 422
- **AND** the bezwaar MUST remain in state `"in_behandeling"`

---

### Requirement: ParaferingService Status Constants SHALL Be Removed

The constants `ParaferingService::STATUS_CONCEPT`, `STATUS_IN_PARAFERING`, `STATUS_TERUGGESTUURD`, and `STATUS_GEPARAFEERD` MUST be removed from `lib/Service/ParaferingService.php`. Callers that previously used these constants MUST be updated to use string literals matching the `x-openregister-lifecycle` enum values, or access the object's `lifecycle` field directly.

#### Scenario: No STATUS_* constants remain in ParaferingService

- **GIVEN** `lib/Service/ParaferingService.php` after migration
- **WHEN** the file is inspected
- **THEN** no `const STATUS_` declarations SHALL exist
- **AND** `composer check:strict` MUST pass with no reference errors

#### Scenario: No direct lifecycle saveObject calls remain

- **GIVEN** `lib/Service/ParaferingService.php` after migration
- **WHEN** the file is inspected
- **THEN** no call pattern matching `saveObject` with a `lifecycle` or `status`
  field mutation for voorstel/parafeerroute/bezwaar MUST remain
  without routing through OR's lifecycle engine (i.e., via a PATCH that OR handles)

---

### Requirement: Automatic Actions on Transitions MUST Use Schema Hooks

Automatic actions on voorstel/parafeerroute/bezwaar lifecycle transitions (send email, create task) MUST be dispatched via `x-openregister-hooks` entries in `procest_register.json` — Application.php event listeners that perform this dispatch MUST be removed.

#### Scenario: Schema hook dispatches parafering notification via n8n

- **GIVEN** a voorstel transitions to `"in_parafering"`
- **WHEN** OR's `HookExecutor` processes the `updated` event
- **THEN** the `procest-parafering-notification` n8n workflow MUST be triggered
  asynchronously via `WorkflowEngineInterface::executeWorkflow()`
- **AND** no Application.php listener for `ObjectUpdatedEvent` that calls n8n
  directly SHALL exist

#### Scenario: Removed Application.php listeners do not interfere

- **GIVEN** `lib/AppInfo/Application.php` after migration
- **WHEN** the file is inspected
- **THEN** no event listener registrations for voorstel/parafeerroute/bezwaar
  lifecycle automatic-action dispatch SHALL remain
- **AND** `composer check:strict` MUST pass

---

### Requirement: PHP Guard Classes MUST Implement Single-Method Interface

Guard classes (`VoorstelSubmitGuard`, `BezwaarDeadlineGuard`, `HoorzittingAfzienGuard`) MUST be thin precondition checkers that return `bool` and MUST NOT call `WorkflowEngineInterface`, `ObjectService::saveObject()`, or any workflow engine adapter.

#### Scenario: Guard class implements single allows() method

- **GIVEN** `lib/Lifecycle/VoorstelSubmitGuard.php`
- **WHEN** the class is inspected
- **THEN** it MUST implement a single public method with signature
  `allows(array $object): bool`
- **AND** it MUST NOT inject or call `WorkflowEngineInterface`
- **AND** it MUST NOT call `ObjectService::saveObject()` or any persistence method

---

### Requirement: PHPUnit Tests MUST Cover Each Lifecycle Transition

Each schema's lifecycle extension MUST have PHPUnit test coverage confirming
(a) allowed transitions pass, (b) disallowed transitions are rejected, and
(c) guard failures block the transition.

#### Scenario: VoorstelLifecycleTest covers all transitions

- **GIVEN** a `tests/Unit/Lifecycle/VoorstelLifecycleTest.php` exists
- **WHEN** the test suite runs
- **THEN** at minimum the following scenarios MUST be covered:
  (a) `concept → in_parafering` succeeds when guard passes;
  (b) `concept → in_parafering` fails when guard returns false;
  (c) `geparafeerd → in_parafering` fails (invalid from-state);
  (d) all five terminal state values are valid enum values

#### Scenario: BezwaarLifecycleTest covers AWB sequence

- **GIVEN** a `tests/Unit/Lifecycle/BezwaarLifecycleTest.php` exists
- **WHEN** the test suite runs
- **THEN** at minimum the following scenarios MUST be covered:
  (a) sequential AWB status progression passes;
  (b) skipping `ontvankelijkheidstoets` is rejected;
  (c) `hoorzitting_overslaan` is blocked when hearing waiver flag is false;
  (d) `intrekken` is accepted from all documented from-states

