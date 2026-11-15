# task-list Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-list-pooled-handler](../../)

## ADDED Requirements

### Requirement: The task list names the team of an unclaimed task

The Tasks list SHALL show, for a task nobody holds, the team it is offered to in the Behandelaar column, as
"<team>, niet geclaimd" with the team's initials.

#### Scenario: DqTaken

- **GIVEN** an unclaimed task offered to the group "Team Woo"
- **THEN** its Behandelaar cell reads "TW  Team Woo, niet geclaimd"
