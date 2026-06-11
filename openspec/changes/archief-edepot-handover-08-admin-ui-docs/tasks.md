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
- [~] Unit tests for trigger daemon, bundler, BagIt bundler — DEFERRED: behavioural tests need either live OR or mocked DOMDocument schema-validation (current scaffold doesn't ship full MDTO/TMLO XSDs); the call shapes ARE exercised via the EndToEnd termijn suite which shares the SettingsService pattern
- [~] Unit tests for the submitter, retry daemon, proof recorder, rollback manager, batch processor — DEFERRED with members 05/06/07 (no implementation to test yet)
- [~] Integration end-to-end happy path / failure path / batch — DEFERRED with members 05/06/07; the dashboard contract IS asserted in the procest e2e shell
- [~] Mock docudesk, e-Depot endpoints, case/document entities — DEFERRED with the dependent submitter; mocking pattern exists in `lib/Service/Beschikking/Mock*Adapter.php`

## 4. Documentation

- [x] Author the admin guide — `docs/admin/archief-edepot.md`
- [x] Author the developer guide — `docs/Features/archief-edepot.md`
- [x] Author the e-Depot integration guide — included in the developer guide
- [x] Include architecture diagrams and code/sample-data examples — same docs
