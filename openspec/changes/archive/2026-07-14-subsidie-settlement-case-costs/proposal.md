# Proposal: subsidie-settlement-case-costs

## Why

`iv3-case-cost-reporting` documented this follow-up: subsidie vaststelling (settlement) amounts
represent real municipal expenditure but never reach `case.kosten`, so the quarterly IV3 report
under-states costs for every case that carries a settled subsidie. Today
`VaststellingService::finalize()` patches the vaststelling object and (on overpayment) opens a
clawback case — but the case linked via `subsidieUitvoering → subsidieAanvraag → case` is never
touched. The `case.kosten` enum (`leges_income` / `handling_cost`) also has no type that fits a
disbursed grant.

## What Changes

- **REQ-SSC-001**: When `VaststellingService::finalize()` settles a vaststelling with a positive
  `vastgesteldBedrag`, it appends a kosten entry to the case linked via
  `subsidieUitvoering → subsidieAanvraag → case`, through the SAME
  `ObjectService::saveObject()` write path (`SettingsService::getObjectService()`) every other
  case mutation uses — no parallel path.
- **REQ-SSC-002**: The entry carries a new type `subsidy_disbursement` (English snake_case,
  matching the existing `leges_income`/`handling_cost` discriminator convention), plus
  `source: "subsidie_vaststelling"` and `vaststellingId` markers. `case.kosten` is a free-form
  JSON string field (its "enum" lives in its description + `Iv3ReportService`'s type constants,
  not a JSON-schema `enum` keyword — verified at HEAD), so no validation change is needed; the
  description is updated and the `case` schema version bumps 1.8.0 → 1.9.0 to propagate the
  documented contract.
- **REQ-SSC-003**: Idempotency — re-finalizing the same vaststelling detects the existing entry by
  its `source` + `vaststellingId` markers and does not duplicate it.
- **REQ-SSC-004**: The append is best-effort/fail-soft: no linked case (the
  `subsidieAanvraag.case` `$ref` is `onDelete: SET_NULL` and optional), a missing chain hop, an
  unconfigured schema, or any `Throwable` degrades to a logged warning — settling never fails
  because of the cost enrichment.
- **REQ-SSC-005**: `Iv3ReportService` counts `subsidy_disbursement` entries toward `totalCosts`
  (a disbursed grant is expenditure, like `handling_cost`; never leges income).

## Capabilities

### New Capabilities

- `subsidie-settlement-case-costs`: auto-population of `case.kosten` from subsidie settlement,
  with idempotency and IV3 cost-report integration.

## Impact

- **Backend**: `lib/Service/Subsidie/VaststellingService.php` (constants + `finalize()` hook +
  3 fail-soft private helpers); `lib/Service/Iv3ReportService.php` (new type constant counted in
  `applyEntries()`); `lib/Settings/procest_register.json` (`case.kosten` description + case
  schema version 1.9.0).
- **Frontend**: none — `case.kosten` is `visible: false` (no UI surface reads it).
- **Dependencies**: none added.
