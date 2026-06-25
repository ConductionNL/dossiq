---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# dwangsom-calculation Specification

## Purpose
Calculates the statutory dwangsom (AWB 4:17) due when a decision is not taken in time, applying the daily staffel (€23/€35/€45 per tier) after a 14-day grace period and capping the cumulative amount at the €1.442 plafond without retroactive recalculation. When a beschikking is registered, accrual stops, the final amount is locked, the termijn is marked voltooid, and a payment preparation is triggered.
## Requirements
### Requirement: Dwangsom-staffel berekening volgens AWB 4:17 (REQ-TERM-006)

The system SHALL apply the statutory daily tariff — €23/day (days 1–14), €35/day (days 15–28), €45/day (days 29+) — with a 14-day grace period and a €1.442 plafond per case, without retroactive recalculation.

#### Scenario: Grace period accrues nothing and first tier is €23/day

- **GIVEN** an ingebrekestelling with a 14-day grace period ending on a known date
- **WHEN** the daily calculation runs 10 days after grace ends
- **THEN** the grace period SHALL accrue no dwangsom
- **AND** days 1–10 SHALL accrue €23/day for `cumulatievBedrag` = €230 with `dagtarief` = €23

#### Scenario: Tier transitions are not retroactive

- **GIVEN** accrual has reached day 14 with `cumulatievBedrag` = €322
- **WHEN** the calculation runs on day 15
- **THEN** `dagtarief` SHALL switch to €35
- **AND** `cumulatievBedrag` SHALL remain €322 with no retroactive recalculation of the first 14 days
- **AND** on day 29 `dagtarief` SHALL switch to €45

#### Scenario: Plafond is enforced

- **GIVEN** accrual is approaching the €1.442 plafond
- **WHEN** a daily calculation would exceed €1.442
- **THEN** `cumulatievBedrag` SHALL be capped at €1.442 and `plafondBereikt` SHALL be set true
- **AND** no further accrual SHALL occur on subsequent days

#### Scenario: Beschikking stops accrual and locks the amount

- **GIVEN** a running `DwangsomBerekening`
- **WHEN** a beschikking is registered
- **THEN** `DwangsomBerekening.status` SHALL be set to `gestopt-wegens-beschikking`
- **AND** `definitievBedrag` SHALL be locked at the current `cumulatievBedrag` with no further accrual
- **AND** the `TermijnInstance.status` SHALL be set to `voltooid` with a `voltooi` event, and a payment preparation SHALL be triggered

