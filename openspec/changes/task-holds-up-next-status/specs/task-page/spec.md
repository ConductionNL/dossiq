# task-page Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [task-holds-up-next-status](../../)

## ADDED Requirements

### Requirement: A task says which step its case waits to move to

A task that a flow run waits on SHALL say which status its case moves to once the task is done (the case type's next
status) and since when the flow has waited on it.

#### Scenario: DqTaak

- **GIVEN** "Hoorzitting inplannen" on an objection case in the status before "Beslissing op bezwaar"
- **THEN** the card reads "De zaak gaat pas naar Beslissing op bezwaar als deze taak klaar is."
