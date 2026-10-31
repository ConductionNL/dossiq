# case-field-filters-on-properties Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The case list filters a case type's fields on the case's own answers

With one case type chosen, the case list's field filters SHALL narrow on the answers the case carries in `properties[]`,
one answer matching both the field and the value.

#### Scenario: A field with answers

- **GIVEN** cases of one case type answer its field "Oppervlakte" in `properties[]`
- **WHEN** the handler filters Oppervlakte from 100
- **THEN** the list shows the cases whose answer is 100 or more
