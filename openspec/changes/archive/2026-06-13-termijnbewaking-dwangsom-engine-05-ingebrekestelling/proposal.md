---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-04-daily-scan-escalation]
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

# Proposal: termijnbewaking-dwangsom-engine-05-ingebrekestelling

Member 5 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-04-daily-scan-escalation`. This `kind: code` member implements ingebrekestelling registration with overschrijding validation and the one-dwangsom-per-termijn guarantee, creating the `DwangsomBerekening` placeholder that member 06 will accrue against.

## Why

AWB 4:17 requires a valid formal notice (ingebrekestelling) before any dwangsom can accrue, and the notice is only valid once the termijn is genuinely overschreden. Registering a premature notice or spawning two parallel dwangsommen from two notices are both legally wrong and financially damaging. This member encodes the validation and the "only the first notice matters" rule.

## What Changes (this member)

1. `IngebrekestellingService.registerIngebrekestelling()` validates `status = overschreden` AND `einddatumActueel < ontvangstDatum`; marks `gevalideerd`/`geldigheidStatus`.
2. On valid: creates a `DwangsomBerekening` with `startDatum = ontvangstDatum + 14 days` (grace) and sets `relevantIngbrekes`.
3. On premature: rejects with handler advice, no `DwangsomBerekening`.
4. Multiple-ingebrekestelling guard: a second notice is recorded but does NOT spawn a second `DwangsomBerekening`.

## Impact

- **Affected**: procest (`IngebrekestellingService`).
- **Traces to giant tasks**: Task 7 (registration + validation), Task 8 (prevent multiple dwangsommen), REQ-TERM-005, REQ-TERM-010 edge case.
- **Depends on**: member 04 (overschrijding status set by the scan).
