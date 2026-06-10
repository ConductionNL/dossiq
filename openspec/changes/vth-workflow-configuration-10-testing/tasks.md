# Tasks: vth-workflow-configuration-10-testing

Cross-cutting unit/integration/E2E tests. Traces to giant Tasks 21, 22, 23.

## 1. Unit Tests

- [~] `LegesCalculationServiceTest` (modifiers, verrekening, refund, rule versioning) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `BeschikkingGenerationServiceTest` (merge fields, required-field validation, versioning) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `LhsoLookupServiceTest` (all 16 lookups, invalid inputs) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `DsoIntakeServiceTest` (STAM 2.0 mapping, reference resolution, bijlagen) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `MobileInspectionServiceTest` (photo storage, GPS fallback, required-item/photo validation) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Integration Tests

- [~] `WorkflowTransitionTest`: Omgevingsvergunning intake → beschikking → verleend — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test guard validation (e.g. advies required before Beschikking) and notifications per transition — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test Toezichtzaak inspection flow and Handhavingszaak LHSO/intervention flow — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. End-to-End

- [~] `DsoIntegrationTest`: mock STAM 2.0 verzoek, simulate ObjectCreatedEvent, assert case creation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Transition the case and assert VergunningStatusChangedEvent dispatch + payload for each transition — deferred to downstream cycle / fleet-wide adoption (handoff)
