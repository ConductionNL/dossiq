## MODIFIED Requirements

### Requirement: Inspection checklist schema

The system SHALL store every inspection checklist as one `inspectionChecklistTemplate` object in OpenRegister, versioned and optionally bound to a case type. No other schema SHALL hold a checklist template. Each item SHALL carry a stable `id` that answers name.

**Feature tier**: V1
**ZGW mapping**: Custom extension (no ZGW equivalent)
**Schema.org**: schema:HowTo (checklist), schema:HowToStep (checklist item)

#### Scenario: Create inspection checklist

- **WHEN** an admin creates a new checklist "Bouwtoezicht fase 1 - Fundering" linked to case type "Toezichtzaak Bouw"
- **THEN** the system SHALL create an `inspectionChecklistTemplate` object with: name, caseType (reference), version (integer, starting at 1), status (draft), sections, each with ordered items
- **THEN** each item SHALL support: id, order, label, responseType (yes_no_na / text / getal / meerkeuze / photo / meting), required, photoRequired (nooit / if_no / altijd), choices, helpText, weight and parent
- @e2e tests/e2e/vth-inspection-result-authz.spec.ts

#### Scenario: Version checklist

- **WHEN** an admin modifies an active checklist
- **THEN** the system SHALL create a new version (version + 1) in draft status
- **THEN** in-progress inspections SHALL continue using their original checklist version, frozen in their task's `templateSnapshot`
- **THEN** only the latest active version SHALL be used for new inspections
- @e2e exclude versioning is asserted on the template service and the frozen snapshot in PHPUnit; a browser sees only the newest version

#### Scenario: The older template schemas fold into the one

- **GIVEN** an instance holding `inspectieChecklist` objects and `inspectionChecklist` objects with their `checklistItem` rows
- **WHEN** the upgrade runs
- **THEN** each becomes one `inspectionChecklistTemplate` carrying the same name, case type, version and items, with `legacyRef` naming its source
- **THEN** a second run creates nothing, and a source it cannot read is reported and left in place
- @e2e exclude a repair step on upgrade has no browser surface; asserted by the fold's PHPUnit suite

### Requirement: Inspection rapport creation

The system SHALL record every completed inspection checklist as one OpenRegister `Task` on the case, of kind `inspection`, with the answers in `Task.responses`, the photos in `Task.evidence`, the overall result in `Task.outcome` and the frozen template in `Task.templateSnapshot`. No result schema SHALL hold a run.

**Feature tier**: V1
**Schema.org**: schema:Report (rapport), schema:ReviewAction (inspection)

#### Scenario: Complete inspection checklist

- **WHEN** an inspector opens a planned inspection on case "2026-089" and fills in all checklist items
- **THEN** the system SHALL complete a task on the case with: kind `inspection`, the template id and version, the inspector as assignee and completer, the answers as responses and the location in the task's metadata
- **THEN** each answer SHALL record: itemId, value (ja / nee / nvt for a yes_no_na item), comment, numericValue for a getal or meting item, photos (Nextcloud file ids)
- **THEN** the outcome SHALL be determined over the answered items that apply: "conform" when none is nee, "non_conform" when none conforms, "partly_conform" otherwise
- @e2e tests/e2e/vth-inspection-result-authz.spec.ts

#### Scenario: Photo capture on failed items

- **WHEN** an inspector answers nee on an item whose photoRequired is if_no, or any answer on an item whose photoRequired is altijd
- **THEN** the system SHALL refuse the submission until that answer carries at least one photo, and SHALL write no task
- **THEN** photos SHALL be uploaded to the case folder in Nextcloud Files and listed as the task's evidence
- **THEN** each photo SHALL be linked to its answer
- @e2e exclude the gate is asserted on ChecklistService and the run service in PHPUnit; staging a camera upload headless is not deterministic

#### Scenario: Follow-up task on non-conformity

- **WHEN** an inspector submits a run with outcome "non_conform" (2 items answered nee)
- **THEN** the system SHALL create a follow-up task on the case: "Opvolging vereist: 2 afwijkingen geconstateerd"
- **THEN** the follow-up task SHALL reference the inspection task
- @e2e exclude the panel and the follow-up are client surfaces left to the live pass (decision 139); the run shape they read is asserted by vth-inspection-result-authz.spec.ts and InspectionRunServiceTest, the follow-up by inspectionStore.spec.js

#### Scenario: Older runs are carried onto tasks once

- **GIVEN** stored `inspectieRapport`, `inspectionChecklistRun` and `inspectionResult` objects
- **WHEN** the upgrade runs
- **THEN** each becomes one completed inspection task with its answers, outcome and moments, and `legacyRef` in its metadata naming its source
- **THEN** a second run creates nothing
- @e2e exclude a repair step on upgrade has no browser surface; asserted by the carry-over's PHPUnit suite

### Requirement: Inspection panel on case dashboard

The system SHALL display an inspection panel on the case dashboard for Toezicht case types showing inspection progress and the history of the case's inspection tasks.

**Feature tier**: V1

#### Scenario: Display inspection progress

- **WHEN** a user views the case dashboard for a Toezichtzaak Bouw with 3 active checklist templates
- **THEN** the "Inspecties" panel SHALL show: inspection progress ("Inspectie 1/3 voltooid"), counting a template as done when a completed inspection task names it
- **THEN** completed inspections SHALL show: date, inspector name, result badge (conform=green, non_conform=red, partly_conform=orange)
- @e2e exclude the panel and the follow-up are client surfaces left to the live pass (decision 139); the run shape they read is asserted by vth-inspection-result-authz.spec.ts and InspectionRunServiceTest, the follow-up by inspectionStore.spec.js

#### Scenario: Expand rapport details

- **WHEN** a user clicks on a completed inspection in the panel
- **THEN** the system SHALL expand to show each answer: item label, value, comment, linked photos
- **THEN** items answered nee SHALL be highlighted with a warning icon
- @e2e exclude the panel and the follow-up are client surfaces left to the live pass (decision 139); the run shape they read is asserted by vth-inspection-result-authz.spec.ts and InspectionRunServiceTest, the follow-up by inspectionStore.spec.js

#### Scenario: Multiple inspections per phase

- **WHEN** a case has several inspection tasks for the same template (re-inspection after non-conformity)
- **THEN** the panel SHALL show all of them in chronological order
- **THEN** the most recent SHALL determine the current phase status
- @e2e exclude ordering is asserted on the results endpoint in PHPUnit; staging two completed runs needs a seeded case the e2e fixtures do not carry

### Requirement: Inspection result submission is authorized from stored state

The system SHALL decide who may submit an inspection result for a case from
data it has stored, never from the submitted request. `POST
/api/vth/cases/{id}/inspection-result` SHALL admit only the case's stored
assignee and members of the admin group, and SHALL refuse every other
authenticated caller.

This requirement exists because the endpoint shipped with a guard that read
the permitted inspector out of the request body (issue #799). A caller who
omitted the field, or who named themselves, passed. The rule below is written
down so the next implementation cannot quietly choose a different one.

**Feature tier**: V1

#### Scenario: The case's assignee submits a result

- **GIVEN** case `2026-089` whose stored `assignee` is `inspecteur-a`
- **WHEN** `inspecteur-a` posts an inspection result to that case
- **THEN** the system SHALL accept the submission and complete an inspection
  task whose subject is `2026-089` and whose `completedBy` is `inspecteur-a`

#### Scenario: Another authenticated account is refused

- **GIVEN** the same case, and an authenticated account `buitenstaander` that
  is neither its `assignee` nor a member of the admin group
- **WHEN** `buitenstaander` posts an inspection result to that case
- **THEN** the system SHALL respond `403 Forbidden`
- **THEN** no inspection task SHALL be written

#### Scenario: Omitting the inspector field does not skip the check

- **GIVEN** the same case and the same account `buitenstaander`
- **WHEN** `buitenstaander` posts an inspection result carrying no inspector
  field of any kind
- **THEN** the system SHALL still respond `403 Forbidden`, because the decision
  is taken before the payload is read and no absent field can influence it

#### Scenario: The case cannot be resolved

@e2e exclude every branch of this one is an infrastructure failure a browser cannot stage: an absent OpenRegister, an unconfigured case schema, a case uuid with no row. `CaseAccessGuardReadAccessTest` drives all three against the guard directly, which is where the decision is taken.

- **WHEN** a submission names a case that does not exist, or OpenRegister is
  unavailable, or the case schema is not configured
- **THEN** the system SHALL refuse the submission
- **THEN** an unresolvable case SHALL never be treated as an unrestricted one

#### Scenario: Creating an advice request is authorized the same way

- **GIVEN** case `2026-089` whose stored `assignee` is `inspecteur-a`
- **WHEN** an authenticated account that is neither that assignee nor an admin
  posts to `/api/vth/cases/2026-089/advice-requests`
- **THEN** the system SHALL respond `403 Forbidden` and SHALL send no adviseur
  notification

### Requirement: Inspection results are stored under the schema's own property names

The system SHALL write an inspection result as an OpenRegister `Task` using
the task's own fields (`objectUuid`, `templateId`, `responses`, `outcome`),
and SHALL read results back from the case's inspection tasks, so what a
submission writes is what the results endpoint returns.

**Feature tier**: V1

#### Scenario: A submitted result is readable back

- **WHEN** a permitted caller submits an inspection result for a case
- **THEN** `GET /api/vth/cases/{id}/inspection-results` SHALL return that
  result for the same case
