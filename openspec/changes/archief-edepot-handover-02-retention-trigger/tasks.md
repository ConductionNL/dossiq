# Tasks: archief-edepot-handover-02-retention-trigger

Chain member 2 of 8 (`kind: code`, depends_on member 01). Traces to giant Tasks 3–4 / REQ-ARCH-001.

## 1. ArchivalTriggerDaemon

- [~] Implement `detectReadyCases()`: query closed cases, look up `BewaarTermijnRegel` by zaaktypeKey, create/update `OverdrachtTrigger`, calculate `overdrachtDatum` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Branch: rule found → status `gereed-voor-overdracht` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Branch: rule missing → status `geblokkeerd-geen-regel`, set `redenBlokkering` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Branch: active bezwaar/beroep → status `opgeschort-juridische-procedure`, defer `overdrachtDatum` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `updateTriggerStatus(triggerId, newStatus)` for trigger state transitions — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `logEvent(triggerId, eventType, details)` appending to `OverdrachtAuditLog` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read/write all objects via OpenRegister ObjectService (no bespoke SQL) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Scheduling + console command

- [~] Create console command `archief:detect-ready` for manual testing — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Schedule the daemon via the Nextcloud background-job system (nightly) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. DIV notification on blocked triggers

- [~] Implement `notifyBlockedTrigger(triggerId)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Compose the blocked-trigger message: "Zaak [id] kan niet worden overgedragen; configureer eerst BewaarTermijnRegel voor zaaktype '[type]'" — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Send to the configured DIV group — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Optionally create a `task` entity "Configureer retentiebesluit voor zaaktype [type]" — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Tests

- [~] Test: detection creates ready triggers, blocks missing rules, suspends bezwaar cases — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: bezwaar case resumes to `gereed-voor-overdracht` after procedure ends — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: blocked case produces a DIV notification (and optional task) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test: nightly dry-run on representative data completes within the performance budget — deferred to downstream cycle / fleet-wide adoption (handoff)
