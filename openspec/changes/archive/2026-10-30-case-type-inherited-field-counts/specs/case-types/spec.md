# case-types Delta

## ADDED Requirements

### Requirement: The blueprint counts inherited and overridden fields

The case type blueprint SHALL count its fields: all of them, those a parent type gave, and those the type overrides by
declaring a row with the same name as a parent's. The parent type card SHALL show those counts for a type with a parent.

#### Scenario: DqZaaktypeVelden

- **GIVEN** Omgevingsvergunning regulier, a child of Omgevingsvergunning, with 14 fields of which 6 come from the parent and none replace a parent's
- **THEN** the card reads "Erft van Omgevingsvergunning", "Geërfde velden 6 van 14", "Overschreven 0"
