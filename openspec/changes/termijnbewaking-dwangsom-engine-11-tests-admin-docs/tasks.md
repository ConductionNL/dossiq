# Tasks: termijnbewaking-dwangsom-engine-11-tests-admin-docs

Member 11 of 11 (code, final). Depends on member 10. Traces to giant Tasks 21, 22, 23, 24, 25.

## 1. End-to-end scenarios

- [x] E2E Scenario 1: normal case — `tests/Unit/Service/TermijnbewakingEndToEndTest::testNormalCaseFlow`
- [x] E2E Scenario 2: pause case — `TermijnbewakingEndToEndTest::testPauseAndResumeFlow`
- [x] E2E Scenario 3: extension case — `TermijnbewakingEndToEndTest::testExtensionFlow`
- [x] E2E Scenario 4: overschrijding + dwangsom — `TermijnbewakingEndToEndTest::testOverschrijdingDwangsomFlow`
- [x] E2E Scenario 5: bezwaar — `TermijnbewakingEndToEndTest::testBezwaarFreezeAndResolve`
- [x] Assert status transitions, event emissions, notifications, and amounts in each — all five scenarios assert state + events via the mocked dispatcher

## 2. Integration sweep

- [~] Integration test full workflow against test OpenRegister with mocked time (tariff transitions) — DEFERRED to live env; mocked-time tier transitions are covered in the EndToEnd unit suite
- [~] Integration test mocked ERP callback updates DwangsomUitbetaling — DEFERRED to live env; the controller's signature/404/200 paths are exercised via mocked OCP\IRequest in `DwangsomPaymentCallbackControllerTest`
- [x] Consolidate per-member unit coverage; ensure CI green — termijn-cluster unit tests are wired into `phpunit.xml` and pass on the dev container

## 3. Admin UI

- [x] Create `TermijnDefinitiesTab.vue` listing definitions (zaaktype, grondslag, duur, validity) — `src/views/settings/tabs/TermijnDefinitiesTab.vue`, wired into AdminRoot
- [x] Create `TermijnDefinitieEditor.vue` form (NcSelect with inputLabel; modals in own files per ADR-004) — `src/modals/TermijnDefinitieEditor.vue`; NcSelects declare `inputLabel`
- [x] Implement versioning on save (new version validFrom=today+1, prior validUntil=today) — `onSave()` PATCHes prior + POSTs new with computed dates
- [~] Verify new cases use latest version; existing retain original — DEFERRED to live verify; backend `TermijnService::getTermijnDefinitie` selects max(validFrom) ≤ now and `TermijnInstance` pins by `termijnDefinitie` ID

## 4. Documentation

- [x] Admin guide (Dutch): configuration, daily-scan setup, troubleshooting, reporting — `docs/Features/termijnbewaking-dwangsom-engine.md`
- [x] User guide (Dutch): AWB deadlines, pause/extension, ingebrekestelling, dwangsom reports — same doc (single combined admin+user reference; matches the leverancier-zaakportaal documentation style)
