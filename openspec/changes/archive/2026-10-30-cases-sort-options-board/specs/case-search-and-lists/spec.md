# case-search-and-lists Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [cases-sort-options-board](../../)

## ADDED Requirements

### Requirement: The case list offers the orders the board draws

The Cases page SHALL offer, in this order, sorting by deadline, case title, handler and last change (newest first),
followed by urgency and newest. A list sorted by deadline SHALL show "Termijn" as the chosen order.

#### Scenario: DqZakenSorteren

- GIVEN the Cases cards sorted by deadline, ascending, with the side panel open
- THEN "Sorteren op" reads "Termijn" and the line under the direction reads the order in words, not the field name
