# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [organisations-list-cells-as-drawn](../../)

## ADDED Requirements

### Requirement: The organisations list draws its cells as the board

The organisations list SHALL show a long trade name in full over lines, and SHALL read an organisation without open
cases as "Geen", as it reads one without open tickets.

#### Scenario: DqOrganisaties

- **GIVEN** "Aannemersbedrijf Van Wijk B.V." and "Dagblad Drechtsteden B.V." without open cases
- **THEN** the first name wraps over two lines, and the second organisation's Lopende zaken reads "Geen"
