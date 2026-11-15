# admin-settings Delta

## ADDED Requirements

### Requirement: A connection row opens its settings card

The integrations tab SHALL list Kadaster (BRK) en WOZ as a connection, switched off while its source is None, and each
connection row SHALL link to its settings card on the same page. The Kadaster card SHALL show only once its row was
followed, saying it was opened from that row.

#### Scenario: DqInstellingen

- **GIVEN** the integrations tab with the Kadaster source on None
- **THEN** the Kadaster row reads Uit
- **WHEN** the row is followed
- **THEN** "Kadaster (BRK) en WOZ instellen" shows with "Geopend vanuit de rij Kadaster (BRK) en WOZ"
