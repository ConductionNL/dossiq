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
