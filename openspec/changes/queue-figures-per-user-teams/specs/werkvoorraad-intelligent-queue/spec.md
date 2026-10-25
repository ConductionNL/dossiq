# werkvoorraad-intelligent-queue Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [queue-figures-per-user-teams](../../)

## ADDED Requirements

### Requirement: The team queue is the unassigned open cases of the reader's teams

The team queue's list, its "Zonder behandelaar" tile and its menu badge SHALL count the same cases: open cases without
a handler whose team is one of the reader's teams. A reader's teams SHALL be the organisation roles that bind one of the
reader's groups and are no ancestor of another such role; the admin group SHALL never be a team. For a reader in no
team all three SHALL count without a team filter.

#### Scenario: DqTeamwachtrij

- GIVEN Pieter in the groups admin, zuiddrecht-medewerkers, zuiddrecht-woo-en-informatie and zuiddrecht-vergunningen
- THEN his teams are Woo en informatie and Vergunningen, and the tile, the list total and the badge read the same number
