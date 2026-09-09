## ADDED Requirements

### Requirement: You configure everything a status is, on the page (REQ-CT-10)

You configure a status without editing register JSON. The Statuses tab of a
case type SHALL edit every property the `statusType` schema declares: name,
order, description, colour, role, whether it is final, whether cases in it stay
out of the Cases index, and its checklist. The add form and the edit form SHALL
be the same form. A status saved before a property existed SHALL open with that
property unset rather than with a guessed value, and saving it SHALL NOT write
back any property the schema does not declare.

**Feature tier**: MVP

#### Scenario: A functional administrator gives a status a colour and a role
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** a case type with a status called In behandeling
- **WHEN** the administrator edits it, picks the colour orange and the role in progress, and saves
- **THEN** the status row SHALL show that colour
- **AND** a flow addressing the in-progress role SHALL resolve to this status

#### Scenario: A status asks for a checklist
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** a status being edited
- **WHEN** the administrator adds the checklist item Check identity and marks it required
- **AND** saves
- **THEN** the status row SHALL report one checklist item
- **AND** a case entering that status SHALL get a task called Check identity

#### Scenario: A blank checklist row is not saved
@e2e exclude Pure mapping, covered by tests/vitest/statusTypeForm.spec.js.

- **GIVEN** a status with one filled checklist item and one empty row
- **WHEN** the administrator saves
- **THEN** only the filled item SHALL be stored, because a task with no title is unactionable

#### Scenario: An older status opens without inventing values
@e2e exclude Pure mapping, covered by tests/vitest/statusTypeForm.spec.js.

- **GIVEN** a status saved before colour, role, hiddenInLists and checklist existed
- **WHEN** the administrator opens it
- **THEN** its colour SHALL be unset rather than grey, because a form that opens on grey saves grey
- **AND** saving it SHALL NOT carry back the notifyInitiator and notificationText properties it arrived with
