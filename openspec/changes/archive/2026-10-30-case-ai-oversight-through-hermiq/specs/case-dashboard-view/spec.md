# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-ai-oversight-through-hermiq](../../)

## ADDED Requirements

### Requirement: The AI oversight list reads through the case

The AI-toezicht list SHALL read the case's AI oversight records through hermiq's subject endpoint with the case as
subject, and SHALL name the outcomes used, adapted, not used and rejected.

#### Scenario: DqZaakAssistent

- **WHEN** a handler who may open the case opens AI-toezicht
- **THEN** the list shows the case's suggestions, a suggestion nobody acted on as "Niet gebruikt"
