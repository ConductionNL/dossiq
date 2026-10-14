# status-transition-engine Delta: woo-stages-are-guarded-by-their-flow

**Status**: draft
**Scope**: the guard registry, and the flow the Woo request case type ships.

## ADDED Requirements

### Requirement: A move MAY require the case's documents gathered or assessed

The engine SHALL offer a guard kind `documentAssessment`. With `require: gathered` it SHALL pass only when the case has at least one document under review. With `require: assessed` it SHALL pass only when the case has at least one document under review and none waits for an assessment. When the documents cannot be read, it SHALL fail with a sentence saying so.

#### Scenario: Nothing gathered

- **GIVEN** a case with no documents under review
- **WHEN** the handler opens the moves out of its status
- **THEN** a move guarded by `documentAssessment` is offered disabled with "Er zijn nog geen documenten verzameld"

#### Scenario: One document still waits

- **GIVEN** a case with two documents under review and one assessed
- **THEN** a move guarded by `require: assessed` is disabled with "1 van 2 documenten wachten nog op een beoordeling"

### Requirement: The Woo request case type MUST ship a guarded flow

The Woo request case type SHALL name a shipped flow as its default route. In that flow, assessing documents SHALL need gathered documents. Redaction and the decision stage SHALL need every gathered document assessed. Publication SHALL be withheld until the decision is made. Closing from Publicatie SHALL be withheld until the decision is published. The resident's withdrawal SHALL have a direct move from every status it is open in.

#### Scenario: The 13 October walk is refused

- **GIVEN** a new Woo request with no documents and no decision
- **WHEN** the coordinator tries to take it to Publicatie
- **THEN** the move into Beoordelen documenten is refused, and so is every move after it

#### Scenario: Publication waits for the decision

- **GIVEN** a Woo request in Besluit whose documents are all assessed
- **AND** no decision is made
- **THEN** "Naar publicatie" is withheld, naming "het besluit (stel het besluit op)"
