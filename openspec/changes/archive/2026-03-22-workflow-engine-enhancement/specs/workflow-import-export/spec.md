## ADDED Requirements

### Requirement: Export Workflow Template

The system SHALL allow administrators to export a workflow template (including all steps, transitions, guards, and actions) as a JSON file for transfer between environments (OTAP).

**Feature tier**: V1

#### Scenario: Export workflow to JSON

- **WHEN** an administrator clicks "Exporteren" on workflow template "Omgevingsvergunning v3"
- **THEN** the system SHALL generate a JSON file containing the complete workflow definition
- **AND** the JSON SHALL include: template metadata, all steps with their properties, all transitions with guards and actions, referenced StatusType names (not UUIDs)
- **AND** the file SHALL be downloaded as `omgevingsvergunning-v3-workflow.json`

#### Scenario: Export uses names instead of UUIDs for portability

- **WHEN** a workflow template is exported
- **THEN** all references to StatusTypes, RoleTypes, and DocumentTypes SHALL use their `name` property instead of UUIDs
- **AND** the export SHALL include a manifest section listing all referenced types for validation at import time

### Requirement: Import Workflow Template

The system SHALL allow administrators to import a workflow template JSON file into a zaaktype, matching referenced types by name and creating the workflow as a new draft version.

**Feature tier**: V1

#### Scenario: Import workflow with matching types

- **WHEN** an administrator imports a workflow JSON file into zaaktype "Omgevingsvergunning"
- **AND** all referenced StatusTypes, RoleTypes, and DocumentTypes exist on the target zaaktype (matched by name)
- **THEN** the system SHALL create a new draft workflow template version
- **AND** all steps, transitions, guards, and actions SHALL be recreated with the target environment's UUIDs

#### Scenario: Import with missing types

- **WHEN** an administrator imports a workflow JSON file
- **AND** the source workflow references StatusType "Advies extern" which does not exist on the target zaaktype
- **THEN** the system SHALL display a validation report listing missing types
- **AND** the administrator SHALL be offered the option to: (a) auto-create the missing types, or (b) cancel the import

#### Scenario: Import does not overwrite active workflow

- **WHEN** an administrator imports a workflow into a zaaktype that already has an active workflow
- **THEN** the imported workflow SHALL be created as a new draft version
- **AND** the currently active workflow SHALL remain unchanged
- **AND** the administrator must explicitly publish the imported version to activate it
