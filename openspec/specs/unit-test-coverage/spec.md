---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# unit-test-coverage Specification

## Purpose
Ensures the Procest backend is covered by automated tests: every PHP service and controller class has a matching PHPUnit test exercising construction, main methods, and error handling, and a Newman/Postman collection validates the end-to-end ZGW API workflow. A coverage threshold of at least 75% of source files having a corresponding test guards against untested code reaching production.
## Requirements
### Requirement: Service unit test coverage
Every PHP service class in lib/Service/ SHALL have a corresponding PHPUnit test file in tests/Unit/Service/ with at least 3 test methods covering constructor instantiation, main public methods, and error handling.

#### Scenario: ZgwMappingService tests
- **WHEN** ZgwMappingServiceTest is executed
- **THEN** it SHALL verify getMapping returns null for missing keys, returns decoded array for valid JSON, and saveMapping persists JSON via IAppConfig

#### Scenario: ZgwPaginationHelper tests
- **WHEN** ZgwPaginationHelperTest is executed
- **THEN** it SHALL verify wrapResults produces correct ZGW HAL format with count, next, previous, and results fields

#### Scenario: ZgwDocumentService tests
- **WHEN** ZgwDocumentServiceTest is executed
- **THEN** it SHALL verify storeBase64 throws InvalidArgumentException for invalid content, storeRaw stores content via IRootFolder, and fileExists returns boolean correctly

#### Scenario: ZgwBusinessRulesService tests
- **WHEN** ZgwBusinessRulesServiceTest is executed
- **THEN** it SHALL verify the dispatcher delegates to the correct per-register rules service based on zgwApi parameter

#### Scenario: ZgwBrcRulesService tests
- **WHEN** ZgwBrcRulesServiceTest is executed
- **THEN** it SHALL verify BRC business rule validation methods return valid/invalid results with appropriate status codes

#### Scenario: ZgwDrcRulesService tests
- **WHEN** ZgwDrcRulesServiceTest is executed
- **THEN** it SHALL verify DRC business rule validation for document creation and lock validation

#### Scenario: ZgwZtcRulesService tests
- **WHEN** ZgwZtcRulesServiceTest is executed
- **THEN** it SHALL verify ZTC business rule validation for catalogi resources including concept protection

#### Scenario: NotificatieService tests
- **WHEN** NotificatieServiceTest is executed
- **THEN** it SHALL verify publish() logs warnings on delivery failure and constructs correct notification payloads

#### Scenario: ZgwService tests
- **WHEN** ZgwServiceTest is executed
- **THEN** it SHALL verify RESOURCE_MAP constant structure and that service can be constructed with mocked dependencies

### Requirement: Controller unit test coverage
Every PHP controller class in lib/Controller/ SHALL have a corresponding PHPUnit test file in tests/Unit/Controller/ with at least 3 test methods covering constructor, success paths, and error responses.

#### Scenario: DashboardController tests
- **WHEN** DashboardControllerTest is executed
- **THEN** it SHALL verify page() returns a TemplateResponse with the correct template name

#### Scenario: HealthController tests
- **WHEN** HealthControllerTest is executed
- **THEN** it SHALL verify the health check endpoint returns JSONResponse with status 200 on success and appropriate error status on failure

#### Scenario: MetricsController tests
- **WHEN** MetricsControllerTest is executed
- **THEN** it SHALL verify index() returns TextPlainResponse with Prometheus content type header

#### Scenario: SettingsController tests
- **WHEN** SettingsControllerTest is executed
- **THEN** it SHALL verify settings CRUD operations delegate to SettingsService and return JSONResponse

#### Scenario: ZgwMappingController tests
- **WHEN** ZgwMappingControllerTest is executed
- **THEN** it SHALL verify index() returns mapping list, show() returns single mapping, and update() persists changes

#### Scenario: ZGW register controller tests (ZrcController, ZtcController, DrcController, BrcController, NrcController, AcController)
- **WHEN** each ZGW register controller test is executed
- **THEN** it SHALL verify index/create/show/update/destroy methods delegate to ZgwService and return JSONResponse

### Requirement: Newman API test collection
A Newman/Postman collection SHALL exist at tests/newman/ that tests the main ZGW API workflow endpoints.

#### Scenario: ZGW workflow collection exists
- **WHEN** the tests/newman/ directory is inspected
- **THEN** it SHALL contain a Postman collection JSON file and an environment JSON file

#### Scenario: Collection covers ZGW CRUD flow
- **WHEN** the Newman collection is executed against a running environment
- **THEN** it SHALL test creating a catalogus, zaaktype, zaak, status, and document in sequence with response validation

### Requirement: Test file coverage threshold
The ratio of PHP files with corresponding test files SHALL be at least 75%.

#### Scenario: Coverage count
- **WHEN** test files in tests/Unit/ are counted against source files in lib/
- **THEN** at least 33 of the 42 PHP source files SHALL have a corresponding test file

