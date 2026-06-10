# Tasks: termijnbewaking-dwangsom-engine-11-tests-admin-docs

Member 11 of 11 (code, final). Depends on member 10. Traces to giant Tasks 21, 22, 23, 24, 25.

## 1. End-to-end scenarios

- [~] E2E Scenario 1: normal case (create → no pause/extension → beschikking before deadline) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] E2E Scenario 2: pause case (incomplete aanvraag → hersteltermijn → aanvulling → resume) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] E2E Scenario 3: extension case (first extension → beschikking after extension) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] E2E Scenario 4: overschrijding + dwangsom (overschreden → ingebrekestelling → accrual → beschikking → payment signal) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] E2E Scenario 5: bezwaar (dwangsom → bezwaar → resolution with amount change) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Assert status transitions, event emissions, notifications, and amounts in each — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Integration sweep

- [~] Integration test full workflow against test OpenRegister with mocked time (tariff transitions) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration test mocked ERP callback updates DwangsomUitbetaling — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Consolidate per-member unit coverage; ensure CI green — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Admin UI

- [~] Create `TermijnDefinitiesTab.vue` listing definitions (zaaktype, grondslag, duur, validity) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `TermijnDefinitieEditor.vue` form (NcSelect with inputLabel; modals in own files per ADR-004) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement versioning on save (new version validFrom=today+1, prior validUntil=today) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify new cases use latest version; existing retain original — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Documentation

- [~] Admin guide (Dutch): configuration, daily-scan setup, troubleshooting, reporting — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] User guide (Dutch): AWB deadlines, pause/extension, ingebrekestelling, dwangsom reports — deferred to downstream cycle / fleet-wide adoption (handoff)
