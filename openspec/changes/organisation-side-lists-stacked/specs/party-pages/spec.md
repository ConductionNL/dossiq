# party-pages Delta

## ADDED Requirements

### Requirement: The organisation's side lists are short stacked lists

The OrganisationDetail side cards "Open cases" and "Open tickets" SHALL draw their rows as a stacked list
(`density: "stacked"`), with no rule above the row.

#### Scenario: DqOrganisatie Lopende zaken

- **GIVEN** an organisation with one open case
- **THEN** the case starts 10px under the "Lopende zaken" title, with no hairline above it
