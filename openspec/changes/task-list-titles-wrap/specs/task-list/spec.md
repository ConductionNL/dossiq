# task-list Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-list-titles-wrap](../../)

## ADDED Requirements

### Requirement: The task list shows a title and subject in full

The Tasks list SHALL show a task's title and its subject in full, wrapping over lines within their columns, rather
than cutting them with an ellipsis.

#### Scenario: DqTaken

- **GIVEN** a task "Bezwaarschrift aanvullen bij de indiener" in the Tasks list
- **THEN** its title reads in full over three lines
