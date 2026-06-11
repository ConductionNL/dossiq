# Tasks: unit-test-coverage-75

> **Build status (hydra audit 2026-06-10).** Partial. The test suite has grown to 100 *Test.php files in tests/. Of the 14 listed in this spec, audit-verified existing: ZgwMappingServiceTest, ZgwPaginationHelperTest, HealthControllerTest, MetricsControllerTest. Missing: ZgwDocumentServiceTest, ZgwBrcRulesServiceTest, ZgwDrcRulesServiceTest, ZgwZtcRulesServiceTest, ZgwBusinessRulesServiceTest, NotificatieServiceTest, ZgwServiceTest, DashboardControllerTest, SettingsControllerTest, ZgwMappingControllerTest. Tasks stay [ ] for the missing tests; the 75% coverage target is real forward work.

## 1. Test Infrastructure Setup

- [ ] 1.1 Ensure `tests/bootstrap.php` supports `OC::$server` mocking for unit tests that need container access.

## 2. Service Unit Tests — Simple Services

- [x] 2.1 `ZgwMappingServiceTest` — verified on dev (`tests/Unit/Service/ZgwMappingServiceTest.php`).
- [x] 2.2 `ZgwPaginationHelperTest` — verified on dev (`tests/Unit/Service/ZgwPaginationHelperTest.php`).
- [ ] 2.3 `ZgwDocumentServiceTest` — `storeBase64`, `storeRaw`, `getContent`, `fileExists`, `deleteFiles` (missing).

## 3. Service Unit Tests — Business Rules

- [~] 3.1 Register rules services: `ZgwBrcRulesServiceTest` (missing), `ZgwDrcRulesServiceTest` (missing), `ZgwZtcRulesServiceTest` (missing). `ZgwZrcRulesServiceTest` exists on dev as a partial slice.
- [ ] 3.2 `ZgwBusinessRulesServiceTest` — dispatcher delegates to the correct per-register rules service (missing).

## 4. Service Unit Tests — Complex Services

- [ ] 4.1 `NotificatieServiceTest` (`publish`, deliver-failure logging) and `ZgwServiceTest` (`RESOURCE_MAP` structure, constructor dependencies) (both missing).

## 5. Controller Unit Tests — Simple Controllers

- [~] 5.1 `HealthControllerTest` + `MetricsControllerTest` verified on dev; `DashboardControllerTest` missing.
- [ ] 5.2 `SettingsControllerTest` and `ZgwMappingControllerTest` (both missing).

## 6. Controller Unit Tests — ZGW Register Controllers

- [ ] 6.1 `ZrcControllerTest` and `ZtcControllerTest` — index/create/show/update/destroy delegation to `ZgwService`; ZtcControllerTest also covers `publish`.
- [ ] 6.2 `DrcControllerTest` and `BrcControllerTest` — index/create/show/update/destroy delegation; DrcControllerTest also covers `download`.
- [ ] 6.3 `NrcControllerTest` (index/create/show delegation) and `AcControllerTest` (index/create/show/update/destroy delegation).

## 7. Newman API Tests

- [x] 7.1 Create `tests/newman/zgw-workflow.postman_collection.json` (ZGW CRUD flow: catalogi zaaktype → zaken zaak → besluiten besluit, plus validation + cleanup) and `tests/newman/procest-environment.json` (local dev variables).

## 8. Verification

- [ ] 8.1 Run full PHPUnit suite and verify all tests pass.
- [ ] 8.2 Verify file coverage count is 33+ of 42 (75%+).
