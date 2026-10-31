# dossiq-sociaal-domein-jeugdwet Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [family-plan-by-case](../../)

## ADDED Requirements

### Requirement: A case finds its family plan by the plan's case

A case page SHALL find the family plan through the plan's `caseId`, show the plan's status in the report card, and
draw the plan card from that plan. A case without a plan SHALL show a plan without goals; an unreadable register
SHALL show an error, not an empty plan.

#### Scenario: DqSociaalDomein

- **GIVEN** the Jeugdwet example case and a gezinsplan with `caseId` that case and status `in_opbouw`
- **THEN** De melding reads "Gezinsplan: In opbouw"
- **AND** the Gezinsplan card reads "Dit plan heeft nog geen doelen"

#### Scenario: No plan

- **GIVEN** a case no gezinsplan points at
- **THEN** `/api/cases/{caseId}/family-plan` answers `{ plan: null, goals: [], interventions: [], dueForReview: false }`
