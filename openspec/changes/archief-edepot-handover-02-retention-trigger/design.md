# Design: archief-edepot-handover-02-retention-trigger

## Scope

Retention-trigger detection only. Consumes the schemas from member 01; produces `OverdrachtTrigger` records. Bundling, document export, submission, proof, rollback, batch, inspection are out of scope (members 03–08).

## Declarative-first (ADR-031)

The retention rules themselves are declarative (`BewaarTermijnRegel`, member 01). This member adds only the imperative detection logic that ADR-031 leaves to a service: date arithmetic across the case corpus and trigger-state transitions are genuine procedural work with no declarative analogue, so a service class is correct here.

## Data access (ADR-001)

All reads of `case` and writes of `OverdrachtTrigger` / `OverdrachtAuditLog` go through the OpenRegister ObjectService (find / findAll / saveObject), never bespoke SQL. Lookups of `BewaarTermijnRegel` by `zaaktypeKey` use findAll with a filter.

## Service layout

### ArchivalTriggerDaemon
- `detectReadyCases()` — query cases with `endDate ≤ today - min(retention)`; per case, look up `BewaarTermijnRegel`; create/update `OverdrachtTrigger`; compute `overdrachtDatum`.
- `updateTriggerStatus(triggerId, status)` — transition state.
- `logEvent(triggerId, eventType, details)` — append to `OverdrachtAuditLog`.

Branches: rule found → `gereed-voor-overdracht`; rule missing → `geblokkeerd-geen-regel` + `redenBlokkering`; active bezwaar/beroep → `opgeschort-juridische-procedure` (overdrachtDatum deferred, re-checked next run).

### NotificationService (blocked-trigger path)
- `notifyBlockedTrigger(triggerId)` — compose + send DIV email; optionally create a `task` entity for rule configuration.

### Console command + background job
- `DetectArchivalReadyCommand` for manual runs.
- Nightly background job (Nextcloud background-job system) schedules `detectReadyCases()`.

## Security (ADR-005)

The daemon runs as a system actor (background job) — no user-facing endpoint is added by this member. The optional manual console command is admin-invokable only. Trigger records carry the case reference; no IDOR surface is introduced because no per-user HTTP endpoint reads/writes triggers in this member.

## Traceability

Giant Task 3 (daemon) + Task 4 (blocked notification); REQ-ARCH-001-A/B/C.
