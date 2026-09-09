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
