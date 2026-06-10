# Tasks: vth-workflow-configuration-03-leges-engine

Leges calculation engine consuming member-01 rule sets. Traces to giant Task 4.

## 1. LegesCalculationService

- [ ] Implement `calculateFee(caseId)` → {baseFee, modifiers, totalFee, rules}
- [ ] Implement `applyVerrekening(caseId, priorFee)` → {offset, finalFee}
- [ ] Implement `refund(caseId, reason)` → {refundAmount, status}
- [ ] Implement `navordering(caseId, amount, reason)` recording an additional fee
- [ ] Read the active rule set for the case's zaaktype/activiteit from member-01 seed

## 2. LegesController

- [ ] Create `LegesController` with POST /api/vth/leges/calculate and per-case verrekening/refund/navordering endpoints
- [ ] Add per-object authorization guard (case belongs to caller) before mutations
- [ ] Validate request inputs server-side

## 3. Audit & Tests

- [ ] Log all leges transactions (calculation, verrekening, refund, navordering) to an audit trail
- [ ] Test calculation with all modifier types
- [ ] Test verrekening offset logic
- [ ] Test refund conditions and notification
- [ ] Test navordering additional-fee recording
