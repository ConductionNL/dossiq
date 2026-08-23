# case-location-surface

## ADDED Requirements

### Requirement: Case locations are surfaced on the case index map and case detail, not a standalone list

Location data SHALL be surfaced through the `map` view mode on the case index
and through the case detail. The app SHALL NOT declare a standalone
`/settings/locations` index page or a navigation entry for it. The `location`
schema and its case-detail linkage SHALL be preserved — this requirement retires
a page, not data.

#### Scenario: The case index still plots locations

- **GIVEN** the `Cases` index page
- **THEN** its `config.viewModes` includes `map`
- **AND** `config.mapConfig` still declares the geo field and popup field

#### Scenario: The standalone locations page is gone

- **GIVEN** the manifest
- **THEN** no `Locations` page, no `LocationDetail` route, and no locations menu entry exist

#### Scenario: Location data survives

- **GIVEN** the procest register
- **THEN** the `location` schema is unchanged
- **AND** locations linked to a case remain visible on that case's detail page
