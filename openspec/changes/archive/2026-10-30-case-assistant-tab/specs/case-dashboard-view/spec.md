# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-assistant-tab](../../)

## ADDED Requirements

### Requirement: The case page holds the assistant as a tab

The simple case page SHALL offer an "AI oversight" tab that holds the AI Chat Companion embedded (`ai-chat`), with
the case as its context, and SHALL say hermiq is not installed when it is absent. The tab SHALL wait under Acties and
SHALL take its place in the strip while it is open. The launcher hex and the Assistant sidebar tab SHALL stay.

#### Scenario: DqZaakAssistent

- **WHEN** a handler opens AI-toezicht from Acties on a case
- **THEN** the tab shows the assistant's window, open, with its agents, sessions and input
