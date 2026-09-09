## ADDED Requirements

### Requirement: The case page lists every party in a Parties tab (REQ-ROLE-007)

You see everyone involved in the case with their role. The `case-panels` tabs
widget on `CaseDetail` SHALL carry a tab Parties that renders widget
`case-roles`, type `object-list`, over schema `role` filtered on
`case = @objectId`, sorted by role type, with the columns role type,
participant, delegate and delegation end date. The tab SHALL sit between
Documents and Tasks in the tab order. The list SHALL show the empty state
"No parties yet" when the case has no role rows. This refines REQ-ROLE-005:
the grouped ParticipantsSection is replaced by the declarative list.

#### Scenario: Parties visible on the case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case with a handler role and an advisor role
- **WHEN** you open the case page and pick the Parties tab
- **THEN** the list SHALL show both rows with role type, participant, delegate and delegation end date
- **AND** the tab SHALL be reachable by its id `case-roles` without scrolling past Data

#### Scenario: A case without parties says so
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case with no role rows
- **WHEN** you open the Parties tab
- **THEN** the list SHALL show the empty state and the Add party action

### Requirement: You add a party from the case page with the case filled in (REQ-ROLE-008)

You add a person or an organisation with a role without leaving the case. The
Parties tab SHALL carry a header action Add party of type `open-form` over
schema `role` with `props: {"case": "@objectId"}`, showing the fields role
type, participant, delegate, delegate until and description. On success the
list SHALL refresh and show the new row. The `case` field SHALL be prefilled
and read only.

#### Scenario: Add a party with the case prefilled
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an open case
- **WHEN** you press Add party, choose the role type Advisor and a participant, and save
- **THEN** the new row SHALL appear in the Parties list
- **AND** the saved role row SHALL reference the case you were on

#### Scenario: Role validation still runs
@e2e exclude REQ-ROLE-006 validation runs in OpenRegister and is covered by tests/Unit for the schema; the form only forwards the error

- **GIVEN** the Add party form
- **WHEN** you save without a role type
- **THEN** the form SHALL show the validation error from the platform and keep your input
