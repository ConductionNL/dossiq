---
status: done
retrofit_extensions:
  - REQ-001
---

## Purpose

@e2e exclude Process step configuration is V1; drag-and-drop step reorder in the workflow editor is not testable in the current build.

## Requirements

### Requirement: Process Step CRUD within Workflow

The system SHALL allow administrators to create, edit, reorder, and delete process steps within a workflow status phase. Steps represent concrete work items (CMMN HumanTask) that handlers must complete during that phase.

**Feature tier**: V1

#### Scenario: Add a step to a status phase

- **WHEN** an administrator clicks "Stap toevoegen" within the "In behandeling" status node
- **THEN** a new step SHALL be created with default values (title: "Nieuwe stap", isRequired: false, order: last)
- **AND** the step configuration panel SHALL open for editing

#### Scenario: Edit step properties

- **WHEN** an administrator edits step "Toets ontvankelijkheid" and sets `isRequired: true` and adds 3 checklist items
- **THEN** the step SHALL be saved to the workflow template
- **AND** the visual editor SHALL show a badge indicating "3 checks" on the step

#### Scenario: Delete a step

- **WHEN** an administrator deletes step "Optionele controle" from status "Intake"
- **THEN** the step SHALL be removed from the workflow template
- **AND** remaining steps SHALL have their `order` properties recalculated

#### Scenario: Reorder steps via drag-and-drop

- **WHEN** an administrator drags step at position 3 to position 1
- **THEN** all affected steps SHALL have their `order` updated
- **AND** the visual order in the editor SHALL reflect the change immediately

### Requirement: Step-to-Task Mapping at Runtime

The system SHALL automatically create tasks for case handlers based on the workflow steps defined for the current status. When a case enters a status with configured steps, tasks are created from the step definitions.

**Feature tier**: V1

#### Scenario: Case enters status with steps

- **WHEN** case "ZK-2024-001" transitions to status "In behandeling" which has 3 configured steps
- **THEN** the system SHALL create 3 tasks linked to the case, one per step
- **AND** each task SHALL inherit: title, description, assigneeRole, checklist from the step definition
- **AND** tasks SHALL have status `available` per CMMN lifecycle

#### Scenario: Required steps block status exit

- **WHEN** status "In behandeling" has 2 required steps and 1 optional step
- **AND** the handler has completed 1 required step and the optional step
- **THEN** the exit transitions from "In behandeling" SHALL remain blocked
- **AND** the UI SHALL display: "1 verplichte stap nog niet afgerond: 'Inhoudelijke beoordeling'"

#### Scenario: Optional steps do not block transition

- **WHEN** all required steps in status "Intake" are completed but 1 optional step remains
- **THEN** the exit transitions from "Intake" SHALL be available
- **AND** the optional step task SHALL be automatically terminated when the transition executes

---

### REQ-001: StepConfigValidator SHALL validate every workflow step's `config` block at publish time

`OCA\Dossiq\Service\StepConfigValidator::validate(array $step, array $caseTypeSchema = [], array $actionCatalog = self::DEFAULT_ACTION_CATALOG, int $stepIndex = 0): array` SHALL return an empty array on success, or an array of `{path: string, code: string, message: string}` errors on failure. The validator SHALL be invoked once per entry in the `steps[]` array of a `workflowTemplate` during the publish transition and SHALL enforce:

- **`config.sla`** (via `validateSla`):
  - `sla.unit` SHALL be one of `SLA_UNITS = ['hours', 'businessDays', 'calendarDays']`
  - `sla.value` SHALL be a positive integer ≤ `SLA_VALUE_MAX = 10000`

- **`config.requiredFields`** (via `validateRequiredFields`):
  - Every field reference SHALL exist on the supplied `caseTypeSchema.properties` (skipped silently when the caller passes an empty schema).

- **`config.autoActions`** (via `validateAutoActions`):
  - Every action key SHALL be present in the supplied `$actionCatalog` (defaults to `DEFAULT_ACTION_CATALOG`: sendEmail, createTask, createSubCase, webhook, setField, notify, notifySteller, logToAuditTrail).

- **`config.escalationRule`** (via `validateEscalationRule`):
  - `escalationRule.offsetUnit` SHALL be one of `OFFSET_UNITS = ['hours', 'businessDays']`
  - `escalationRule.trigger` SHALL be one of `TRIGGERS = ['preBreach', 'slaBreached']`

Errors SHALL be addressable: every `path` SHALL be prefixed with `steps[<stepIndex>].config.` so callers can pinpoint the offending field. The `message` field is for internal diagnostics — callers MUST NOT surface it to end users (use `code` for user messaging).

#### Scenario: Valid step passes
- **GIVEN** a step with `config.sla = {unit: 'businessDays', value: 5}` and no other config blocks
- **WHEN** `validate($step)` is called
- **THEN** the result SHALL be an empty array

#### Scenario: Invalid SLA unit fails with path
- **GIVEN** a step at index 2 with `config.sla = {unit: 'weeks', value: 5}`
- **WHEN** `validate($step, stepIndex: 2)` is called
- **THEN** the result SHALL contain an error with `path: 'steps[2].config.sla.unit'` and a `code` identifying the enum violation

#### Scenario: SLA value above max fails
- **GIVEN** `config.sla = {unit: 'hours', value: 99999}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain an error whose `path` ends in `config.sla.value`

#### Scenario: Required-field check skipped on empty schema
- **GIVEN** `config.requiredFields = ['foo', 'bar']`
- **AND** `caseTypeSchema = []` (unit-test mode)
- **WHEN** `validate()` is called
- **THEN** the result SHALL NOT contain any `requiredFields` errors

#### Scenario: Auto-action outside catalog fails
- **GIVEN** `config.autoActions = [{action: 'launchMissiles'}]`
- **WHEN** `validate()` is called with the default action catalog
- **THEN** the result SHALL contain an error referencing the unknown action key

#### Scenario: Escalation rule trigger enforces enum
- **GIVEN** `config.escalationRule = {offsetUnit: 'hours', trigger: 'whenever'}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain an error at `path: '....escalationRule.trigger'`

#### Notes
- The action catalog is hand-synced with the `automatic-actions` spec; a future change should inject it via constructor when an action catalog service exists (TODO documented in the class docblock).
- All five validators run in sequence — every block contributes to the same result array so callers see the full list of failures, not just the first.
- The class is `final` and exposes only a static `validate()` method; helpers are private and not separately observable.

### Requirement: Each task in a case type is configured on its own (REQ-TASK-040)

A case type's workflow declaration SHALL carry a block per task: whether
it runs, its form, its candidate group, its lead time and its effects.
The block SHALL be one declaration per task and SHALL NOT be several
parallel maps keyed by task name. Publishing SHALL refuse a task naming a
form, a group or an effect handler that cannot be resolved, naming what
was missing.

#### Scenario: a task gets its own lead time
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a case type whose hoorzitting task declares 10 days
- **WHEN** the task is created on a case
- **THEN** its due date SHALL be 10 working days out

#### Scenario: a task is switched off for one case type
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** two case types sharing a process, one with the advice task off
- **WHEN** a case of each reaches that step
- **THEN** the advice task SHALL exist on one and not the other

#### Scenario: an unresolvable form refuses publication
@e2e exclude Publishing a workflow is an admin API call with no surface of its own yet. Asserted in tests/Unit/Service/Task/PerTaskConfigurationTest.php::testAnUnresolvableFormRefusesPublication.

- **GIVEN** a case type whose task names a form that does not exist
- **WHEN** it is published
- **THEN** publication SHALL refuse
- **AND** it SHALL name the form and the task

### Requirement: Always-available acts are declared beside the phase's acts (REQ-TASK-041)

A case type SHALL declare a list of acts available in every phase, beside
the acts of the current phase. The two lists SHALL be rendered together
and distinguished. An always-available act SHALL NOT appear in the phase
strip, SHALL NOT enter the progress figure, and SHALL NOT be given a phase
term.

#### Scenario: what may I do right now, in two halves
@e2e tests/e2e/task-as-a-first-class-record.spec.ts

- **GIVEN** a case type declaring three always-available acts and a phase with two
- **WHEN** a handler opens the case
- **THEN** five acts SHALL be offered
- **AND** the always-available three SHALL be marked as such

#### Scenario: an always-available act is not a phase
@e2e exclude The phase strip and the progress figure read statusTypes, and an always-available act is not one, so there is nothing for a browser to look at. Asserted in tests/vitest/alwaysAvailableActs.spec.js.

- **GIVEN** a case type with always-available acts
- **WHEN** the phase strip and the progress figure are read
- **THEN** neither SHALL include them

#### Scenario: an act unavailable on this case says why
@e2e exclude Playwright signs in as admin, who holds every role, so a refused act cannot be produced in the browser. Asserted in tests/vitest/alwaysAvailableActs.spec.js.

- **GIVEN** an always-available act refused by a guard
- **WHEN** a handler reads the acts
- **THEN** it SHALL be shown and disabled with the guard's sentence
