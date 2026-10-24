# task-list Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-list-subject-links-its-case](../../)

## ADDED Requirements

### Requirement: The task list links a task's case with its number

The Tasks list SHALL show the subject of a dossiq task as a link to that case's page, with the case number on a
muted line under it. A task of another app SHALL show its subject as text.

#### Scenario: DqTaken

- **GIVEN** the task "Hoorzitting inplannen" on case 2026-0074 "Bezwaar parkeerboete Havenstraat"
- **THEN** its Subject cell links "Bezwaar parkeerboete Havenstraat" to the case page, with "2026-0074" under it
