---
status: done
---

## Purpose

@e2e exclude Workflow template versioning is V1; immutable version management is backend logic covered by PHPUnit.

## Requirements

### Requirement: Workflow Template Versioning

The system SHALL support versioning of workflow templates so that changes to a zaaktype's workflow do not affect running cases. Each published version is immutable; editing creates a new draft version.

**Feature tier**: V1

#### Scenario: Publish a workflow creates a version

- **WHEN** an administrator clicks "Publiceren" on a draft workflow template
- **THEN** the template SHALL be validated (at least one status, one final status, no orphaned nodes)
- **AND** the template `isDraft` SHALL be set to `false` and `isActive` set to `true`
- **AND** any previously active version SHALL have `isActive` set to `false`

#### Scenario: Running cases retain their workflow version

- **WHEN** zaaktype "Omgevingsvergunning" has workflow version 2 active
- **AND** there are 5 running cases using version 2
- **AND** the administrator publishes version 3
- **THEN** the 5 running cases SHALL continue using version 2's steps and transitions
- **AND** new cases SHALL use version 3

#### Scenario: Edit published workflow creates new draft

- **WHEN** an administrator clicks "Bewerken" on the active workflow (version 2)
- **THEN** the system SHALL create a new draft version (version 3) as a copy of version 2
- **AND** the administrator SHALL edit version 3 while version 2 remains active for running cases

#### Scenario: View version history

- **WHEN** an administrator opens the version history of a workflow template
- **THEN** the system SHALL display all versions with: version number, publish date, published by, status (active/archived/draft)
- **AND** the administrator SHALL be able to view (read-only) any historical version

### Requirement: Case-to-Workflow-Version Binding

The system SHALL store the workflow template version reference on each case so that the case always knows which version of the workflow governs it.

**Feature tier**: V1

#### Scenario: New case binds to active workflow version

- **WHEN** a new case of type "Omgevingsvergunning" is created
- **AND** the active workflow version is version 3
- **THEN** the case SHALL store `workflowVersion: 3` and a reference to the workflow template UUID + version
- **AND** all status transitions and steps SHALL be computed from version 3

#### Scenario: Case with outdated workflow version

- **WHEN** a case is bound to workflow version 2 but version 3 is now active
- **THEN** the case detail view SHALL display an informational notice: "Dit dossier gebruikt werkstroomversie 2. Huidige versie is 3."
- **AND** the case SHALL continue to follow version 2's workflow rules

### Requirement: You publish a draft after a validation check and a change note (REQ-ZV-01)

You publish a draft case type after a validation check and a change note.
The case type page SHALL offer Publish, which SHALL run the validation and
refuse with the finding list when it is not empty, and otherwise SHALL ask
for a change note, clear the draft flag and mark the active workflow
template published. A Versions tab SHALL list the type's workflow templates
with version, lifecycle status and change note.

**Feature tier**: MVP

#### Scenario: A valid draft is published
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** a draft type whose validation finds nothing
- **WHEN** you choose Publish and enter the change note Eerste versie
- **THEN** the type SHALL no longer be a draft
- **AND** the Versions tab SHALL show one row marked published with Eerste versie

#### Scenario: A draft with findings is not published
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** a draft type without an initial status
- **WHEN** you choose Publish
- **THEN** the page SHALL list the finding and the type SHALL stay a draft

### Requirement: A case type is versioned, and a running case stays on its version (REQ-ZV-02)

You change a published case type by making a new version of it, not by editing
it. A published case type SHALL offer New version, which SHALL create a draft
carrying the same title and the same identifier, one version number higher,
linked back through `previousVersion`, with its own copy of every status,
result, role, property, document type and decision type. The version being
succeeded SHALL NOT be written to. Publishing the new version SHALL write
`supersededBy` onto the version it replaces. A superseded version SHALL keep
its cases running and SHALL NOT be offered for new cases.

A case SHALL stay on the version it started under, and SHALL NOT be migrated to
a newer one. No property is added to the case: `caseType` already names one
specific version.

**Feature tier**: V1

#### Scenario: A new version leaves the running cases alone
@e2e exclude Version-chain writes are backend logic, covered by tests/Unit/Service/CaseTypeCopyServiceTest.php and CaseTypePublishServiceTest.php.

- **GIVEN** a published case type Vergunning at version 2 with five running cases
- **WHEN** the administrator chooses New version
- **THEN** a draft version 3 SHALL exist, linked back to version 2
- **AND** version 2 SHALL be unchanged, including its statuses and its initial status
- **AND** the five cases SHALL still resolve their type, statuses and deadline through version 2

#### Scenario: Publishing a version closes the one it replaces
@e2e exclude Backend write, covered by tests/Unit/Service/CaseTypePublishServiceTest.php.

- **GIVEN** draft version 3 linked back to published version 2
- **WHEN** the administrator publishes version 3 with a change note
- **THEN** version 2 SHALL carry `supersededBy` naming version 3
- **AND** version 2 SHALL still not be a draft, because its cases still run on it

#### Scenario: Only the current version is offered for a new case
@e2e exclude Pure rule, covered by tests/vitest/caseTypeVersion.spec.js.

- **GIVEN** version 2 superseded by version 3, both published and both valid today
- **WHEN** somebody starts a new case
- **THEN** only version 3 SHALL be offered
- **AND** asking for version 2 by id SHALL be refused with a sentence naming the replacement, not one about drafts or expiry

#### Scenario: A duplicate is not a version
@e2e exclude Backend write, covered by tests/Unit/Service/CaseTypeCopyServiceTest.php.

- **GIVEN** a published case type
- **WHEN** the administrator chooses Duplicate
- **THEN** the copy SHALL carry a new identifier, a "Copy of" title and version 1
- **AND** it SHALL carry no `previousVersion`, because it is a second case type rather than the same one later on

### Requirement: A copied case type starts its own cases in its own status (REQ-ZV-03)

Copying or versioning a case type SHALL repoint the new type's `initialStatus`
at its own copy of that status. A new type whose initial status names a status
owned by another type files cases into a lifecycle it cannot move them out of.

**Feature tier**: V1

#### Scenario: The initial status follows the copy
@e2e exclude Backend write, covered by tests/Unit/Service/CaseTypeCopyServiceTest.php.

- **GIVEN** a case type whose initial status is its own status Ontvangen
- **WHEN** it is duplicated or versioned
- **THEN** the new type's initial status SHALL be the new type's own copy of Ontvangen
- **AND** publish validation SHALL NOT ask for a status the administrator already picked

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

### Requirement: The case type page shows its version chain (REQ-ZV-04)

You see every version of a case type on its page. `#CaseTypeDetail` SHALL
carry a Version chain panel listing every `caseType` row with the same
`identifier`, newest version first, with version, draft or published, valid
from and valid until.

#### Scenario: Two versions are listed
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case type with a published version 1 and a draft version 2
- **WHEN** you open the page of version 1
- **THEN** the Version chain panel SHALL list version 2 above version 1
- **AND** version 2 SHALL read as draft

### Requirement: New version and Deprecate are actions on the page (REQ-ZV-05)

You start the next version from the page. `#CaseTypeDetail` SHALL offer New
version, which calls `POST /api/case-definitions/{id}/new-version` and opens
the created draft, and Deprecate, which sets `validUntil` to today and is
offered only on a published version that has a successor.

#### Scenario: New version opens the draft
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published case type
- **WHEN** you press New version
- **THEN** the page of the new draft SHALL open
- **AND** its `previousVersion` SHALL reference the version you came from

#### Scenario: Deprecate is refused on the only version
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published case type without a successor
- **WHEN** you open the header actions
- **THEN** Deprecate SHALL NOT be offered

### Requirement: The index shows the current version (REQ-ZV-06)

`#CaseTypes` SHALL list one row per identifier, the version without a
successor, and SHALL offer a chip All versions that lists every version.

#### Scenario: Superseded versions are hidden by default
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case type with versions 1 and 2, version 2 current
- **WHEN** you open the Case types index
- **THEN** only version 2 SHALL be listed
- **AND** pressing All versions SHALL list both

### Requirement: A new version carries the workflow of the version it succeeds (REQ-ZV-07)

A new version of a case type SHALL carry the workflow templates of the version
it succeeds, and its `workflowDefinition` SHALL name its own copy of the pinned
template. When there is no copy to name, `workflowDefinition` SHALL be empty
rather than name another version's template.

#### Scenario: The next version keeps the process
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published case type with a pinned workflow
- **WHEN** you start a new version
- **THEN** the draft SHALL carry a copy of that workflow
- **AND** its `workflowDefinition` SHALL name the copy, not the original

### Requirement: Publishing closes the version it replaces (REQ-ZV-08)

Publishing a version SHALL write `supersededBy` on the version it replaces AND
close that version's `validUntil` on the day the new version takes effect. A
`validUntil` already set SHALL NOT be moved.

#### Scenario: The replaced version stops being open ended
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a published version 1 and a draft version 2
- **WHEN** you publish version 2
- **THEN** version 1 SHALL carry `supersededBy` naming version 2
- **AND** version 1 SHALL carry a `validUntil`
- **AND** version 1 SHALL stay published, because its cases still run on it

### Requirement: A running case moves to another version only as a named act (REQ-ZV-09)

A running case SHALL stay on the version it was filed under unless somebody
moves it deliberately. `#CaseDetail` SHALL offer Move to another version, which
SHALL show, before anything is written, the status the case lands in, the
statuses and fields the target version adds and drops, and which of the dropped
fields this case has answered. The act SHALL require a reason and SHALL record
both versions, the reason and the actor on the case.

The act SHALL refuse, naming the status, when the target version carries no
status by the name the case is currently in. The act SHALL refuse a target that
is not another version of the case's own case type.

#### Scenario: The preview says where the case lands
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case running on version 1 of a case type with a published version 2
- **WHEN** you ask what moving it to version 2 would change
- **THEN** the answer SHALL name the status it lands in
- **AND** that status SHALL be version 2's own row, not version 1's

#### Scenario: A status the target version dropped refuses the move
@e2e tests/e2e/case-type-version-chain.spec.ts

- **GIVEN** a case in a status the target version does not carry
- **WHEN** you move it to that version
- **THEN** the move SHALL be refused
- **AND** the refusal SHALL name that status
- **AND** the case SHALL stay on the version it was on

#### Scenario: The same act runs over a selection
@e2e exclude a bulk job over a seeded caseload is a background run, and watching it finish means polling the job; the action's rehearsal, its refusal and its commit are asserted in tests/Unit/BulkAction/CaseBulkActionsTest.php against the same service the single-case act calls

- **GIVEN** a selection of cases on one version of a case type
- **WHEN** you run the bulk move onto another version
- **THEN** the rehearsal SHALL report each case that has nowhere to land
- **AND** it SHALL name the status for each of them

### Requirement: Translatable case type and status labels read as text in the reader's language (REQ-ZV-11)

Wherever the change case type dialog, the version move dialog or the
rebind journal names a case type or a status, it SHALL resolve a
translatable value stored as a language map to one string: the reader's
language first (a regional code also accepts its base language), then
Dutch, then the first non-empty text in the map. Rows of a case type
SHALL be told apart by that text, read the same way for every reader, so
two statuses with different names SHALL never merge into one. The literal
"Array" SHALL never be shown.

#### Scenario: Status options are separate and readable

- GIVEN a case type whose statuses are stored as `{"nl": "Ontvangen"}`,
  `{"nl": "In behandeling"}` and `{"nl": "Afgehandeld"}`
- WHEN a coordinator previews a rebind onto that case type
- THEN the dialog offers three statuses named "Ontvangen",
  "In behandeling" and "Afgehandeld"

#### Scenario: The reader's language wins

- GIVEN a target case type titled `{"nl": "Omgevingsvergunning", "en": "Environmental permit"}`
- WHEN a coordinator whose language is English opens the dialog
- THEN the target reads "Environmental permit"
- AND for a coordinator whose language is Dutch it reads "Omgevingsvergunning"

#### Scenario: A map without the reader's language falls back

- GIVEN a status stored as `{"de": "In Bearbeitung"}` only
- WHEN an English or Dutch reader previews the rebind
- THEN the status reads "In Bearbeitung"

### Requirement: A version move reads the answers in the register's list shape (REQ-ZV-12)

The version move preview SHALL report as answered every dropped field the
case holds a non-empty value for, reading `case.properties` as the
register stores it: a list of `{propertyDefinition, name, value}` entries.
A legacy name-keyed map SHALL still be read.

#### Scenario: An answered field that the next version drops is named

- GIVEN a case whose `properties` list holds `oppervlakte` with value 42
- AND the next version of its case type has no field `oppervlakte`
- WHEN the handler previews the version move
- THEN `oppervlakte` is listed among the answers about to be lost
