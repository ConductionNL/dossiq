## ADDED Requirements

### Requirement: A case type answers its phases with their tasks (REQ-CTP-1)

`GET /api/case-types/{id}/phases` SHALL answer the case type's statuses as
phases in their order, each with the tasks of the active workflow definition
whose step names that status, in step order, with title, role, lead time and
whether the step is required, and the acts available in every phase. A step
whose task is switched off SHALL be left out; a step naming an unknown status
SHALL be listed under `unplaced`. An unreadable case type SHALL answer 404.

#### Scenario: Tasks sit under their phase
@e2e exclude a read endpoint covered by tests/Unit/Service/CaseType/CaseTypePhasesTest.php

- **GIVEN** a case type with statuses Ontvangst and Beoordeling and steps on both
- **WHEN** its phases are requested
- **THEN** each step SHALL be listed under its status, in order
