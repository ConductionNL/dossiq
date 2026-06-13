---
kind: code
depends_on:
  - vth-workflow-configuration-01-config-foundation
chain:
  - vth-workflow-configuration-01-config-foundation
  - vth-workflow-configuration-02-workflow-templates
  - vth-workflow-configuration-03-leges-engine
  - vth-workflow-configuration-04-leges-config-ui
  - vth-workflow-configuration-05-beschikking-generation
  - vth-workflow-configuration-06-mobile-inspection
  - vth-workflow-configuration-07-lhso-classification
  - vth-workflow-configuration-08-dso-integration
  - vth-workflow-configuration-09-admin-settings
  - vth-workflow-configuration-10-testing
  - vth-workflow-configuration-11-quality-docs
---

# VTH Workflow Configuration — 03 Leges Engine

> Member 3 of 11 in the `vth-workflow-configuration` ADR-032 chain. `kind: code`. Depends on member 01 (which seeds the leges rule sets). Implements the leges (fee) calculation service and its endpoints.

## Summary

Implement `LegesCalculationService` and `LegesController`: rule-based fee calculation per zaaktype/activiteit, with verrekening (offsetting prior fees), teruggaaf (refund), and navordering (additional billing), plus an audit trail for every leges transaction. The leges rule sets are seeded by member 01; this member is the calculation engine consuming them. Traces to giant Task 4.

## Scope

### In Scope

- `LegesCalculationService.calculateFee / applyVerrekening / refund / navordering`.
- `LegesController` endpoints (calculate, verrekening, etc.).
- Audit logging of all leges transactions.

### Out of Scope

- Leges rule-configuration UI (member 04).
- Rule-set seed data (member 01).

## Dependencies

- **vth-workflow-configuration-01-config-foundation**: provides seeded leges rule sets.

## Acceptance Criteria

1. GIVEN a case (Omgevingsvergunning, Verbouwing, 250 m²), WHEN calculated, THEN fee = base + size modifier per the seeded rule set.
2. GIVEN prior fees, WHEN verrekening applies, THEN final fee = calculated − offset.
3. GIVEN a withdrawal before beschikking, WHEN refund runs, THEN a full refund is recorded with audit.
