# party-pages Delta

## ADDED Requirements

### Requirement: A colleague can be a party on a case

dossiq SHALL declare a `colleague` party schema tied to a Nextcloud user. The case schema SHALL accept the `colleague`
kind, and every kind it accepts SHALL name its identity shapes, so that a colleague, a person and an organisation are
checked against pipelinq's role kinds in one vocabulary.

#### Scenario: Lars de Koning on a case

- **GIVEN** the colleague Lars de Koning (user `lars`) linked to a case in the role Adviseur
- **THEN** the case's parties show him without "Soort niet geaccepteerd"
