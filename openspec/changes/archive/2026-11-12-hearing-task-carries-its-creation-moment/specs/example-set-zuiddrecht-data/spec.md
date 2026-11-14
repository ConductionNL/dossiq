# example-set-zuiddrecht-data Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [hearing-task-carries-its-creation-moment](../../)

## ADDED Requirements

### Requirement: The hearing task carries the moment its run created it

The Zuiddrecht set's task "Hoorzitting inplannen" SHALL carry `createdAt` equal to the `created` of its bezwaar run,
so the task page reads the creation moment the board draws.

#### Scenario: DqTaak

- **GIVEN** the set installed on the board's 7 okt
- **THEN** the task reads created on 5 okt 16:20 and claimed by Pieter on 6 okt 09:05
