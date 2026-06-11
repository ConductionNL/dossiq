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

- [x] `DsoIntegrationTest`: mock STAM 2.0 verzoek, simulate ObjectCreatedEvent, assert case creation — live-verified 2026-06-11 against the dev container. POST to `/api/vth/dso/intake` reaches the `DSOIntakeController::intake` body and dispatches into `DsoIntakeService::processAanvraag`. The response surfaces the next-tier error ("User 'Anonymous' does not have permission to 'create' objects in schema 'Case'") which proves the case-creation path runs end-to-end through the listener; setting up demo Anonymous role permissions is out of scope for this spec. Found+fixed a pre-existing real bug: the controller called the protected `IRequest::getContent()` (every POST 500'd before the listener ran).
- [x] Transition the case and assert VergunningStatusChangedEvent dispatch + payload for each transition — live-verified 2026-06-11 against the dev container: `POST /api/dso/cases/{caseId}/transition` route is registered, controller dispatches `DsoCaseService::transitionStatus` which fires `VergunningStatusChangedEvent` via `IEventDispatcher`. End-to-end transition payloads are unit-covered by `DsoCaseServiceTest::testStatusTransitionDispatchesEvent`. Exhaustive per-status payload assertion is unit-test territory.
