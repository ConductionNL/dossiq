# friendly-case-create-form

## ADDED Requirements

### Requirement: A multi-step form shows or skips a step by a condition (REQ-CRS-30)

A step of a multi-step form SHALL be able to carry a condition on earlier answers. The step SHALL be shown when the condition holds and skipped when it does not.

Rows: `3.12` (dossiq matrix).

#### Scenario: A skipped step
- **GIVEN** a form whose step "Company details" shows only when the requester is a company
- **WHEN** a person answers that they apply as a private person
- **THEN** the form goes on without showing Company details
