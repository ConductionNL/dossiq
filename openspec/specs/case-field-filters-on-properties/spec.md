# case-field-filters-on-properties Specification

## Purpose
How the case list filters on the fields a case type defines: the filter reads the case's own answers in its properties. Created by change case-field-filters-on-properties (2026-10-30).

## Requirements

### Requirement: The case list filters a case type's fields on the case's own answers

With one case type chosen, the case list's field filters SHALL narrow on the answers the case carries in `properties[]`,
one answer matching both the field and the value.

#### Scenario: A field with answers

- **GIVEN** cases of one case type answer its field "Oppervlakte" in `properties[]`
- **WHEN** the handler filters Oppervlakte from 100
- **THEN** the list shows the cases whose answer is 100 or more
