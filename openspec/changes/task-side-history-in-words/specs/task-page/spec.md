# task-page Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-side-history-in-words](../../)

## ADDED Requirements

### Requirement: The task page's side history reads in words

The side "Historie" card on the task page SHALL name each engine action in the same words as the task's history
tab, with the actor where the action has one.

#### Scenario: DqTaak

- **GIVEN** a task that Pieter Jansen picked up
- **THEN** the side Historie card reads "Taak opgepakt door Pieter Jansen", not `claim (active)`
