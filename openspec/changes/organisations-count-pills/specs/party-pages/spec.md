# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisations-count-pills](../../)

## ADDED Requirements

### Requirement: The organisations list shows counts as pills and none as text

The organisations list SHALL show a count of open cases or open tickets as a purple pill and an empty count as the
muted text "Geen", in columns wide enough for their headers.

#### Scenario: DqOrganisaties

- **GIVEN** Buurtvereniging Oost with 1 open case and Sportclub Zuiddrecht with none
- **THEN** Buurtvereniging Oost's Lopende zaken reads a purple "1" pill and Sportclub's the muted "Geen"
