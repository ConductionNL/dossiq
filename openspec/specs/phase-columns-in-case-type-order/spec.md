# phase-columns-in-case-type-order Specification

## Purpose
What dossiq does for phase columns in case type order. The requirements were first written by the change `phase-columns-in-case-type-order` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-phase-columns-in-case-type-order/`) and cover: The phase columns follow the case types' order.

## Requirements

### Requirement: The phase columns follow the case types' order

The days-per-phase matrix SHALL order its columns by the status `order` of the case types: a column SHALL come before
another when more case types that have both pass it first. Columns no case type orders against each other SHALL keep
their first-seen order.

#### Scenario: DqProcesanalyse

- **GIVEN** Woo-verzoek passing Ontvangst, Beoordeling, Besluit, Verzending and Omgevingsvergunning passing Ontvangst,
  Beoordeling, Advies, Besluit
- **THEN** the columns read Ontvangst, Beoordeling, Advies, Besluit, Verzending
