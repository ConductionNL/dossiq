## ADDED Requirements

### Requirement: A request is filed on the current version of the case type

The Woo request intake SHALL file a new request on the version of the Woo request case type that nothing has superseded, found from the seeded first version along `supersededBy`. A link that cannot be read SHALL end the walk on the last readable version, and a loop SHALL end where it would repeat.

#### Scenario: version 2 is current
- **GIVEN** version 1 is superseded by version 2
- **WHEN** a resident sends a request
- **THEN** the case is filed on version 2

### Requirement: Every version of the Woo case type is a Woo request

A rule that holds for Woo requests only SHALL hold for a case on any version of the Woo request case type: the first version by its fixed id, a later one by the shared identifier.

#### Scenario: a clarification on version 2
- **WHEN** a handler asks a clarification on a case of version 2
- **THEN** it is asked, as on version 1, and refused on a case of another type
