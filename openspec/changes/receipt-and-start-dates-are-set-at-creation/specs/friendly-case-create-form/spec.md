## MODIFIED Requirements

### Requirement: REQ-FCF-001 The New Case Dialog Is The Plain Form

The `new-case` header action SHALL open the plain schema-driven form, never the properties-and-JSON table. The action SHALL declare `includeFields` naming exactly the fields collected at create time: `caseType`, `title`, `requester`, `intakeChannel`, `description`, `plannedEndDate`, `assignee`, `confidentiality`, `impact`, `urgency`. The case detail page remains the surface for every other property. `startDate` SHALL NOT be asked: dossiq sets it when the case is created (REQ-TERM-042).

#### Scenario: The dialog carries no schema-inspection tabs

- **GIVEN** a case handler is on the dossiq dashboard
- **WHEN** they press "New case"
- **THEN** the dialog SHALL show neither a "Properties" tab nor a "Data" tab
- **AND** it SHALL offer a "Create" button, disabled until the required fields are answered

#### Scenario: The dialog asks only for create-time fields
@e2e tests/e2e/case-create-form.spec.ts

- **GIVEN** the New case dialog is open
- **THEN** it SHALL render a field for each of the ten declared `includeFields`
- **AND** it SHALL render no field for `startDate`, `result`, `workflowTemplate`, `archiveNomination`, `qualityScore`, `casePlanState`, `statusHistory` or `portalSubject`
