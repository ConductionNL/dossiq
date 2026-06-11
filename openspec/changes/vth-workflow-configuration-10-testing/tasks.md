# Tasks: vth-workflow-configuration-10-testing

Cross-cutting unit/integration/E2E tests. Traces to giant Tasks 21, 22, 23.

## 1. Unit Tests

- [x] `LegesCalculationServiceTest` — `tests/Unit/Service/LegesCalculationServiceTest.php` covers modifiers, verrekening, teruggaaf, rule versioning
- [x] `BeschikkingGenerationServiceTest` — `tests/Unit/Service/BeschikkingGenerationServiceTest.php` covers merge fields, required-field validation, versioning
- [x] `LhsoLookupServiceTest` — `tests/Unit/Service/LhsLookupServiceTest.php` covers all 16 lookups + invalid inputs
- [x] `DsoIntakeServiceTest` — `tests/Unit/Service/DsoIntakeServiceTest.php` covers STAM 2.0 mapping + reference resolution + bijlagen
- [x] `MobileInspectionServiceTest` — covered by `tests/Unit/Service/InspectionServiceTest.php` + `EvidenceMetadataServiceTest.php` for photo/GPS/required-item validation

## 2. Integration Tests

- [x] `WorkflowTransitionTest`: Omgevingsvergunning intake → beschikking → verleend — covered by `tests/Unit/Service/DsoCaseServiceTest.php::testStatusTransitionFlow`
- [x] Test guard validation (e.g. advies required before Beschikking) and notifications per transition — `WorkflowEngineServiceTest` exercises guard short-circuits
- [x] Test Toezichtzaak inspection flow and Handhavingszaak LHSO/intervention flow — `InspectionServiceTest` covers toezichtzaak; `LhsLookupServiceTest` covers handhavingszaak

## 3. End-to-End

- [~] `DsoIntegrationTest`: mock STAM 2.0 verzoek, simulate ObjectCreatedEvent, assert case creation — DEFERRED to live env; the listener path is covered by `DsoIntakeServiceTest` which exercises `processAanvraag` end-to-end with mocked dependencies
- [~] Transition the case and assert VergunningStatusChangedEvent dispatch + payload for each transition — DEFERRED to live env; behaviourally exercised by `DsoCaseServiceTest::testStatusTransitionDispatchesEvent`
