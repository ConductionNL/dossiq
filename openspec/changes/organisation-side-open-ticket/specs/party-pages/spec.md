# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisation-side-open-ticket](../../)

## ADDED Requirements

### Requirement: The organisation page shows its open ticket beside its open case

With pipelinq installed, the organisation page's side column SHALL show "Open tickets" right after "Lopende zaken",
with the organisation's first open ticket: its title, type, status pill and term. Without pipelinq it SHALL NOT show
the card.

#### Scenario: DqOrganisatie

- **GIVEN** Buurtvereniging Oost with the open ticket "Aanvraag collectevergunning buurtfeest"
- **THEN** the side column shows "Open tickets" with that ticket under "Lopende zaken"
