# catalogue Delta

## ADDED Requirements

### Requirement: An object type says in how many case types it is used

Each object type row SHALL carry the number of distinct case types of the cases that link an object of that type,
besides its number of cases, and the Objecttypen table SHALL show it under "Gebruikt in" as "N zaaktypen". When the
case schema is not configured the number SHALL be unknown and the cell empty.

#### Scenario: Boom linked from three cases of two case types

- **GIVEN** cases c1 and c2 of Woo-verzoek and c3 of Omgevingsvergunning link a Boom
- **THEN** the Boom row reads "Gebruikt in 2 zaaktypen" and still counts 3 cases
