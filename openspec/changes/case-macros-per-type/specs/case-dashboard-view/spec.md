# case-dashboard-view Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-macros-per-type](../../)

## ADDED Requirements

### Requirement: A case type chooses the macros its cases offer

A case type SHALL list the declared macros its cases offer, in order, and the Macro's card SHALL show only those. A case
type that lists none SHALL offer none.

#### Scenario: Woo request and objection

- **WHEN** a handler opens AI-toezicht on a Woo request
- **THEN** the card offers "Woo-verzoek afronden" and "Opschorten voor aanvulling"
- **AND** on an objection it says no macros are set up for the case type
