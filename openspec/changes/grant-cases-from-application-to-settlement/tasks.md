# Tasks: grant-cases-from-application-to-settlement

## 1. Case page

- [ ] 1.1 Wait for board DqSubsidie (gap list b), then place on CaseDetail (`src/manifest.json`) the widgets it draws: decision and advance schedule, interim reports, evidence, settlement, recovery; each `visibleWhen` on the subsidie case type's config (`@context.caseType.grantChain`), not on its name.
- [ ] 1.2 Header actions `interim-report-record` (`POST /api/subsidies/uitvoeringen/{id}/tussenrapportages`), `interim-report-assess` (`/beoordelen`), `grant-settle` (`/vaststellingen/{id}/vast`), `grant-recover`; dialogs under `src/dialogs/` named by what they do.
- [ ] 1.3 `lib/Settings/packages/subsidie/case-type.json`: set `grantChain: true`; bump the version.

## 2. Payment schedule (generic)

- [x] 2.1 `paymentSchedule` on the decision schema (`beschikking` 1.2.0 in `lib/Settings/register.d/30-beschikking.json`, register 0.20.87): `payeeIban` and `instalments` (label, dueOn, amountCents, condition, conditionMet, and the payableId and raisedAt the job writes back). The payee name is the decision's addressee.
- [x] 2.2 `lib/BackgroundJob/RaiseDuePayments.php` (daily, as the background service account) over `lib/Service/Money/PaymentScheduleRaiser.php`: raises each due instalment once through the shillinq payable seam (`PayoutRequester`, decision 192); an instalment with an unmet condition waits; `tests/Unit/BackgroundJob/RaiseDuePaymentsTest.php` (once per instalment, idempotent, condition, refusal).

## 3. Checks

- [ ] 3.1 `tests/e2e/grant-case.spec.ts`: both scenarios of REQ-SUB-CASE-01.
- [ ] 3.2 Newman: `tests/integration/packages/subsidie.postman_collection.json` gains interim report, settlement and recovery calls.
- [ ] 3.3 Live pass against DqSubsidie.
- [ ] 3.4 `openspec/parity/capabilities.json` row 15.2: built when 1 and 2 are live.
