## ADDED Requirements

### Requirement: A task form keeps what you typed (REQ-TM-DRAFT-01)

The task form and the case edit form SHALL keep the user's unsaved input when the dialog closes, the page reloads or the tab is lost, and SHALL offer it back the next time the same form opens for the same object. A user SHALL be able to save the form as a draft ("Voorlopig") without passing validation; the draft SHALL be the destination object in status draft.

#### Scenario: Input survives a closed dialog

- **GIVEN** a handler has filled 12 of 27 fields of a task form
- **WHEN** they close the dialog and open the same task form again
- **THEN** the 12 values SHALL be offered back with a note that unsaved input was restored
- @e2e tests/e2e/task-form-draft.spec.ts

#### Scenario: Save as draft skips validation

- **GIVEN** a task form with two required fields left empty
- **WHEN** the handler chooses "Opslaan als voorlopig"
- **THEN** the object SHALL be stored in status draft with the values given
- **AND** no required-field error SHALL be shown
- @e2e tests/e2e/task-form-draft.spec.ts
