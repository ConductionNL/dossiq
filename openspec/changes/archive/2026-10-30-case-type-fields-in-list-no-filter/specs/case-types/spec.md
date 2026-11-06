# case-types Delta

## ADDED Requirements

### Requirement: The In the list column of the fields table filters as drawn

The case type fields table SHALL draw a sort control and a filter on its "In the list" column, as on its other columns.
Field names SHALL keep their words whole without giving up that filter (the reorder grip's width is held by nc-vue).

#### Scenario: DqZaaktypeVelden

- **GIVEN** the Fields tab of a case type
- **WHEN** the Velden table is drawn
- **THEN** "In de lijst" has a sort control and a funnel, and "Welstandsgebied" reads on one line
