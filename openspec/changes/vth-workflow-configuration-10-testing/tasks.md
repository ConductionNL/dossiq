# Tasks: vth-workflow-configuration-10-testing

Cross-cutting unit/integration/E2E tests. Traces to giant Tasks 21, 22, 23.

## 1. Unit Tests

- [ ] `LegesCalculationServiceTest` (modifiers, verrekening, refund, rule versioning)
- [ ] `BeschikkingGenerationServiceTest` (merge fields, required-field validation, versioning)
- [ ] `LhsoLookupServiceTest` (all 16 lookups, invalid inputs)
- [ ] `DsoIntakeServiceTest` (STAM 2.0 mapping, reference resolution, bijlagen)
- [ ] `MobileInspectionServiceTest` (photo storage, GPS fallback, required-item/photo validation)

## 2. Integration Tests

- [ ] `WorkflowTransitionTest`: Omgevingsvergunning intake → beschikking → verleend
- [ ] Test guard validation (e.g. advies required before Beschikking) and notifications per transition
- [ ] Test Toezichtzaak inspection flow and Handhavingszaak LHSO/intervention flow

## 3. End-to-End

- [ ] `DsoIntegrationTest`: mock STAM 2.0 verzoek, simulate ObjectCreatedEvent, assert case creation
- [ ] Transition the case and assert VergunningStatusChangedEvent dispatch + payload for each transition
