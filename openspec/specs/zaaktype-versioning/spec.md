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
