# subsidie-settlement-case-costs Specification

## Purpose

Auto-populate `case.kosten` with subsidie vaststelling (settlement) amounts so the quarterly IV3
cost report reflects disbursed grants — closing the follow-up `iv3-case-cost-reporting`
documented. The append flows through the same ObjectService write path as every other case
mutation, is idempotent per vaststelling, and never fails the settlement itself.

## ADDED Requirements

### Requirement: VaststellingService::finalize() SHALL append a subsidy_disbursement kosten entry to the linked case

When a vaststelling is finalized with a positive `vastgesteldBedrag`, `finalize()` SHALL resolve
the linked case via `subsidieUitvoering → subsidieAanvraag → case` and SHALL append one entry
`{bedrag, type: "subsidy_disbursement", datum, source: "subsidie_vaststelling", vaststellingId}`
to that case's `kosten` field, persisted through `ObjectService::saveObject()` (the same write
path as the vaststelling patch itself — no parallel persistence path).

#### Scenario: Happy path appends one marked entry

- **GIVEN** a vaststelling linked (via its subsidieUitvoering and subsidieAanvraag) to case
  `case-1`
- **WHEN** `finalize()` settles it at `vastgesteldBedrag = 330000.0`
- **THEN** `case-1`'s `kosten` SHALL contain exactly one new entry
- **AND** that entry's `bedrag` SHALL equal `330000.0`
- **AND** its `type` SHALL be `subsidy_disbursement`
- **AND** its `source` SHALL be `subsidie_vaststelling`
- **AND** its `vaststellingId` SHALL be the finalized vaststelling's id
- **AND** it SHALL carry an ISO `datum`

#### Scenario: Zero settled amount appends nothing

- **GIVEN** a vaststelling whose `vastgesteldBedrag` computes to `0.0`
- **WHEN** `finalize()` runs
- **THEN** the linked case's `kosten` SHALL be unchanged

---

### Requirement: The kosten append SHALL be idempotent per vaststelling and fail-soft on every unresolvable link

Re-finalizing the same vaststelling SHALL NOT duplicate its kosten entry (detected via the
`source` + `vaststellingId` markers on the existing entry). A missing chain hop — empty
`subsidieuitvoering`, unresolvable subsidieUitvoering/subsidieAanvraag object, empty or
unresolvable `subsidieAanvraag.case` — an unconfigured schema, or any `Throwable` during the
append SHALL degrade to a logged warning; `finalize()` SHALL still complete the settlement.

#### Scenario: Re-finalize does not duplicate

- **GIVEN** a vaststelling already finalized once (its kosten entry present on the linked case)
- **WHEN** `finalize()` runs again for the same vaststelling
- **THEN** the linked case's `kosten` SHALL still contain exactly one entry for that
  `vaststellingId`

#### Scenario: No linked case does not fail the settlement

- **GIVEN** a vaststelling whose subsidieAanvraag has no `case` link
- **WHEN** `finalize()` runs
- **THEN** the vaststelling SHALL be patched to status `vastgesteld`
- **AND** no case SHALL be written

#### Scenario: No execution id skips the append

- **GIVEN** a vaststelling with an empty `subsidieuitvoering`
- **WHEN** `finalize()` runs
- **THEN** the settlement SHALL complete normally without any kosten append

---

### Requirement: Iv3ReportService SHALL count subsidy_disbursement entries toward totalCosts

`Iv3ReportService::applyEntries()` SHALL add a `subsidy_disbursement` entry's `bedrag` to the
bucket's `totalCosts` (like `handling_cost`) and SHALL NOT count it toward `totalLegesIncome`.

#### Scenario: Disbursement counts as cost, not leges income

- **GIVEN** a case with one `handling_cost` entry of `100` and one `subsidy_disbursement` entry
  of `330000` in the same quarter
- **WHEN** the quarterly report is generated
- **THEN** the case's taakveld bucket SHALL report `totalCosts = 330100.0`
- **AND** `totalLegesIncome = 0.0`
