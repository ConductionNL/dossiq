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
