# case-plan-read Delta: r6-dossiq-titles-related-cases-requests

## ADDED Requirements

### Requirement: The case page asks OpenRegister for a plan only when it can hold one

The case-plan panel SHALL read the case and its caseType before it reads the
plan. It SHALL NOT request `/apps/openregister/api/cases/{id}` for a case
whose caseType has a `handlingModel` other than `cmmn` (absent reads as
`bpmn`) and that carries no `casePlanState` blob. It SHALL still request it
when the caseType is CMMN, when the case carries a blob, or when the caseType
cannot be read, so an OpenRegister outage still shows as an error.

#### Scenario: A BPMN case
<!-- @e2e exclude Proven by tests/vitest/casePlanPanel.spec.js and live on :8099 (no 404 in the network log). -->
- **GIVEN** a case whose caseType has `handlingModel: bpmn` and no blob
- **WHEN** a handler opens the case
- **THEN** no request goes to `/apps/openregister/api/cases/{id}` and the panel says the case type has no adaptive plan

#### Scenario: A CMMN case
<!-- @e2e exclude Proven by tests/vitest/casePlanPanel.spec.js. -->
- **GIVEN** a case whose caseType has `handlingModel: cmmn`
- **WHEN** a handler opens the case
- **THEN** the panel reads the plan from OpenRegister

#### Scenario: The caseType cannot be read
<!-- @e2e exclude Proven by tests/vitest/casePlanPanel.spec.js. -->
- **GIVEN** a case whose caseType read fails and OpenRegister answers 500
- **WHEN** a handler opens the case
- **THEN** the panel shows the error with a retry, never an empty plan
