# berichtenbox-integration Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [dialogs-answer-what-the-boards-draw](../../)

## ADDED Requirements

### Requirement: A send without a provider says so by code

A Berichtenbox send SHALL answer HTTP 400 with `code: provider-not-configured` when no digital post provider is set up
(integriq missing, without the seam, or without a source), and SHALL record the message as not sent. It SHALL never
simulate a send.

#### Scenario: No source

- **GIVEN** no integriq digital post source is set
- **WHEN** a handler sends a message
- **THEN** the answer is 400 with `code: provider-not-configured`
