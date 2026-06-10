# Tasks: termijnbewaking-dwangsom-engine-11-tests-admin-docs

> **Build status (hydra audit).** Greenfield. No TermijnDefinitie/TermijnInstance/TermijnGebeurtenis/Ingebrekestelling/Dwangsom schemas, no termijn-binding lifecycle, no daily-scan escalation daemon, no dwangsom calculation/financial integration, no burger notifications, no reporting/REST-API surfaces on dev. The 11-member chain delivers the AWB termijnbewaking + dwangsom engine from scratch. Tasks stay [ ] as genuine forward work.

Member 11 of 11 (code, final). Depends on member 10. Traces to giant Tasks 21, 22, 23, 24, 25.

## 1. End-to-end scenarios

- [ ] E2E Scenario 1: normal case (create → no pause/extension → beschikking before deadline)
- [ ] E2E Scenario 2: pause case (incomplete aanvraag → hersteltermijn → aanvulling → resume)
- [ ] E2E Scenario 3: extension case (first extension → beschikking after extension)
- [ ] E2E Scenario 4: overschrijding + dwangsom (overschreden → ingebrekestelling → accrual → beschikking → payment signal)
- [ ] E2E Scenario 5: bezwaar (dwangsom → bezwaar → resolution with amount change)
- [ ] Assert status transitions, event emissions, notifications, and amounts in each

## 2. Integration sweep

- [ ] Integration test full workflow against test OpenRegister with mocked time (tariff transitions)
- [ ] Integration test mocked ERP callback updates DwangsomUitbetaling
- [ ] Consolidate per-member unit coverage; ensure CI green

## 3. Admin UI

- [ ] Create `TermijnDefinitiesTab.vue` listing definitions (zaaktype, grondslag, duur, validity)
- [ ] Create `TermijnDefinitieEditor.vue` form (NcSelect with inputLabel; modals in own files per ADR-004)
- [ ] Implement versioning on save (new version validFrom=today+1, prior validUntil=today)
- [ ] Verify new cases use latest version; existing retain original

## 4. Documentation

- [ ] Admin guide (Dutch): configuration, daily-scan setup, troubleshooting, reporting
- [ ] User guide (Dutch): AWB deadlines, pause/extension, ingebrekestelling, dwangsom reports
