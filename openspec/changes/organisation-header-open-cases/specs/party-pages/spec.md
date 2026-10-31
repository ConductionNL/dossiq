# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisation-header-open-cases](../../)

## ADDED Requirements

### Requirement: The organisation header line counts its open cases

The organisation page SHALL name the number of open cases the organisation requested in its header line, "1 lopende
zaak" or "2 lopende zaken", and SHALL show no such line when there are none.

#### Scenario: DqOrganisatie

- **GIVEN** Buurtvereniging Oost with one open case
- **THEN** the header line reads "Organisaties / Buurtvereniging Oost · 1 lopende zaak"
