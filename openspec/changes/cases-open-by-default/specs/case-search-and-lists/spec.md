# case-search-and-lists Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [cases-open-by-default](../../)

## ADDED Requirements

### Requirement: The open-cases list opens on open cases

A case list whose count line speaks of open cases SHALL open on the open cases (`isFinalStatus: false`) as a removable
filter, unless the address carries filters of its own or a saved view is opened.

#### Scenario: DqZaken

- GIVEN /apps/dossiq/cases opened from the menu
- THEN no closed case is listed and the Active line reads "Status: lopend"
