# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-ai-tab-side-alone](../../)

## ADDED Requirements

### Requirement: The AI oversight tab has the side column to the assistant

While the AI oversight tab is open, the four generic side cards SHALL stand aside, so the Assistant chat is the side
column's card. Beside every other tab they SHALL show as before.

#### Scenario: DqZaakAssistent

- **WHEN** a handler opens AI-toezicht on a case
- **THEN** the side column holds the Assistent card and not Termijn, Verzoeker, Behandeling or Werkinstructie
