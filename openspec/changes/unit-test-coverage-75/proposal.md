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
