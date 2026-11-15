# case-flow-human-steps

## ADDED Requirements

### Requirement: A person fills in and submits a task's form, and an invalid answer is refused (REQ-CRS-29)

A task whose step declares a form SHALL show that form on the task page. Submitting SHALL check every field, refuse the submission naming each invalid field, and complete the task once all fields pass.

Rows: `3.4` (dossiq matrix).

#### Scenario: A required field left empty
- **GIVEN** a task form with a required field "Advice"
- **WHEN** the person submits it with Advice empty
- **THEN** the submission is refused naming Advice and the task stays open
