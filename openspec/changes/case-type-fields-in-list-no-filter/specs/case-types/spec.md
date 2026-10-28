# case-types Delta

## ADDED Requirements

### Requirement: The In the list column of the fields table has no filter

The case type fields table SHALL draw no filter on its "In the list" column; its other columns keep theirs.

#### Scenario: DqZaaktypeVelden

- **GIVEN** the Fields tab of a case type
- **WHEN** the Velden table is drawn
- **THEN** "In de lijst" has a sort control and no funnel, and "Welstandsgebied" reads on one line
