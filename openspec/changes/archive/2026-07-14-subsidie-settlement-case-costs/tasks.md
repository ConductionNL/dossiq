# Tasks: subsidie-settlement-case-costs

## 1. Schema/data contract (REQ-SSC-002)

- [x] 1.1 Verify at HEAD that `case.kosten` has no JSON-schema `enum` (contract lives in the
      description + `Iv3ReportService` constants) — documented in design.md §2.
- [x] 1.2 Update `case.kosten` description with the `subsidy_disbursement` type + `source`/
      `vaststellingId` markers; bump case schema version 1.8.0 → 1.9.0.

## 2. VaststellingService (REQ-SSC-001, REQ-SSC-003, REQ-SSC-004)

- [x] 2.1 `KOSTEN_TYPE_SUBSIDY_DISBURSEMENT` + `KOSTEN_SOURCE` public constants.
- [x] 2.2 `finalize()` calls `appendKostenToLinkedCase()` after the vaststelling patch +
      clawback trigger.
- [x] 2.3 `appendKostenToLinkedCase()` — fail-soft append through
      `ObjectService::saveObject()`; `resolveKostenContext()` + `resolveLinkedCaseId()` +
      `hasExistingEntry()` + `decodeKosten()` helpers.

## 3. Iv3ReportService (REQ-SSC-005)

- [x] 3.1 `TYPE_SUBSIDY_DISBURSEMENT` constant, counted toward `totalCosts` in `applyEntries()`.

## 4. Tests

- [x] 4.1 `VaststellingServiceTest` — `VaststellingFakeObjectService` fake + 5 new tests: happy
      path, no-linked-case, idempotency on re-finalize, no-execution-id, zero-amount skip.
- [x] 4.2 `Iv3ReportServiceTest` — `subsidy_disbursement` counts toward `totalCosts`, never leges.
- [x] 4.3 Full PHPUnit suite green (CI-equivalent `php:8.3-cli` container, `phpunit-unit.xml`).
- [x] 4.4 PHPCS/PHPMD/Psalm/PHPStan clean on touched `lib/` files (also fixed 5 pre-existing
      missing-@spec warnings on VaststellingService's public methods).
- [x] 4.5 vitest green, `npm run build` exit 0, l10n en/nl parity green (no frontend change).

## 5. Follow-ups filed, not built here

- [ ] 5.1 `finalize()` has no status guard against re-finalizing an already-`vastgesteld`
      vaststelling (pre-existing; the kosten append is idempotent against it, but the clawback
      trigger is not — a re-finalize with overpayment creates a second concept clawback case).
