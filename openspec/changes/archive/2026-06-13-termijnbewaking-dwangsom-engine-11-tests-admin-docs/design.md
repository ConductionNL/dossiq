# Design: termijnbewaking-dwangsom-engine-11-tests-admin-docs

## Scope of this member

The cross-cutting verification + operability cap: e2e scenario tests, a consolidated integration sweep, the admin TermijnDefinitie UI, and the documentation. Per-member unit/integration tests already shipped in members 02–10; this member adds only what spans the whole chain.

## Approach

### End-to-end scenarios (tests/Feature/TermijnWorkflowTest)
1. Normal case (create → no pause/extension → beschikking before deadline).
2. Pause case (incomplete aanvraag → hersteltermijn → aanvulling → resume).
3. Extension case (first extension → beschikking after extension).
4. Overschrijding + dwangsom (overschreden → ingebrekestelling → daily accrual → beschikking → payment signal).
5. Bezwaar case (dwangsom → bezwaar → resolution with amount change).
Each asserts the correct status transitions, event emissions, notifications, and amounts.

### Integration sweep (tests/Integration)
End-to-end against a test OpenRegister instance with mocked time (simulate days passing → tariff transitions) and a mocked ERP callback.

### Admin UI (ADR-004 frontend)
- `TermijnDefinitiesTab.vue` lists all TermijnDefinities (zaaktype, grondslag, duur, validity).
- `TermijnDefinitieEditor.vue` form (zaaktype dropdown, grondslag, durationDagen, verlengingsRuimte, afwijkendDwangsomRegime). Versioning: on save create a new version (`validFrom = today + 1`), mark the prior `validUntil = today`. NcSelect uses `inputLabel`; any modal lives in its own file (ADR-004 hard rules).

### Documentation (ADR-009)
- Admin guide: configure TermijnDefinities, daily-scan cronjob setup, troubleshooting missed alerts, reporting.
- User guide: AWB deadlines, pause grounds, extension request, ingebrekestelling registration, where to find dwangsom reports. Both in Dutch with examples.

## Security (ADR-005)

The admin UI is rendered by the settings framework (admin-only) and does not register its admin components in the in-app vue-router (ADR-004). Configuration writes go through the member-10 admin-gated endpoints.

## Tests

This member *is* the test cap; its own acceptance is the five e2e scenarios passing green in CI.
