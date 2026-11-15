# case-search-and-lists Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [cases-three-chips-then-more](../../)

## ADDED Requirements

### Requirement: The case list shows three lens chips, the rest behind More

In the simple profile the Cases list SHALL show its first three lenses (All, Mine, Due this week) as chips on the row and
every other lens, in its order, behind the More chip.

#### Scenario: DqZaken

- GIVEN the Cases list
- THEN the row reads "Alles · Mijn zaken · Termijn deze week · ⋯"
