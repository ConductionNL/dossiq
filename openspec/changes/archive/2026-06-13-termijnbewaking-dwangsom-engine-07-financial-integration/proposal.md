---
kind: code
depends_on: [termijnbewaking-dwangsom-engine-06-dwangsom-calculation]
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

# Proposal: termijnbewaking-dwangsom-engine-07-financial-integration

Member 7 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032). Predecessor: `termijnbewaking-dwangsom-engine-06-dwangsom-calculation`. This `kind: code` member implements the payment-signal preparation to the ERP via openconnector and the payment-confirmation callback handler.

## Why

A computed dwangsom is only meaningful once it is actually paid. AWB 4:17 lid 2 obliges the bestuursorgaan to pay within 28 days of the notice; the payment must be ERP-processable with full traceability (bedrag, IBAN, wettelijke grondslag, referentie) and the confirmation must flow back to close the loop and notify the burger. This member is the bridge to the financial system.

## What Changes (this member)

1. `DwangsomUitbetalingService.prepareBetaling()` creates a `DwangsomUitbetaling` (validated IBAN, referentie, `betaaldatumUiterlijk = ontvangstDatum + 28 days`) and emits `dwangsom-payment-signal` to openconnector.
2. `OpenconnectorCallbackController` handles the ERP payment-confirmation callback (signature-validated), updates `DwangsomUitbetaling`, emits `dwangsom-betaald`, and triggers the burger payment notification.

## Impact

- **Affected**: procest (`DwangsomUitbetalingService`, `OpenconnectorCallbackController`), openconnector (payment event consumer + callback origin).
- **Traces to giant tasks**: Task 12 (prepare + emit signal), Task 13 (callback handler), REQ-TERM-007.
- **Depends on**: member 06 (`prepareBetaling()` hand-off + locked `definitievBedrag`).
