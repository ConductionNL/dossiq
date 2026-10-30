# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-ai-oversight-tab](../../)

## ADDED Requirements

### Requirement: The AI oversight tab shows what the assistant did, the macros and the chat

The case page's AI oversight tab SHALL list hermiq's advisory decisions on the case, newest first, with the moment,
the user, the question or suggestion, the outcome and the model; SHALL offer the case schema's macros; and SHALL
show the assistant chat in the side column while it is open.

#### Scenario: DqZaakAssistent

- **WHEN** a handler opens AI-toezicht on a case
- **THEN** "Wat de assistent op deze zaak deed" lists the decisions recorded for this case
- **AND** "Macro's" lists the macros the case schema declares, or says there are none
- **AND** the side column holds the Assistant chat
