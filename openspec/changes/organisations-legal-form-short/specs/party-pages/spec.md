# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisations-legal-form-short](../../)

## ADDED Requirements

### Requirement: The organisations list abbreviates the legal form as the board does

The organisations list SHALL show "BV", "NV", "VOF" and "CV" for those legal forms and other legal forms as stored.

#### Scenario: DqOrganisaties

- **GIVEN** Aannemersbedrijf Van Wijk B.V. with legal form "Besloten Vennootschap"
- **THEN** its Rechtsvorm reads "BV"
