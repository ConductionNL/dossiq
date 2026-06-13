---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle]
chain:
  - termijnbewaking-dwangsom-engine-01-schemas-and-seed
  - termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle
  - termijnbewaking-dwangsom-engine-03-pause-extension
  - termijnbewaking-dwangsom-engine-04-daily-scan-escalation
  - termijnbewaking-dwangsom-engine-05-ingebrekestelling
  - termijnbewaking-dwangsom-engine-06-dwangsom-calculation
  - termijnbewaking-dwangsom-engine-07-financial-integration
  - termijnbewaking-dwangsom-engine-08-burger-notifications
  - termijnbewaking-dwangsom-engine-09-reporting-dashboard
  - termijnbewaking-dwangsom-engine-10-bezwaar-rest-api
  - termijnbewaking-dwangsom-engine-11-tests-admin-docs
---

# Proposal: termijnbewaking-dwangsom-engine-03-pause-extension

Member 3 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle`. This `kind: code` member adds the legal-ground-compliant pause (AWB 4:5/4:15 hersteltermijn) and single-extension (AWB 4:14) logic on top of the bound `TermijnInstance`.

## Why

AWB 4:15 lets the deadline clock pause while a hersteltermijn (request-for-completion) is outstanding, and AWB 4:14 allows exactly one motivated extension. Getting these wrong is the most common cause of unjust dwangsom payouts: a deadline that should have been paused keeps running, or a second extension slips through. This member encodes both rules with the unconsumed-pause-days accounting and second-extension blocking that the law requires.

## What Changes (this member)

1. `PauseService.registerPauze()` extends `einddatumActueel`, sets `status = gepauzeerd`, records a `pauze` event.
2. `resumeAfterPauze()` consumes only the elapsed pause days proportionally and recalculates `einddatumActueel`.
3. `ExtensionService.requestExtension()` validates and applies the first extension; blocks the second per AWB 4:14 lid 3 (override only via supervisor-approved exceptional grond).

## Impact

- **Affected**: procest (`PauseService`, `ExtensionService`).
- **Traces to giant tasks**: Task 3 (pause logic), Task 4 (single extension), REQ-TERM-002, REQ-TERM-003.
- **Depends on**: member 02 (`TermijnService` + bound `TermijnInstance`).
