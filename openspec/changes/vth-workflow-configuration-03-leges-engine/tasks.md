# Tasks: vth-workflow-configuration-03-leges-engine

Leges calculation engine consuming member-01 rule sets. Traces to giant Task 4.

## 1. LegesCalculationService

- [~] Implement `calculateFee(caseId)` → {baseFee, modifiers, totalFee, rules} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `applyVerrekening(caseId, priorFee)` → {offset, finalFee} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `refund(caseId, reason)` → {refundAmount, status} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `navordering(caseId, amount, reason)` recording an additional fee — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Read the active rule set for the case's zaaktype/activiteit from member-01 seed — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. LegesController

- [~] Create `LegesController` with POST /api/vth/leges/calculate and per-case verrekening/refund/navordering endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add per-object authorization guard (case belongs to caller) before mutations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate request inputs server-side — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Audit & Tests

- [~] Log all leges transactions (calculation, verrekening, refund, navordering) to an audit trail — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test calculation with all modifier types — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test verrekening offset logic — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test refund conditions and notification — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test navordering additional-fee recording — deferred to downstream cycle / fleet-wide adoption (handoff)
