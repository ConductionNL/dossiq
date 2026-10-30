# Tasks: penalty-payment-rows

### Task 1: The annual audit lists the forfeited payments by case
- **spec_ref**: `openspec/changes/penalty-payment-rows/specs/termijn-reporting/spec.md#requirement-the-annual-audit-lists-the-forfeited-payments-by-case`
- **files**: `lib/Service/Termijn/PenaltyPaymentRows.php`, `lib/Controller/DeadlineReportingController.php`, `appinfo/routes.php`
- [x] Implement: the rows, the summary and the route
- [x] Test: `tests/Unit/Service/PenaltyPaymentRowsTest.php`, `tests/Unit/Controller/DeadlineReportingControllerContractTest.php`

### Task 2: The report cards carry their own form
- **spec_ref**: `openspec/changes/penalty-payment-rows/specs/termijn-reporting/spec.md#requirement-the-report-cards-carry-their-own-form`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement: the two cards, the forms, the payments table and the summary
- [x] Test: `tests/vitest/reportDashboardsDeclaration.spec.js`
