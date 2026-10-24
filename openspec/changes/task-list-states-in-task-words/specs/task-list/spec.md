# task-list Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-list-states-in-task-words](../../)

## ADDED Requirements

### Requirement: The task list names a state as the task page does

The Tasks list SHALL show a task's state in the words the task page's state pill uses, and SHALL colour it by the
state, not by the shown words: ready to claim blue, in execution purple, completed green, terminated red.

#### Scenario: DqTaken

- **GIVEN** an active task in the Tasks list, in Dutch
- **THEN** its Status pill reads "In uitvoering" in purple, as the pill on its task page
