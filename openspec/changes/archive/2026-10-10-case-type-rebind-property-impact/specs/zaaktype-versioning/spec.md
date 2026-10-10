## MODIFIED Requirements

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

Before anything is written, the preview SHALL show the property impact in
three groups, computed once on the server for the dialog and every API
caller alike:

- **dropped**: every answer on the case that the target type has no
  compatible field for, with its current value;
- **ported**: every answer that carries over, naming the source field, the
  target field, the value it lands with and whether it was converted;
- **required**: every target field that is required (`isRequired`, or
  `requiredAtStatus` equal to the landing status) and has no value after
  porting, with its definition and whether the given answer is valid.

Answers are matched by field name, case-insensitively. A same-named answer
whose value does not fit the target field's type SHALL be dropped, not
converted silently. The coordinator MAY move a dropped answer onto another
target field whose type accepts the value. The rebind SHALL apply exactly
the impact the preview computes, in the single case write, and SHALL be
refused unless the request confirms the same list of dropped names the
preview showed. Dropped values SHALL be recorded on the case's journal
entry for the rebind.

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

#### Scenario: The preview shows what is dropped, ported and required
@e2e exclude covered by CaseRebindImpactTest and the caseRebindImpact component test; the e2e seed carries no answered properties to drop

- **GIVEN** a case answering `boomsoort` = eik and `oppervlakte` = 120, rebound onto a type with `oppervlakte` as a number and `bouwjaar` required at Toetsing
- **WHEN** the coordinator picks the type and Toetsing
- **THEN** `boomsoort` SHALL be listed as dropped with the value eik
- **AND** `oppervlakte -> oppervlakte` SHALL be listed as ported with 120
- **AND** `bouwjaar` SHALL be listed as required, and confirm SHALL stay disabled until it holds a valid year

#### Scenario: A value that does not fit is dropped, not converted
@e2e exclude covered by CaseRebindImpactTest

- **GIVEN** a case answering `oppervlakte` = "groot" as text, and a target whose `oppervlakte` is a number
- **WHEN** the impact is computed
- **THEN** `oppervlakte` SHALL be listed as dropped with the reason that its value does not fit

#### Scenario: A dropped answer is moved onto another field
@e2e exclude covered by CaseRebindImpactTest and CaseRebindServiceTest

- **GIVEN** `boomsoort` is dropped and the target has a text field `soort`
- **WHEN** the coordinator moves `boomsoort` onto `soort`
- **THEN** the impact SHALL list `boomsoort -> soort` as ported, remapped
- **AND** the rebind SHALL write eik under `soort`

#### Scenario: An unconfirmed drop is refused
@e2e exclude covered by CaseRebindServiceTest

- **GIVEN** the impact drops `boomsoort`
- **WHEN** the rebind is posted without confirming that drop
- **THEN** it SHALL be refused with `rebind-drop-not-confirmed`
- **AND** nothing SHALL be written
