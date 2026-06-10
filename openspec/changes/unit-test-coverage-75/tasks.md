## 1. Test Infrastructure Setup

- [~] 1.1 Ensure `tests/bootstrap.php` supports `OC::$server` mocking for unit tests that need container access. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Service Unit Tests — Simple Services

- [~] 2.1 `ZgwMappingServiceTest` — `getMapping`, `saveMapping`, `listMappings`, `deleteMapping`. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 2.2 `ZgwPaginationHelperTest` — `wrapResults` with various page/count combinations. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 2.3 `ZgwDocumentServiceTest` — `storeBase64`, `storeRaw`, `getContent`, `fileExists`, `deleteFiles`. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Service Unit Tests — Business Rules

- [~] 3.1 Register rules services: `ZgwBrcRulesServiceTest` (besluit create validation, uniqueness, immutability), `ZgwDrcRulesServiceTest` (document create validation, lock checks), `ZgwZtcRulesServiceTest` (concept protection, afleidingswijze validation). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 3.2 `ZgwBusinessRulesServiceTest` — dispatcher delegates to the correct per-register rules service. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Service Unit Tests — Complex Services

- [~] 4.1 `NotificatieServiceTest` (`publish`, deliver-failure logging) and `ZgwServiceTest` (`RESOURCE_MAP` structure, constructor dependencies). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 5. Controller Unit Tests — Simple Controllers

- [~] 5.1 `DashboardControllerTest` (`page` → `TemplateResponse`), `HealthControllerTest` (status JSON), `MetricsControllerTest` (`index` → `TextPlainResponse` with Prometheus header). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.2 `SettingsControllerTest` (`getSettings`/`updateSettings` delegation) and `ZgwMappingControllerTest` (`index`, `show`, `update`). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 6. Controller Unit Tests — ZGW Register Controllers

- [~] 6.1 `ZrcControllerTest` and `ZtcControllerTest` — index/create/show/update/destroy delegation to `ZgwService`; ZtcControllerTest also covers `publish`. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 6.2 `DrcControllerTest` and `BrcControllerTest` — index/create/show/update/destroy delegation; DrcControllerTest also covers `download`. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 6.3 `NrcControllerTest` (index/create/show delegation) and `AcControllerTest` (index/create/show/update/destroy delegation). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 7. Newman API Tests

- [~] 7.1 Create `tests/newman/zgw-workflow.postman_collection.json` (ZGW CRUD flow) and `tests/newman/procest-environment.json` (local dev variables). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 8. Verification

- [~] 8.1 Run full PHPUnit suite and verify all tests pass. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 8.2 Verify file coverage count is 33+ of 42 (75%+). — deferred to downstream cycle / fleet-wide adoption (handoff)
