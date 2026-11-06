# cases-default-order Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [cases-default-order](../../)

## Purpose

The cases list is in the order it says it is in.

## ADDED Requirements

### Requirement: The cases list opens sorted by deadline

The Cases page SHALL declare `defaultOrder: "deadline"`, a column it shows, so the list opens ordered by deadline and
the Deadline header carries `aria-sort="ascending"`.

#### Scenario: DqZaken

- **GIVEN** the cases list opened without an order in the address
- **THEN** the rows are ordered by deadline, earliest first, and the Deadline ("Termijn") header is marked sorted
