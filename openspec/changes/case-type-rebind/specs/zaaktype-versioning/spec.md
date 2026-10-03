## ADDED Requirements

### Requirement: A coordinator may rebind a running case with a mapping and a reason (REQ-ZV-07)

REQ-ZV-02 stays the default. `#CaseDetail` SHALL offer Change type or
version to `dossiq-coordinators` only. The rebind SHALL require a target
type or version, a mapped status and a reason; SHALL ask for every
property the target requires at that status that the case lacks; SHALL
migrate the engine run through `migrate-run-between-versions`; SHALL
write `caseType`, `workflowTemplate`, `workflowVersion` and `status` with
a status record naming the reason and the old binding; and SHALL re-arm
each active term against the target's definition keeping its start date.
The case number and its files SHALL NOT change.

#### Scenario: Refile a case under the right type
@e2e tests/e2e/case-type-rebind.spec.ts

- **GIVEN** a running Kapvergunning case that should be an Omgevingsvergunning
- **WHEN** a coordinator rebinds it, maps In behandeling to Toetsing, fills the two missing properties and gives a reason
- **THEN** the case SHALL carry the new type and status with the same number
- **AND** the History tab SHALL show the rebind with the reason and the old type

#### Scenario: The term keeps its start
@e2e exclude covered by the TermijnService fixture pair in D-2

- **GIVEN** a 56-day term started 1 June, extended once by 14 days, rebound on 20 June to an 84-day definition
- **WHEN** the terms are re-armed
- **THEN** the new instance SHALL start 1 June and end 1 June plus 98 days

#### Scenario: A handler is refused
@e2e tests/e2e/case-type-rebind.spec.ts

- **GIVEN** a handler outside `dossiq-coordinators`
- **WHEN** they call the rebind endpoint
- **THEN** the response SHALL be 403 naming the rule

#### Scenario: The engine's refusal stops the rebind
@e2e exclude covered by CaseRebindServiceTest over an engine stub that refuses the migration

- **GIVEN** the engine refuses to migrate the run
- **WHEN** the rebind is attempted
- **THEN** no field SHALL change
- **AND** the response SHALL carry the engine's reason
