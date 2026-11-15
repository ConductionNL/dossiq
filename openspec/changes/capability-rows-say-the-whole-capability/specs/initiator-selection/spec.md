# initiator-selection

## ADDED Requirements

### Requirement: A handler searches persons by BSN (REQ-CRS-21)

The party picker SHALL find a person by an exact BSN for a handler allowed to look citizens up, and SHALL refuse the search for anyone else. Every search SHALL be recorded.

Rows: `9.9` (dossiq matrix).

#### Scenario: A handler finds a person
- **GIVEN** a handler allowed to look citizens up
- **WHEN** they type a valid nine digit BSN
- **THEN** the person with that BSN is offered and the search is recorded
