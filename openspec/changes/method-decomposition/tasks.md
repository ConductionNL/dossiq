# Tasks — Method Decomposition

## Priority 1: V1 Implementation (22+19+17+16+9+9 suppressions)

### ZrcController Decomposition (22 suppressions)

- [ ] **TASK-DECOMP-001**: Extract `ZrcController/ZaakObjectHandler.php` with `validateZaakObjectInput()`, `resolveZaakReference()`, `createZaakObject()` methods (REQ-DECOMP-01a)
- [ ] **TASK-DECOMP-002**: Update `ZrcController::createZaakObject()` to delegate to handler; verify test coverage unchanged
- [ ] **TASK-DECOMP-003**: Extract `ZrcController/RolHandler.php` with validation, role-type resolution, person reference resolution (REQ-DECOMP-01b)
- [ ] **TASK-DECOMP-004**: Extract `ZrcController/StatusHandler.php` with transition validation, status effects, response building (REQ-DECOMP-01c)
- [ ] **TASK-DECOMP-005**: Extract `ZrcController/ResultaatHandler.php` following same handler pattern
- [ ] **TASK-DECOMP-006**: Update ZrcController constructor to inject handlers; reduce class-level suppressions
- [ ] **TASK-DECOMP-007**: Verify ZrcController class length <=500 lines and CouplingBetweenObjects <=13

### ZgwService Decomposition (19 suppressions)

- [ ] **TASK-DECOMP-008**: Create `lib/Service/JwtValidationService.php` with token structure, expiry, signature, claims extraction (REQ-DECOMP-02a)
- [ ] **TASK-DECOMP-009**: Create `lib/Service/ZgwSubResourceResolver.php` with generic `resolveSubResources()` and type-specific resolve methods (REQ-DECOMP-02b)
- [ ] **TASK-DECOMP-010**: Extract `handleSubResourceList()` into: `parseListFilters()`, `querySubResources()`, `paginateResults()`, `buildListResponse()` (REQ-DECOMP-02c)
- [ ] **TASK-DECOMP-011**: Update ZgwService to use extracted services; verify constructor parameter count decreases by 3+
- [ ] **TASK-DECOMP-012**: Run phpunit ZgwServiceTest; confirm all tests pass

### ZgwZrcRulesService Decomposition (17 suppressions)

- [ ] **TASK-DECOMP-013**: Create `lib/Service/StatusTransitionValidator.php` with transition matrix, required properties, documents, effects (REQ-DECOMP-03a)
- [ ] **TASK-DECOMP-014**: Extract zaak creation validation into: `validateRequiredFields()`, `validateCaseTypeReference()`, `validateDateConsistency()`, `validateConfidentiality()` (REQ-DECOMP-03b)
- [ ] **TASK-DECOMP-015**: Create ImmutabilityChecker with: `checkClosedCase()`, `checkProtectedFields()`, `checkArchivalStatus()` (REQ-DECOMP-03c)
- [ ] **TASK-DECOMP-016**: Extract role validation into: `validateRolType()`, `validateBetrokkeneData()`, `validateUniqueRol()` with separate BSN/vestigingsnummer/medewerker validation (REQ-DECOMP-03d)
- [ ] **TASK-DECOMP-017**: Update ZgwZrcRulesService methods to use early returns and delegate to validators

### ZgwZtcRulesService Decomposition (16 suppressions)

- [ ] **TASK-DECOMP-018**: Create `lib/Service/ZaaktypeValidator.php` with identification, date range, concept, related types validation (REQ-DECOMP-04a)
- [ ] **TASK-DECOMP-019**: Create `lib/Service/ZgwReferenceResolver.php` for URL-based references, nested object resolution, circular-reference detection (REQ-DECOMP-04c; shared service)
- [ ] **TASK-DECOMP-020**: Extract sub-type validation using declarative rule arrays instead of procedural if/else chains (REQ-DECOMP-04b)
- [ ] **TASK-DECOMP-021**: Update ZgwZtcRulesService to use ZaaktypeValidator and ZgwReferenceResolver

### ZtcController Decomposition (16 suppressions)

- [ ] **TASK-DECOMP-022**: Extract `ZtcController/InformatieObjectTypeHandler.php` with validate, resolve, create, response build (REQ-DECOMP-05a)
- [ ] **TASK-DECOMP-023**: Extract `ZtcController/StatusTypeHandler.php` and `ZtcController/ResultaatTypeHandler.php` following validate-resolve-create-respond pattern (REQ-DECOMP-05c)
- [ ] **TASK-DECOMP-024**: Extract listing methods: `parseListFilters()`, `queryItems()`, `buildListResponse()` (REQ-DECOMP-05b)
- [ ] **TASK-DECOMP-025**: Inject handlers in ZtcController; verify class-level suppressions eliminated

### ZgwBrcRulesService Decomposition (12 suppressions)

- [ ] **TASK-DECOMP-026**: Extract `BrcController/BesluitHandler.php` with input validation, type resolution, zaak reference, response building (REQ-DECOMP-06a)
- [ ] **TASK-DECOMP-027**: Decompose `validateBesluitCreate()` into: `validateRequiredFields()`, `validateBesluitTypeReference()`, `validateZaakReference()`, `validateDateFields()` (REQ-DECOMP-06b)
- [ ] **TASK-DECOMP-028**: Apply early returns to `validateBesluitInformatieObject()` to reduce NPath complexity

### BrcController Decomposition (9 suppressions)

- [ ] **TASK-DECOMP-029**: Extract `BrcController/BesluitHandler.php` (integrated with TASK-DECOMP-026)
- [ ] **TASK-DECOMP-030**: Extract `searchBesluiten()` filter parsing to dedicated method

### DrcController Decomposition (9 suppressions)

- [ ] **TASK-DECOMP-031**: Extract `DrcController/DocumentHandler.php` with file upload, metadata validation, response building (REQ-DECOMP-07a)
- [ ] **TASK-DECOMP-032**: Update DrcController to inject and delegate to handler

### ZgwDrcRulesService Decomposition (9 suppressions)

- [ ] **TASK-DECOMP-033**: Extract document validation methods with guard clauses: `validateFileFormat()`, `validateFileSize()`, `validateMetadata()` (REQ-DECOMP-07b)
- [ ] **TASK-DECOMP-034**: Reuse `ZgwReferenceResolver` for cross-register reference validation (REQ-DECOMP-07c)

### ZgwBusinessRulesService Decomposition (6 suppressions)

- [ ] **TASK-DECOMP-035**: Split `validatePagination()` into: `validatePageNumber()`, `validatePageSize()`, `validateSortField()`, `buildPaginationResponse()` (REQ-DECOMP-08a)
- [ ] **TASK-DECOMP-036**: Create `lib/Service/FieldValidator.php` with date format, range, URL, and reachability validation (REQ-DECOMP-08b)
- [ ] **TASK-DECOMP-037**: Extract `validateDateFields()` and `validateUrlFields()` to use FieldValidator

### Verification Tasks (Priority 1)

- [ ] **TASK-VERIFY-001**: Run `phpunit` on ZrcControllerTest, ZrcRulesServiceTest, ZtcControllerTest, ZtcRulesServiceTest — all pass
- [ ] **TASK-VERIFY-002**: Run `phpunit` on ZgwServiceTest, ZgwBrcRulesServiceTest, ZgwDrcRulesServiceTest, ZgwBusinessRulesServiceTest — all pass
- [ ] **TASK-VERIFY-003**: Run `./vendor/bin/phpmd lib/ text phpmd.xml | grep "CyclomaticComplexity\|NPathComplexity\|ExcessiveMethodLength" | wc -l` — verify reduction by 95+
- [ ] **TASK-VERIFY-004**: Run `composer check:strict` — PHPMD reports 0 violations, PHPCS 0 violations
- [ ] **TASK-VERIFY-005**: Smoke test in browser: Cases, Tasks, Decision/Approvals flows work end-to-end

## Priority 2: V2 Bonus (4+4+1 suppressions)

### AcController Decomposition (5 suppressions)

- [ ] **TASK-DECOMP-038**: Extract `AcController/AutorisatieHandler.php` with scope validation, client verification, autorisatie creation (REQ-DECOMP-09a)
- [ ] **TASK-DECOMP-039**: Create `ScopeValidator` with scope format, existence, combination rules (REQ-DECOMP-09c)
- [ ] **TASK-DECOMP-040**: Update AcController to delegate to handler

### ZgwRulesBase Decomposition (4 suppressions)

- [ ] **TASK-DECOMP-041**: Move helper methods from ZgwRulesBase to FieldValidator and ZgwReferenceResolver
- [ ] **TASK-DECOMP-042**: Keep base class lean: only shared error handling, logging, common type resolution

### LoadDefaultZgwMappings Repair Step (4 suppressions)

- [ ] **TASK-DECOMP-043**: Create `lib/Settings/zgw_mappings/zaak_mappings.json`, `documents_mappings.json`, `besluit_mappings.json`, `catalogi_mappings.json`
- [ ] **TASK-DECOMP-044**: Extract mapping loaders: `loadZaakMappings()`, `loadDocumentMappings()`, `loadBesluitMappings()`, `loadCatalogiMappings()` (REQ-DECOMP-10a)
- [ ] **TASK-DECOMP-045**: Implement idempotent mapping updates using version field in JSON files (REQ-DECOMP-10c)
- [ ] **TASK-DECOMP-046**: Verify LoadDefaultZgwMappings class length <=1000 lines

### Single-Suppression Files (Coupling Reduction)

- [ ] **TASK-DECOMP-047**: Extract `lib/Service/MappingTransformService.php` from ZgwMappingService (REQ-DECOMP-11a)
- [ ] **TASK-DECOMP-048**: Lazy-load rarely-used dependencies in ZgwDocumentService, NotificatieService, ZgwAuthMiddleware (REQ-DECOMP-11b)

### Verification Tasks (Priority 2)

- [ ] **TASK-VERIFY-006**: Run `composer check:strict` — PHPMD reports 0 violations (including Priority 2+3 files)
- [ ] **TASK-VERIFY-007**: Full test suite passes: `phpunit`
- [ ] **TASK-VERIFY-008**: Run PHPCS on all decomposed files — 0 violations (PSR-12 compliance)
- [ ] **TASK-VERIFY-009**: Run Psalm/PHPStan on all decomposed files — 0 issues

## Documentation Tasks

- [ ] **TASK-DOCS-001**: Update architecture documentation if handler/service patterns are novel
- [ ] **TASK-DOCS-002**: Document FieldValidator, ZgwReferenceResolver, StatusTransitionValidator usage in code comments
- [ ] **TASK-DOCS-003**: Add JSON schema for zgw_mappings files in `lib/Settings/zgw_mappings/README.md`

## Final Acceptance

- [ ] **TASK-FINAL-001**: All tests pass (unit, integration, smoke)
- [ ] **TASK-FINAL-002**: `composer check:strict` passes with 0 violations
- [ ] **TASK-FINAL-003**: PHPMD reports 0 suppressions in all 12 target files
- [ ] **TASK-FINAL-004**: No behavioral changes (REST API unchanged, case workflows unchanged)
- [ ] **TASK-FINAL-005**: PR reviewed and approved by @{maintainer}
