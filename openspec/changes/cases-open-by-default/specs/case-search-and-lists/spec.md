# case-search-and-lists Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [cases-open-by-default](../../)

## ADDED Requirements

### Requirement: The open-cases list opens on open cases

A case list whose count line speaks of open cases in the reader's teams SHALL open on those cases (`isFinalStatus: false`
and `assignedGroup: @myTeam`) as removable filters, unless the address carries filters of its own or a saved view is
opened. A reader in no team SHALL get no team filter.

#### Scenario: DqZaken

- GIVEN /apps/dossiq/cases opened from the menu
- THEN no closed case and no other team's case is listed, and the Active line reads "Status: lopend" and "Team: Woo en informatie"
