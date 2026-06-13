# Unit Test Coverage 75%+ and Newman API Tests

## Problem
Procest currently has only 26% test file coverage (11/42 PHP files). Services are at 33% (5/15) and controllers at 8% (1/12). This makes refactoring risky and blocks CI quality gates.

## Proposed Solution
1. Add PHPUnit unit tests for all 10 untested services and all 11 untested controllers
2. Each test file covers constructor, main public methods, and error paths (3-5+ tests each)
3. Add Newman/Postman API test collection for ZGW endpoint workflow testing
4. Target: 75%+ file coverage (33+ of 42 files)

## Scope

### Services to Test
- NotificatieService - notification publishing
- ZgwBrcRulesService - BRC business rules
- ZgwBusinessRulesService - dispatcher to per-register rules
- ZgwDocumentService - binary file storage
- ZgwDrcRulesService - DRC business rules
- ZgwMappingService - mapping config management
- ZgwPaginationHelper - ZGW HAL pagination
- ZgwRulesBase - base validation utilities (tested via concrete subclass)
- ZgwService - shared ZGW operations
- ZgwZtcRulesService - ZTC business rules

### Controllers to Test
- AcController, BrcController, DashboardController, DrcController
- HealthController, MetricsController, NrcController, SettingsController
- ZgwMappingController, ZrcController, ZtcController

### Newman API Tests
- ZGW workflow collection (catalogi, zaken, documenten, besluiten flow)
- Environment file for local development

## Out of Scope
- Integration tests requiring running Nextcloud instance
- Frontend/Vue component tests
- Increasing line-level code coverage metrics

## Risks
- ZgwService constructor accesses OC::$server directly, requiring careful mocking
- Large controller files (ZrcController, DrcController) have many methods; tests focus on key paths

## GitHub Issue
ConductionNL/procest#102



## Design

## Context

Procest has 42 PHP source files in lib/ but only 11 test files in tests/Unit/ (26% file coverage). The tested files are concentrated in a few services (SettingsService, GisProxyService, SeedDataService, ParaferingNotificationService, ZgwZrcRulesService) and one controller (GisProxyController). The remaining 10 services and 11 controllers have zero test coverage.

The existing test pattern uses PHPUnit\Framework\TestCase with Nextcloud interface mocks (IAppConfig, IRequest, LoggerInterface, etc.). Tests follow a consistent docblock style with PHPCS-compliant `//end` comments.

Key challenge: Several services (ZgwService, NotificatieService) access `\OC::$server` in their constructors to dynamically load OpenRegister services. These cannot be instantiated without mocking the global container.

## Goals / Non-Goals

**Goals:**
- Add PHPUnit unit tests for all 10 untested services (3-5+ test methods each)
- Add PHPUnit unit tests for all 11 untested controllers (3-5+ test methods each)
- Add Newman/Postman API test collection for ZGW workflow testing
- Achieve 75%+ file coverage (33+ of 42 PHP files have corresponding test files)
- All tests pass via `vendor/bin/phpunit`

**Non-Goals:**
- Line-level or branch-level code coverage metrics (file coverage only)
- Integration tests requiring a running Nextcloud/database
- Frontend Vue component tests
- Refactoring production code to improve testability

## Decisions

### 1. Test-per-class pattern
Each PHP class gets its own test file in the mirrored namespace (tests/Unit/Service/, tests/Unit/Controller/, tests/Unit/Middleware/). This matches the existing pattern.

**Rationale**: Consistent with existing tests (SettingsServiceTest, GisProxyControllerTest). Easy to correlate which files are tested.

### 2. Mock OC::$server for constructor-loading services
For ZgwService and NotificatieService that call `\OC::$server->get()` in constructors, we will use `@runInSeparateProcess` or create test subclasses that override the constructor loading, OR use reflection to bypass the constructor and set dependencies directly.

**Decision**: Use `TestCase::getMockBuilder()->disableOriginalConstructor()` is not viable since we test the actual class. Instead, we will mock `\OC::$server` as a global in the bootstrap, setting it to a mock ContainerInterface. This pattern is used in other Nextcloud app test suites.

**Alternative considered**: Refactoring constructors to accept optional dependencies -- rejected as out of scope (no production code changes).

### 3. Controller tests focus on routing and delegation
ZGW controllers (ZrcController, BrcController, etc.) are thin wrappers around ZgwService. Tests verify:
- Constructor works with mocked dependencies
- Methods call the correct ZgwService methods
- Return types are JSONResponse
- Error cases return appropriate HTTP status codes

**Rationale**: Testing the full ZGW business logic belongs in the service tests; controller tests verify the wiring.

### 4. Newman collection structure
One collection file covering the main ZGW workflow (create catalogus -> zaaktype -> zaak -> status -> document). Environment file with variables for base URL and auth.

**Rationale**: Tests the happy path end-to-end via HTTP. Complements unit tests which mock all I/O.

## Risks / Trade-offs

- **[Risk] OC::$server mocking complexity** -> Mitigation: If global mocking proves fragile, fall back to testing only public method behavior on instantiable classes and skip constructor-side-effect testing for ZgwService/NotificatieService.
- **[Risk] Large controller files** -> Mitigation: Focus on 3-5 key methods per controller (index, create, show, update, destroy) rather than every method.
- **[Risk] Newman tests require running environment** -> Mitigation: Newman tests are optional/CI-skip by default; they run only when the docker environment is up.



## Tasks

## 1. Test Infrastructure Setup

- [ ] 1.1 Ensure tests/bootstrap.php supports OC::$server mocking for unit tests that need container access

## 2. Service Unit Tests - Simple Services

- [ ] 2.1 Create ZgwMappingServiceTest (getMapping, saveMapping, listMappings, deleteMapping)
- [ ] 2.2 Create ZgwPaginationHelperTest (wrapResults with various page/count combinations)
- [ ] 2.3 Create ZgwDocumentServiceTest (storeBase64, storeRaw, getContent, fileExists, deleteFiles)

## 3. Service Unit Tests - Business Rules

- [ ] 3.1 Create ZgwBrcRulesServiceTest (BRC rules: besluit create validation, uniqueness, immutability)
- [ ] 3.2 Create ZgwDrcRulesServiceTest (DRC rules: document create validation, lock checks)
- [ ] 3.3 Create ZgwZtcRulesServiceTest (ZTC rules: concept protection, afleidingswijze validation)
- [ ] 3.4 Create ZgwBusinessRulesServiceTest (dispatcher: delegates to correct register rules service)

## 4. Service Unit Tests - Complex Services

- [ ] 4.1 Create NotificatieServiceTest (publish, deliver failure logging)
- [ ] 4.2 Create ZgwServiceTest (RESOURCE_MAP structure, constructor dependencies)

## 5. Controller Unit Tests - Simple Controllers

- [ ] 5.1 Create DashboardControllerTest (page returns TemplateResponse)
- [ ] 5.2 Create HealthControllerTest (health check returns JSONResponse with status)
- [ ] 5.3 Create MetricsControllerTest (index returns TextPlainResponse with Prometheus header)
- [ ] 5.4 Create SettingsControllerTest (getSettings, updateSettings delegation)
- [ ] 5.5 Create ZgwMappingControllerTest (index, show, update mapping operations)

## 6. Controller Unit Tests - ZGW Register Controllers

- [ ] 6.1 Create ZrcControllerTest (index, create, show, update, destroy delegation to ZgwService)
- [ ] 6.2 Create ZtcControllerTest (index, create, show, update, destroy plus publish action)
- [ ] 6.3 Create DrcControllerTest (index, create, show, update, destroy plus download action)
- [ ] 6.4 Create BrcControllerTest (index, create, show, update, destroy delegation)
- [ ] 6.5 Create NrcControllerTest (index, create, show delegation)
- [ ] 6.6 Create AcControllerTest (index, create, show, update, destroy delegation)

## 7. Newman API Tests

- [ ] 7.1 Create tests/newman/zgw-workflow.postman_collection.json with ZGW CRUD flow
- [ ] 7.2 Create tests/newman/procest-environment.json with local dev variables

## 8. Verification

- [ ] 8.1 Run full PHPUnit suite and verify all tests pass
- [ ] 8.2 Verify file coverage count is 33+ of 42 (75%+)