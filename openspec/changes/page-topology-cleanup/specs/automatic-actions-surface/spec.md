# automatic-actions-surface

## ADDED Requirements

### Requirement: Automatic actions are OpenRegister flow definitions

Per ADR-065, OpenRegister is the only home for a flow engine and no leaf app
grows a second one. Procest's automatic actions SHALL be expressed as
OpenRegister flow definitions and administered through OpenRegister's `/flows`
surface. Procest SHALL NOT declare an automatic-actions index or detail page.

#### Scenario: Procest hosts no automatic-actions pages

- **GIVEN** the procest manifest
- **THEN** neither `/settings/automatic-actions` nor `/settings/automatic-actions/:id` exists
- **AND** no automatic-actions menu entry exists

#### Scenario: Existing automations keep running

- **GIVEN** an automatic action configured before this change
- **WHEN** its trigger condition occurs on a case
- **THEN** the corresponding OpenRegister flow executes and produces the same effect
- **AND** the run is visible in OpenRegister's flow-run log
