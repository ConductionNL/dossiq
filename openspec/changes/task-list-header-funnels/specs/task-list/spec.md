# task-list Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-list-header-funnels](../../)

## ADDED Requirements

### Requirement: The task list filters from its column headers

The task list's Subject, Status, Priority and Deadline columns SHALL filter on the task inbox's search fields
(`objectUuid`, `state`, `priority`, `dueAt`), so their headers carry a funnel that writes the sidebar's filter.

#### Scenario: DqTaken

- **GIVEN** the task list with the month's deadline filter
- **THEN** Onderwerp, Status, Prioriteit and Deadline carry a funnel, the Deadline one active
- **AND** Taak and Behandelaar carry none
