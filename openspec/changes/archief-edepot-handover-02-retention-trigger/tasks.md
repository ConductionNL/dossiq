# Tasks: archief-edepot-handover-02-retention-trigger

Chain member 2 of 8 (`kind: code`, depends_on member 01). Traces to giant Tasks 3–4 / REQ-ARCH-001.

## 1. ArchivalTriggerDaemon

- [ ] Implement `detectReadyCases()`: query closed cases, look up `BewaarTermijnRegel` by zaaktypeKey, create/update `OverdrachtTrigger`, calculate `overdrachtDatum`
- [ ] Branch: rule found → status `gereed-voor-overdracht`
- [ ] Branch: rule missing → status `geblokkeerd-geen-regel`, set `redenBlokkering`
- [ ] Branch: active bezwaar/beroep → status `opgeschort-juridische-procedure`, defer `overdrachtDatum`
- [ ] Implement `updateTriggerStatus(triggerId, newStatus)` for trigger state transitions
- [ ] Implement `logEvent(triggerId, eventType, details)` appending to `OverdrachtAuditLog`
- [ ] Read/write all objects via OpenRegister ObjectService (no bespoke SQL)

## 2. Scheduling + console command

- [ ] Create console command `archief:detect-ready` for manual testing
- [ ] Schedule the daemon via the Nextcloud background-job system (nightly)

## 3. DIV notification on blocked triggers

- [ ] Implement `notifyBlockedTrigger(triggerId)`
- [ ] Compose the blocked-trigger message: "Zaak [id] kan niet worden overgedragen; configureer eerst BewaarTermijnRegel voor zaaktype '[type]'"
- [ ] Send to the configured DIV group
- [ ] Optionally create a `task` entity "Configureer retentiebesluit voor zaaktype [type]"

## 4. Tests

- [ ] Test: detection creates ready triggers, blocks missing rules, suspends bezwaar cases
- [ ] Test: bezwaar case resumes to `gereed-voor-overdracht` after procedure ends
- [ ] Test: blocked case produces a DIV notification (and optional task)
- [ ] Test: nightly dry-run on representative data completes within the performance budget
