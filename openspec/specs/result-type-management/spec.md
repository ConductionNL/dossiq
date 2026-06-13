# result-type-management Specification

## Purpose
TBD - created by archiving change case-types-03-result-role-tabs. Update Purpose after archive.
## Requirements
### Requirement: Result Type Management Tab
The system SHALL provide an admin tab for managing result types on a case type.
Result types define the allowed outcomes for cases of this type, with archival
rules compliant with the Archiefwet/Selectielijst.

#### Scenario: View result types tab
- GIVEN an admin editing a case type "Omgevingsvergunning"
- WHEN they click the "Results" tab
- THEN the system MUST display all result types linked to this case type
- AND each row MUST show: name, archivalAction (retain/destroy badge), archivalPeriod (formatted)
- AND an "Add" button MUST be visible

#### Scenario: Add a result type with archival rules
- GIVEN an admin on the Results tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Vergunning verleend",
  archivalAction "blijvend_bewaren", archivalPeriod "P20Y"
- THEN the system MUST create a `resultType` OpenRegister object with `caseType`
  referencing the current case type
- AND the new result type MUST appear in the tab list

#### Scenario: Edit a result type
- GIVEN a result type "Vergunning verleend" with `archivalPeriod = "P20Y"`
- WHEN the admin changes `archivalPeriod` to "P25Y"
- THEN the result type MUST be updated in OpenRegister

#### Scenario: Delete a result type
- GIVEN a result type "Aanvraag ingetrokken" not referenced by any closed cases
- WHEN the admin deletes it and confirms the dialog
- THEN the result type MUST be removed from OpenRegister
- AND the tab list MUST no longer show this result type

#### Scenario: Name is required
- GIVEN the result type creation form
- WHEN the admin submits with an empty name
- THEN the system MUST reject with "Name is required"

### Requirement: Role Type Management Tab
The system SHALL provide an admin tab for managing role types on a case type.
Role types restrict which participant roles are available when assigning case
participants.

#### Scenario: View role types tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Roles" tab
- THEN the system MUST display all role types linked to this case type
- AND each row MUST show: name, description (truncated)
- AND an "Add" button MUST be visible

#### Scenario: Add a role type
- GIVEN an admin on the Roles tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Technisch adviseur" with a description
- THEN the system MUST create a `roleType` OpenRegister object linked to the current case type
- AND the new role type MUST appear in the tab list

#### Scenario: Edit a role type
- GIVEN a role type "Technisch adviseur"
- WHEN the admin renames it to "Externe adviseur"
- THEN the name MUST be updated in OpenRegister

#### Scenario: Delete a role type
- GIVEN a role type "Beslisser" not assigned on any active cases
- WHEN the admin deletes it
- THEN the role type MUST be removed from the case type

### Requirement: Case type detail MUST integrate the new tabs
The system SHALL register the Result and Role tabs in `CaseTypeDetail.vue` and
establish the tab-integration framework consumed by member 04.

#### Scenario: Tabs render in the case type detail view
- GIVEN an admin editing a case type
- WHEN the detail view loads
- THEN the General, Statuses, Results, and Roles tabs MUST render without console errors
- AND switching to Results or Roles MUST fetch the correct sub-entities scoped to the case type
- AND each tab component MUST receive the `caseTypeId` prop

