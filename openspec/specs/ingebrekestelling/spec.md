---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# ingebrekestelling Specification

## Purpose
TBD - created by archiving change termijnbewaking-dwangsom-engine-05-ingebrekestelling. Update Purpose after archive.
## Requirements
### Requirement: Ingebrekestelling-registratie en validatie (REQ-TERM-005)

The system SHALL register a formal ingebrekestelling (AWB 4:17) only when the termijn is genuinely overschreden, and SHALL create the grace-period `DwangsomBerekening` on a valid registration.

#### Scenario: Valid ingebrekestelling creates DwangsomBerekening

- **GIVEN** a `TermijnInstance` has been `overschreden` for several days
- **WHEN** the handler registers an ingebrekestelling with an `ontvangstDatum` after `einddatumActueel`
- **THEN** the system SHALL validate `status = overschreden` AND `einddatumActueel < ontvangstDatum`
- **AND** validation SHALL pass: `gevalideerd = true`, `geldigheidStatus = geldig`
- **AND** a `DwangsomBerekening` SHALL be auto-created with `startDatum = ontvangstDatum + 14 days` and `status = lopend`

#### Scenario: Premature ingebrekestelling is rejected

- **GIVEN** the termijn has not yet expired (`einddatumActueel` is in the future)
- **WHEN** the handler registers an ingebrekestelling with an earlier `ontvangstDatum`
- **THEN** validation SHALL fail: `gevalideerd = false`, `geldigheidStatus = premaat`
- **AND** no `DwangsomBerekening` SHALL be created
- **AND** the system SHALL advise the handler to instruct the burger to re-register after overschrijding

### Requirement: One dwangsom per termijn (REQ-TERM-005-B)

The system SHALL treat only the first valid ingebrekestelling as the dwangsom basis; subsequent notices SHALL be recorded but SHALL NOT spawn a second `DwangsomBerekening`.

#### Scenario: Second ingebrekestelling does not spawn a second berekening

- **GIVEN** a first valid ingebrekestelling has set `relevantIngbrekes` and created a `DwangsomBerekening`
- **WHEN** a second ingebrekestelling is registered for the same termijn
- **THEN** the second notice SHALL be recorded for the audit trail
- **AND** no second `DwangsomBerekening` SHALL be created
- **AND** the handler SHALL receive an info message naming the first notice's date as the dwangsom basis

