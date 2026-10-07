---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# termijnbewaking-schemas Specification

## Purpose
Declares the six OpenRegister schemas for the termijn/dwangsom engine — TermijnDefinitie, TermijnInstance, TermijnGebeurtenis, Ingebrekestelling, DwangsomBerekening, and DwangsomUitbetaling — with their documented properties, enums, and relations so every consumer reads the same canonical shape. It also seeds three demo TermijnDefinities (Omgevingsvergunning-regulier, Wmo-aanvraag, and Woo-verzoek) via the register repair step so the engine has working configuration out of the box.

## Requirements

### Requirement: Termijn and dwangsom register schemas (REQ-TERM-SCHEMA-001)

The system SHALL declare six OpenRegister schemas — `TermijnDefinitie`, `TermijnInstance`, `TermijnGebeurtenis`, `Ingebrekestelling`, `DwangsomBerekening`, and `DwangsomUitbetaling` — with the documented properties, enums, and relations, registered through the dossiq register template so every consumer reads the same canonical shape.

#### Scenario: Schemas materialise with documented properties

- **GIVEN** the dossiq register template is imported into OpenRegister
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

- **GIVEN** the dossiq app is enabled and the register repair step runs
- **WHEN** the seed import completes
- **THEN** the three `TermijnDefinitie` rows SHALL be queryable via the OpenRegister REST API
- **AND** the Woo-verzoek definition SHALL carry `afwijkendDwangsomRegime` describing the €15/day, max €500 regime

#### Scenario: Integration test verifies materialised fields

- **GIVEN** the schemas and seed data are imported
- **WHEN** the integration test queries each schema and the seed rows
- **THEN** the test SHALL assert the documented required properties are present
- **AND** the test SHALL assert the three seed `TermijnDefinitie` rows exist with their documented durations

### Requirement: A term declares whether it counts calendar or working days (REQ-TERM-013)

`deadlineDefinition` SHALL carry `countingMode` with values `calendarDays`
(default) and `workingDays`. The armed timer SHALL use it as its SLA unit
and `endDateCalculated` SHALL be computed in the same mode, so the two
agree at day granularity.

#### Scenario: A working-day term skips the weekend
@e2e exclude covered by the TermijnService fixture pair (D-3); the calendar is seeded, not driven through the UI

- **GIVEN** a definition of 5 `workingDays` and a case started on a Thursday
- **WHEN** the instance is created
- **THEN** `endDateCalculated` SHALL be the next Thursday
- **AND** the armed timer SHALL carry `unit: businessDays`

#### Scenario: A calendar-day term is unchanged
@e2e exclude covered by the same fixture pair

- **GIVEN** a definition without `countingMode` of 56 days
- **WHEN** the instance is created
- **THEN** `endDateCalculated` SHALL be start plus 56 days as before

#### Scenario: The mode is visible in settings
@e2e tests/e2e/termijn-counting-mode.spec.ts

- **GIVEN** the termijn settings tab
- **WHEN** you open a definition
- **THEN** Counting mode SHALL be shown and editable
