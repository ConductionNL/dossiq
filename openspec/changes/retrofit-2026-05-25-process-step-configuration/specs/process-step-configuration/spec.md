---
retrofit_extensions:
  - REQ-001
---

# Process Step Configuration — pre-publish validator (retrofit)

## Requirements

### REQ-001: StepConfigValidator SHALL validate every workflow step's `config` block at publish time

`OCA\Procest\Service\StepConfigValidator::validate(array $step, array $caseTypeSchema = [], array $actionCatalog = self::DEFAULT_ACTION_CATALOG, int $stepIndex = 0): array` SHALL return an empty array on success, or an array of `{path: string, code: string, message: string}` errors on failure. The validator SHALL be invoked once per entry in the `steps[]` array of a `workflowTemplate` during the publish transition and SHALL enforce:

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
