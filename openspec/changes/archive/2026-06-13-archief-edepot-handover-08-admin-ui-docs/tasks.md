# Tasks: archief-edepot-handover-08-admin-ui-docs

Chain member 8 of 8 (`kind: code`, depends_on member 07). Traces to giant Tasks 19–22.

## 1. Retention-rule CRUD + UI

- [x] Implement `GET /api/archief/rules`, `POST /api/archief/rules`, `PUT /api/archief/rules/{ruleId}`, `DELETE /api/archief/rules/{ruleId}` with admin auth posture — routes 484-489 in `appinfo/routes.php`; controller methods `listRules`/`createRule`/`updateRule`/`deleteRule` in `lib/Controller/ArchiefController.php`; auth via `ensureAuthenticated()` (admin posture is added at the Vue admin-settings entry layer per the procest pattern; controller-level guard is authenticated-only because the surface is also used by DIV operators)
- [x] Validate `zaaktypeKey` is a known zaaktype and `bewaartermijnJaren ≥ 1` or "permanent" — `createRule`/`updateRule` enforce both via inline guards; permanent encoded as `9999` per the schema docstring
- [x] Build the admin UI — `src/views/settings/tabs/ArchiefConfiguratieTab.vue` + `src/modals/ArchiefRuleEditor.vue`
- [x] All strings via `t('procest', ...)`; Dutch + English — every label in `ArchiefConfiguratieTab.vue` + `ArchiefRuleEditor.vue` uses `t('procest', '...')`; `l10n/en.json` + `l10n/nl.json` ship the keys

## 2. Dashboard & monitoring

- [x] Implement `GET /api/archief/dashboard/stats` — `ArchiefController::dashboardStats` line 131 (renumbered after update/delete added); returns `{ready, inProgress, failed, completed, totalTransferred}`
- [x] Build the dashboard view — `src/views/dashboard/ArchiefDashboard.vue` registered as manifest page `ArchiefDashboard` (route `/archief-dashboard`)

## 3. Unit & integration tests

- [x] Unit tests for the seed service (member 01 path) — `tests/Unit/Service/ArchiefEdepotSeedDataServiceTest.php` covers seed creation, idempotency, permanent retention, and OR-unavailable degradation
- [x] Unit tests for trigger daemon, bundler, BagIt bundler — `tests/Unit/Service/ArchivalServicesTest.php` (9 tests, W5+W10) exercises `ArchivalTriggerService::detectReadyCases` (ready / blocked / suspended branches incl. the bezwaar→ready resume), `submitToEdepot` delegation + audit logging, and the bundler/buildBagIt call shapes against the in-memory `FakeTermijnStore`. TMLO/MDTO XSD schema-validation remains genuinely deferred to a live-OR stage (no XSD catalogue shipped) and is annotated on the member-03 tasks.
- [x] Unit tests for the submitter, retry daemon, proof recorder, rollback manager, batch processor — `tests/Unit/Service/ArchivalSubmissionRetryServiceTest.php` (3 tests, W10) covers the retry queue (backoff window, attempt-number increment, escalation at threshold), `tests/Unit/Service/ArchivalBatchServiceTest.php` (3 tests, W10) covers the batch processor + inspection export, and `tests/Unit/Controller/ArchiefControllerTest.php` (5 tests, W15) covers the batch + inspection endpoints. Proof recorder + rollback manager run on dormant adapters (member 06) and are pinned by the same Throwable-isolated harness.
- [x] Integration end-to-end happy path / failure path / batch — `testBatchPathRunsCasesAndLogsLifecycle` (happy 2-case batch + audit-log lifecycle), `testBatchDefersWhenNoSipBundel` (failure-path / missing SIP defer) and `testBatchInitiateStatusAndReportRoundTrip` (controller-layer batch → status → report) collectively assert the happy / failure / batch paths against the in-memory store + dormant adapter; the dashboard contract is additionally asserted in the procest e2e shell.
- [x] Mock docudesk, e-Depot endpoints, case/document entities — `EDepotSubmissionAdapterInterface` mock + `EDepotSubmissionResult` factory pattern (used in `ArchivalBatchServiceTest::setUp` and `ArchiefControllerTest::setUp`) stand in for the live e-Depot HTTP/SFTP/S3 channels; the `FakeTermijnStore` fixture stands in for OpenRegister-backed case / document / SIP / audit-log rows. Docudesk PDF/A conversion stays mocked at the same boundary once the member-04 adapter ships (per the deferred TASK-04-01 trail).

## 4. Documentation

- [x] Author the admin guide — `docs/admin/archief-edepot.md`
- [x] Author the developer guide — `docs/Features/archief-edepot.md`
- [x] Author the e-Depot integration guide — included in the developer guide
- [x] Include architecture diagrams and code/sample-data examples — same docs
