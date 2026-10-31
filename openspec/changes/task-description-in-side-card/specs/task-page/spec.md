# task-page Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-description-in-side-card](../../)

## ADDED Requirements

### Requirement: The task's description is in the side Task card

The task page SHALL show the task's description as the first line of the side Task card, and SHALL NOT draw a
separate description card in the Overview.

#### Scenario: DqTaak

- **GIVEN** "Hoorzitting inplannen" with the description "Plan de hoorzitting met de indiener en de commissie."
- **THEN** the Overview starts with Checklist
- **AND** the Taak card opens with that description
