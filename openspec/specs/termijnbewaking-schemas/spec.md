---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijnbewaking-schemas Specification

## Purpose
TBD - created by archiving change termijnbewaking-dwangsom-engine-01-schemas-and-seed. Update Purpose after archive.
## Requirements
### Requirement: Termijn and dwangsom register schemas (REQ-TERM-SCHEMA-001)

The system SHALL declare six OpenRegister schemas — `TermijnDefinitie`, `TermijnInstance`, `TermijnGebeurtenis`, `Ingebrekestelling`, `DwangsomBerekening`, and `DwangsomUitbetaling` — with the documented properties, enums, and relations, registered through the procest register template so every consumer reads the same canonical shape.

#### Scenario: Schemas materialise with documented properties

- **GIVEN** the procest register template is imported into OpenRegister
- **WHEN** the six termijn/dwangsom schemas are materialised
- **THEN** each schema SHALL expose its documented required properties (e.g. `TermijnInstance` SHALL expose `zaak`, `termijnDefinitie`, `startDatum`, `einddatumBerekend`, `einddatumActueel`, and a `status` enum of {lopend, gepauzeerd, verlengd, voltooid, overschreden, ingetrokken})
- **AND** `TermijnGebeurtenis` SHALL be modelled as an append-only audit schema with a `type` enum of {start, pauze, hervat, verleng, voltooi, overschreden, ingebrekestelling-ontvangen, dwangsom-gestart}

#### Scenario: Relations between schemas are declared

- **GIVEN** the schemas are registered
- **WHEN** the relations are inspected
- **THEN** `TermijnInstance` SHALL relate to one `TermijnDefinitie`, to many `TermijnGebeurtenis`, and to many `Ingebrekestelling`
- **AND** `Ingebrekestelling` SHALL relate one-to-one to `DwangsomBerekening`, and `DwangsomBerekening` one-to-one to `DwangsomUitbetaling`

### Requirement: Seed TermijnDefinities for demo zaaktypen (REQ-TERM-SCHEMA-002)

The system SHALL seed three `TermijnDefinitie` rows — Omgevingsvergunning-regulier (56 days), Wmo-aanvraag (42 days), and Woo-verzoek (28 days, custom €15/day regime) — via the OpenRegister repair-step import so the engine has working configuration out of the box.

#### Scenario: Seed definitions load via repair step

- **GIVEN** the procest app is enabled and the register repair step runs
- **WHEN** the seed import completes
- **THEN** the three `TermijnDefinitie` rows SHALL be queryable via the OpenRegister REST API
- **AND** the Woo-verzoek definition SHALL carry `afwijkendDwangsomRegime` describing the €15/day, max €500 regime

#### Scenario: Integration test verifies materialised fields

- **GIVEN** the schemas and seed data are imported
- **WHEN** the integration test queries each schema and the seed rows
- **THEN** the test SHALL assert the documented required properties are present
- **AND** the test SHALL assert the three seed `TermijnDefinitie` rows exist with their documented durations

