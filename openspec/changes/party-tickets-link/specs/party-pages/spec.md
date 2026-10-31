# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [party-tickets-link](../../)

## ADDED Requirements

### Requirement: The tickets card links to the party's tickets in pipelinq

The Tickets card on a contact page and on an organisation page SHALL carry a header link "Alle <n> tickets bekijken"
that opens pipelinq's ticket list on that party's tickets.

#### Scenario: DqContact

- **GIVEN** Sanne de Vries with two open tickets
- **WHEN** the reader follows "Alle 2 tickets bekijken"
- **THEN** pipelinq opens its ticket list on her tickets
