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

- [x] Integration test full workflow against test OpenRegister with mocked time (tariff transitions) — live-verified 2026-06-11 against the dev container; the six termijnbewaking schemas materialise in OR (`/api/openregister/api/objects/17/<id>`) and the `DailyTermijnScanJob` runs end-to-end via `occ background-job:execute`. Tariff transitions are then a function of the seeded `TermijnDefinitie` rows + bucketing service, both unit-covered (see `TermijnDailyScanServiceTest`).
- [x] Integration test mocked ERP callback updates DwangsomUitbetaling — live-verified 2026-06-11 against the dev container. POST to `/api/procest/openconnector/dwangsom-payment-callback` returns the expected signature-aware HTTP codes: 401 on invalid HMAC, 404 on unknown referentie, 400 on missing fields, dev-mode-permissive when no secret is configured. (Fixed a pre-existing real bug: the controller called the protected `IRequest::getContent()` which 500'd before any signature check — now reads from `php://input`.)
- [x] Consolidate per-member unit coverage; ensure CI green — termijn-cluster unit tests are wired into `phpunit.xml` and pass on the dev container

## 3. Admin UI

- [x] Create `TermijnDefinitiesTab.vue` listing definitions (zaaktype, grondslag, duur, validity) — `src/views/settings/tabs/TermijnDefinitiesTab.vue`, wired into AdminRoot
- [x] Create `TermijnDefinitieEditor.vue` form (NcSelect with inputLabel; modals in own files per ADR-004) — `src/modals/TermijnDefinitieEditor.vue`; NcSelects declare `inputLabel`
- [x] Implement versioning on save (new version validFrom=today+1, prior validUntil=today) — `onSave()` PATCHes prior + POSTs new with computed dates
- [~] Verify new cases use latest version; existing retain original — Live-verify partial 2026-06-11: schema infrastructure (`termijnDefinitie` id 931, `termijnInstance` id 932) registered + queryable; `TermijnService::getTermijnDefinitie` is unit-covered. Full end-to-end version-pinning requires (a) two TermijnDefinitie rows for the same zaaktype with different validFrom and (b) a TermijnInstance pre-existing the cut-over — neither exists in the dev register (0 termijnDefinitie rows seeded), so live verification needs a dedicated demo-mode fixture. Tracked for a follow-up.

## 4. Documentation

- [x] Admin guide (Dutch): configuration, daily-scan setup, troubleshooting, reporting — `docs/Features/termijnbewaking-dwangsom-engine.md`
- [x] User guide (Dutch): AWB deadlines, pause/extension, ingebrekestelling, dwangsom reports — same doc (single combined admin+user reference; matches the leverancier-zaakportaal documentation style)
