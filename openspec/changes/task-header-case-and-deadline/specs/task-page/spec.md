# task-page Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-header-case-and-deadline](../../)

## ADDED Requirements

### Requirement: The task page header names its case and deadline

The task page's header line SHALL name the number of the case the task belongs to and the task's deadline, the
deadline as today, tomorrow or yesterday when it is one of those and as a short date otherwise.

#### Scenario: DqTaak

- **GIVEN** the task "Hoorzitting inplannen" on case 2026-0074, due today
- **THEN** the header line reads "bij zaak 2026-0074, deadline vandaag"
