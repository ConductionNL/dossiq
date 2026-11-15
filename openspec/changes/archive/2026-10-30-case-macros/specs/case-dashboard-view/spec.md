# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-macros](../../)

## ADDED Requirements

### Requirement: The case schema ships the board's two macros

The case schema SHALL declare the macros "Finish the Woo request" (status to Besluit, the Publiceren task) and "Suspend
for supplementary information" (the term suspended under the case type's rules, the handler asked to send the message
Aanvullende gegevens), each bound by name to a manual flow the schema declares. Every step that changes the case SHALL
carry a label written for people.

#### Scenario: DqZaakAssistent

- **WHEN** a handler opens AI-toezicht on a case
- **THEN** the Macro's card lists "Woo-verzoek afronden" and "Opschorten voor aanvulling"
- **AND** after "Opschorten voor aanvulling" ran, the card says "2 wijzigingen: termijn opgeschort, bericht Aanvullende gegevens klaargezet"
