---
kind: code
depends_on:
  - archief-edepot-handover-01-schema-config
chain:
  - archief-edepot-handover-01-schema-config
  - archief-edepot-handover-02-retention-trigger
  - archief-edepot-handover-03-metadata-bundling
  - archief-edepot-handover-04-document-export
  - archief-edepot-handover-05-sip-submission
  - archief-edepot-handover-06-proof-rollback
  - archief-edepot-handover-07-batch-inspection
  - archief-edepot-handover-08-admin-ui-docs
---

# Proposal: archief-edepot-handover-02-retention-trigger

## Summary

This is **spec 2 of 8** in the `archief-edepot-handover` chain. It implements the retention-trigger detection daemon that consumes the `BewaarTermijnRegel` and `OverdrachtTrigger` schemas declared in member 01. The daemon runs nightly, computes `afsluitingsDatum + bewaartermijnJaren` per closed case, creates/updates `OverdrachtTrigger` records, blocks cases with no matching rule, suspends cases with active bezwaar/beroep, and notifies DIV when a case is blocked. `kind: code`; `depends_on` member 01.

## Why

Detection is the entry point of the archival pipeline. Until a case is marked ready-for-transfer, no bundling, submission, or proof capture can begin. This member turns the declared schemas into a working detector without touching bundling or submission (those are members 03+).

## What Changes

1. **ArchivalTriggerDaemon** — `detectReadyCases()` queries closed cases, looks up the retention rule, and creates/updates `OverdrachtTrigger`.
2. **Status transitions** — `updateTriggerStatus()` moves a trigger through gepland → gereed-voor-overdracht → in-overdracht → geslaagd/gefaald.
3. **Audit logging** — `logEvent()` appends to `OverdrachtAuditLog` on each detection milestone.
4. **Blocked-rule handling** — missing `BewaarTermijnRegel` → trigger status `geblokkeerd-geen-regel` + `redenBlokkering`.
5. **Bezwaar/beroep suspension** — active legal procedure → `opgeschort-juridische-procedure`, re-checked nightly.
6. **DIV notification** — blocked trigger raises a notification (and optional task) with an actionable message.
7. **Console command** — manual `detect-ready` trigger for testing.

## Impact

- **Affected**: procest (daemon, notification, console command, background job).
- **Consumes**: `BewaarTermijnRegel`, `OverdrachtTrigger`, `OverdrachtAuditLog` (member 01).
- **Downstream**: member 03 bundles cases this member marks ready-for-transfer.

## Traceability

Covers giant tasks **3** (ArchivalTriggerDaemon) and **4** (DIV notification on blocked triggers); requirement REQ-ARCH-001 (A/B/C). No new scope.
