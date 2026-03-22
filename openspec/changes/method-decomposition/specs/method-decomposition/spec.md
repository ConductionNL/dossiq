---
status: proposed
priority: high
estimated_effort: large
---

# Method Decomposition -- Procest

## Purpose

Eliminate 152 PHPMD complexity suppressions by decomposing complex methods into smaller, focused units. Each suppression represents a method or class that exceeds PHPMD's strict thresholds (CC>10, NPath>200, MethodLength>100, ClassLength>1000). This refactoring improves maintainability, testability, and code quality without changing any external behavior.

**Tender demand**: Code quality metrics are increasingly requested in Dutch government tenders. ISO 25010 (software quality) compliance requires demonstrable maintainability. Clean PHPMD reports without suppressions demonstrate compliance.
**Standards**: PHPMD, PHPCS (PSR-12), Psalm, PHPStan, ISO 25010
**Feature tier**: V1 (Priority 1 files), V2 (Priority 2+3 files)

## Current State

- **CyclomaticComplexity suppressions:** 53 (methods with >10 branches)
- **NPathComplexity suppressions:** 41 (methods with >200 execution paths)
- **ExcessiveMethodLength suppressions:** 13 (methods >100 lines)
- **ExcessiveClassComplexity suppressions:** 15 (classes with too much logic)
- **ExcessiveClassLength suppressions:** 8 (classes >1000 lines)
- **CouplingBetweenObjects suppressions:** 14 (too many dependencies)
- **TooManyMethods suppressions:** 8

## Requirements

---

### REQ-DECOMP-01: ZrcController Decomposition

The `ZrcController` (22 suppressions) MUST be decomposed into focused handler classes while preserving its public REST API surface. The controller handles ZGW Zaken CRUD operations for zaken, zaakobjecten, rollen, resultaten, statussen, and klantcontacten.

**Feature tier**: V1


#### Scenario DECOMP-01a: Extract zaakobject creation handler

- GIVEN `ZrcController::createZaakObject()` has CyclomaticComplexity and NPathComplexity suppressions
- WHEN the method is decomposed
- THEN a `ZrcController/ZaakObjectHandler.php` class MUST be created
- AND the handler MUST contain: `validateZaakObjectInput()`, `resolveZaakReference()`, `createZaakObject()`
- AND `ZrcController::createZaakObject()` MUST delegate to the handler
- AND the CyclomaticComplexity of each new method MUST be <=10
- AND `phpunit --filter ZrcControllerTest` MUST pass with identical results

#### Scenario DECOMP-01b: Extract rol creation handler

- GIVEN `ZrcController::createRol()` has CyclomaticComplexity and NPathComplexity suppressions
- WHEN the method is decomposed
- THEN a `ZrcController/RolHandler.php` class MUST be created
- AND the handler MUST contain: `validateRolInput()`, `resolveRolType()`, `resolvePersonReference()`, `createRol()`
- AND each new method MUST have NPathComplexity <=200
- AND the public API response format MUST remain unchanged

#### Scenario DECOMP-01c: Extract status creation handler

- GIVEN `ZrcController::createStatus()` has CC, NPath, and MethodLength suppressions (the most complex method)
- WHEN the method is decomposed
- THEN a `ZrcController/StatusHandler.php` class MUST be created
- AND the handler MUST contain: `validateStatusInput()`, `resolveStatusType()`, `checkStatusTransition()`, `applyStatusEffects()`, `createStatusResponse()`
- AND each new method MUST be <=50 lines
- AND the status transition validation logic MUST be preserved exactly

#### Scenario DECOMP-01d: Extract zaak update handler

- GIVEN `ZrcController::updateZaak()` has CC, NPath, and MethodLength suppressions
- WHEN the method is decomposed
- THEN validation, field mapping, and response building MUST be separate methods
- AND immutability checks (closed case protection) MUST be in a dedicated `validateZaakMutability()` method

#### Scenario DECOMP-01e: Reduce class-level suppressions

- GIVEN `ZrcController` has 7 class-level suppressions (coupling, class length, class complexity, method length, CC, NPath, TooManyMethods)
- WHEN all method-level decompositions are complete
- THEN the class MUST inject handler classes via constructor: `ZaakObjectHandler`, `RolHandler`, `StatusHandler`, `ResultaatHandler`
- AND the controller class MUST be <=500 lines (excluding doc blocks)
- AND the CouplingBetweenObjects count MUST be <=13 (the handler classes replace direct dependencies)

---

### REQ-DECOMP-02: ZgwService Decomposition

The `ZgwService` (19 suppressions) MUST be decomposed. It is the core ZGW orchestration service handling zaak creation, JWT validation, sub-resource lookups, and API proxying.

**Feature tier**: V1


#### Scenario DECOMP-02a: Extract JWT validation

- GIVEN `ZgwService::validateJwtToken()` and `validateJwtSignature()` have CC and NPath suppressions
- WHEN the methods are decomposed
- THEN a `Service/JwtValidationService.php` class MUST be created
- AND it MUST contain: `validateTokenStructure()`, `validateTokenExpiry()`, `validateSignature()`, `extractClaims()`
- AND each method MUST have CC <=10

#### Scenario DECOMP-02b: Extract sub-resource lookup methods

- GIVEN `lookupZaakObjecten`, `lookupRollen`, `lookupStatussen`, `lookupResultaten` each have CC+NPath suppressions
- WHEN the methods are decomposed
- THEN a `Service/ZgwSubResourceResolver.php` class MUST be created
- AND a generic `resolveSubResources(string $type, array $filters): array` method MUST handle the common pattern
- AND type-specific logic MUST be in `resolve{Type}()` methods
- AND the 4 individual lookup methods MUST delegate to the resolver

#### Scenario DECOMP-02c: Extract handleSubResourceList

- GIVEN `ZgwService::handleSubResourceList()` has CC, NPath, and MethodLength suppressions
- WHEN the method is decomposed
- THEN it MUST be split into: `parseListFilters()`, `querySubResources()`, `paginateResults()`, `buildListResponse()`
- AND the pagination logic MUST use `ZgwPaginationHelper` (already exists) for the common parts

#### Scenario DECOMP-02d: Reduce class coupling

- GIVEN `ZgwService` has CouplingBetweenObjects suppression (>13 dependencies)
- WHEN JWT validation and sub-resource resolution are extracted
- THEN the constructor parameter count MUST decrease by at least 3
- AND the remaining `ZgwService` MUST focus only on zaak creation and high-level orchestration

---

### REQ-DECOMP-03: ZgwZrcRulesService Decomposition

The `ZgwZrcRulesService` (17 suppressions) MUST be decomposed. It handles ZGW Zaken business rules validation (the most complex validation service).

**Feature tier**: V1


#### Scenario DECOMP-03a: Extract status transition validation

- GIVEN `validateStatusTransition()` has NPath suppression and `handleZaakStatusUpdate()` has CC+NPath+MethodLength
- WHEN the methods are decomposed
- THEN a `Service/StatusTransitionValidator.php` class MUST be created
- AND it MUST contain: `validateTransitionAllowed()`, `validateRequiredProperties()`, `validateRequiredDocuments()`, `applyTransitionEffects()`
- AND the transition validation matrix MUST be configurable (not hardcoded if/else chains)

#### Scenario DECOMP-03b: Extract zaak creation validation

- GIVEN `validateCreateZaak()` has CC suppression
- WHEN the method is decomposed
- THEN it MUST be split into: `validateRequiredFields()`, `validateCaseTypeReference()`, `validateDateConsistency()`, `validateConfidentiality()`
- AND each validator MUST throw a specific exception type for different validation failures

#### Scenario DECOMP-03c: Extract zaak update validation

- GIVEN `validateZaakUpdate()` and `validateImmutability()` have CC+NPath suppressions
- WHEN the methods are decomposed
- THEN immutability rules MUST be in an `ImmutabilityChecker` with methods per rule: `checkClosedCase()`, `checkProtectedFields()`, `checkArchivalStatus()`
- AND the update validator MUST use guard clauses (early returns) to reduce nesting

#### Scenario DECOMP-03d: Extract role validation

- GIVEN `validateRolCreate()` has CC+NPath suppressions
- WHEN the method is decomposed
- THEN it MUST be split into: `validateRolType()`, `validateBetrokkeneData()`, `validateUniqueRol()`
- AND BSN validation, vestigingsnummer validation, and medewerker validation MUST be separate methods

---

### REQ-DECOMP-04: ZgwZtcRulesService Decomposition

The `ZgwZtcRulesService` (16 suppressions) MUST be decomposed. It handles ZGW Catalogi (zaaktype) business rules.

**Feature tier**: V1


#### Scenario DECOMP-04a: Extract zaaktype validation

- GIVEN `validateZaaktypeCreate()` has CC+NPath suppressions
- WHEN the method is decomposed
- THEN a `Service/ZaaktypeValidator.php` class MUST be created
- AND it MUST contain: `validateIdentificatie()`, `validateDateRange()`, `validateConcept()`, `validateRelatedTypes()`
- AND each method MUST have CC <=10

#### Scenario DECOMP-04b: Extract sub-type validation methods

- GIVEN `validateStatusTypeCreate`, `validateResultaatTypeCreate`, `validateEigenschapCreate`, `validateInformatieObjectTypeCreate` each have CC+NPath
- WHEN the methods are decomposed
- THEN a common `validateSubTypeCreate(string $type, array $data, array $rules): void` pattern MUST be used
- AND type-specific rules MUST be in dedicated validator methods
- AND the validation rule structure MUST be declarative (array of rules) rather than procedural (if/else chains)

#### Scenario DECOMP-04c: Extract reference resolution

- GIVEN `resolveZaaktypeReference()` and `resolveNestedObjectReferences()` have CC+NPath suppressions
- WHEN the methods are decomposed
- THEN a `Service/ZgwReferenceResolver.php` class MUST be created (shared across all rules services)
- AND it MUST handle: URL-based references, nested object resolution, and reference validation
- AND circular reference detection MUST be included

---

### REQ-DECOMP-05: ZtcController Decomposition

The `ZtcController` (16 suppressions) MUST be decomposed following the same handler pattern as ZrcController.

**Feature tier**: V1


#### Scenario DECOMP-05a: Extract informatie object type creation

- GIVEN `createInformatieObjectType()` has CC+NPath+MethodLength suppressions (most complex method)
- WHEN the method is decomposed
- THEN a `ZtcController/InformatieObjectTypeHandler.php` class MUST be created
- AND it MUST contain: `validateInput()`, `resolveReferences()`, `create()`, `buildResponse()`

#### Scenario DECOMP-05b: Extract listing methods

- GIVEN `listCatalogi()` and `listZaaktypen()` each have CC+NPath suppressions
- WHEN the methods are decomposed
- THEN filter parsing, query building, and response formatting MUST be separate methods
- AND a shared `buildListResponse()` pattern MUST be reusable across all listing endpoints

#### Scenario DECOMP-05c: Extract sub-type creation handlers

- GIVEN `createStatusType()` and `createResultaatType()` have CC+NPath suppressions
- WHEN the methods are decomposed
- THEN `ZtcController/StatusTypeHandler.php` and `ZtcController/ResultaatTypeHandler.php` MUST be created
- AND each handler MUST follow the same validate-resolve-create-respond pattern

---

### REQ-DECOMP-06: BrcController and ZgwBrcRulesService Decomposition

The `BrcController` (9 suppressions) and `ZgwBrcRulesService` (12 suppressions) MUST be decomposed.

**Feature tier**: V1


#### Scenario DECOMP-06a: Extract besluit creation handler

- GIVEN `BrcController::createBesluit()` has CC+NPath+MethodLength suppressions
- WHEN the method is decomposed
- THEN a `BrcController/BesluitHandler.php` class MUST be created
- AND it MUST contain: `validateBesluitInput()`, `resolveBesluitType()`, `resolveZaakReference()`, `createBesluit()`, `buildBesluitResponse()`

#### Scenario DECOMP-06b: Extract besluit validation service

- GIVEN `ZgwBrcRulesService` has `validateBesluitCreate`, `validateBesluitUpdate`, `validateBesluitInformatieObject` with multiple suppressions
- WHEN the service is decomposed
- THEN `validateBesluitCreate()` MUST be split into: `validateRequiredFields()`, `validateBesluitTypeReference()`, `validateZaakReference()`, `validateDateFields()`
- AND `validateBesluitInformatieObject()` MUST use early returns to reduce NPath complexity

#### Scenario DECOMP-06c: Extract search method

- GIVEN `BrcController::searchBesluiten()` has CC suppression
- WHEN the method is decomposed
- THEN filter parsing MUST be extracted to a `parseSearchFilters()` method
- AND the query building MUST use the shared pattern from REQ-DECOMP-05b

---

### REQ-DECOMP-07: DrcController and ZgwDrcRulesService Decomposition

The `DrcController` (9 suppressions) and `ZgwDrcRulesService` (9 suppressions) MUST be decomposed.

**Feature tier**: V1


#### Scenario DECOMP-07a: Extract document creation handler

- GIVEN `DrcController::createDocument()` has CC+NPath suppressions
- WHEN the method is decomposed
- THEN a `DrcController/DocumentHandler.php` class MUST be created
- AND file upload handling, metadata validation, and response building MUST be separate methods

#### Scenario DECOMP-07b: Extract document validation

- GIVEN `ZgwDrcRulesService` has `validateDocumentCreate`, `validateDocumentUpdate`, `validateCrossRegisterReferences` with suppressions
- WHEN the service is decomposed
- THEN each validation method MUST use the guard clause pattern (early returns)
- AND file format validation, size validation, and metadata validation MUST be separate methods

#### Scenario DECOMP-07c: Extract cross-register reference validation

- GIVEN `validateCrossRegisterReferences()` has CC suppression
- WHEN the method is decomposed
- THEN the `ZgwReferenceResolver` from REQ-DECOMP-04c MUST be reused
- AND document-specific reference patterns (informatieobjecttype, zaak) MUST be separate resolver methods

---

### REQ-DECOMP-08: Shared Business Rules Decomposition

The `ZgwBusinessRulesService` (6 suppressions) and `ZgwRulesBase` (4 suppressions) MUST be decomposed.

**Feature tier**: V1


#### Scenario DECOMP-08a: Extract pagination validation

- GIVEN `validatePagination()` has CC+NPath suppressions
- WHEN the method is decomposed
- THEN it MUST be split into: `validatePageNumber()`, `validatePageSize()`, `validateSortField()`, `buildPaginationResponse()`
- AND `ZgwPaginationHelper` (already exists) SHOULD absorb the common validation logic

#### Scenario DECOMP-08b: Extract date and URL field validation

- GIVEN `validateDateFields()` has CC+NPath and `validateUrlFields()` has CC suppressions
- WHEN the methods are decomposed
- THEN a `FieldValidator` utility MUST be created with: `validateDateFormat()`, `validateDateRange()`, `validateUrl()`, `validateUrlReachability()`
- AND the validators MUST be reusable across all rules services

#### Scenario DECOMP-08c: Reduce ZgwRulesBase coupling

- GIVEN `ZgwRulesBase` has CouplingBetweenObjects, ClassComplexity, TooManyMethods, and CC suppressions
- WHEN the base class is decomposed
- THEN helper methods MUST be moved to dedicated utility classes (`FieldValidator`, `ZgwReferenceResolver`)
- AND the base class MUST contain only shared infrastructure (error handling, logging, common type resolution)

---

### REQ-DECOMP-09: AcController Decomposition

The `AcController` (5 suppressions) MUST be decomposed.

**Feature tier**: V2


#### Scenario DECOMP-09a: Extract autorisatie creation handler

- GIVEN `AcController::createAutorisatie()` has CC+NPath suppressions
- WHEN the method is decomposed
- THEN a `AcController/AutorisatieHandler.php` class MUST be created
- AND scope validation, client verification, and autorisatie creation MUST be separate methods

#### Scenario DECOMP-09b: Reduce class complexity

- GIVEN `AcController` has ClassComplexity, CC, and NPath class-level suppressions
- WHEN the handler is extracted
- THEN the controller MUST only contain route handlers that delegate to the handler class
- AND the class complexity MUST drop below the PHPMD threshold

#### Scenario DECOMP-09c: Scope validation extraction

- GIVEN autorisatie scope validation involves checking multiple ZGW API scopes against permissions
- WHEN the validation is extracted
- THEN a `ScopeValidator` MUST check: scope format, scope existence, scope combination rules
- AND unknown scopes MUST produce descriptive error messages

---

### REQ-DECOMP-10: LoadDefaultZgwMappings Decomposition

The `LoadDefaultZgwMappings` repair step (4 suppressions) MUST be decomposed.

**Feature tier**: V2


#### Scenario DECOMP-10a: Extract mapping loaders

- GIVEN `LoadDefaultZgwMappings` has ClassLength, MethodLength (2x), and CC suppressions
- WHEN the repair step is decomposed
- THEN separate methods MUST be created for each mapping category: `loadZaakMappings()`, `loadDocumentMappings()`, `loadBesluitMappings()`, `loadCatalogiMappings()`
- AND each method MUST be <=50 lines

#### Scenario DECOMP-10b: Extract mapping data to configuration files

- GIVEN the repair step contains hardcoded mapping arrays that cause ExcessiveClassLength
- WHEN the data is extracted
- THEN mapping definitions MUST be moved to JSON configuration files in `lib/Settings/zgw_mappings/`
- AND the repair step MUST load and parse these files instead of containing inline arrays
- AND the class length MUST drop below 1000 lines

#### Scenario DECOMP-10c: Idempotent mapping updates

- GIVEN the repair step runs on every app update
- WHEN mapping data is loaded from JSON files
- THEN existing mappings MUST be updated (not duplicated) using a mapping identifier as the unique key
- AND a version field in the JSON files MUST trigger re-import only when the version changes

---

### REQ-DECOMP-11: Remaining Single-Suppression Files

Files with single suppressions MUST be addressed by reducing coupling or class length.

**Feature tier**: V2


#### Scenario DECOMP-11a: ZgwMappingService class length reduction

- GIVEN `ZgwMappingService` has ExcessiveClassLength suppression
- WHEN the service is decomposed
- THEN mapping transformation logic MUST be extracted to a `MappingTransformService`
- AND the remaining service MUST be <=1000 lines

#### Scenario DECOMP-11b: Reduce coupling in utility services

- GIVEN `ZgwDocumentService`, `NotificatieService`, and `ZgwAuthMiddleware` each have CouplingBetweenObjects suppressions
- WHEN the services are refactored
- THEN rarely-used dependencies MUST be lazy-loaded via `IServerContainer::get()`
- OR related dependencies MUST be grouped into a composite service

#### Scenario DECOMP-11c: Verify no new violations

- GIVEN all decompositions are complete
- WHEN `composer check:strict` is run
- THEN 0 PHPMD violations MUST be reported
- AND 0 new PHPCS violations MUST be introduced
- AND 0 new Psalm/PHPStan issues MUST be introduced

---

## Files Requiring Decomposition

### Priority 1 -- Highest complexity (files with 5+ suppressions)

**lib/Controller/ZrcController.php** (22 suppressions)
ZGW Zaken (cases) REST controller handling CRUD operations for zaken, zaakobjecten, rollen, resultaten, statussen, and klantcontacten. Class-level suppressions (7) for coupling, class length, class complexity, method length, cyclomatic complexity, NPath, and TooManyMethods. Method-level suppressions on `createZaakObject` (CC+NPath), `createRol` (CC+NPath), `createResultaat` (CC), `createStatus` (CC+NPath+MethodLength), `updateZaak` (CC+NPath+MethodLength), `listZaken` (CC+NPath+MethodLength), and `searchZaken` (CC).

**lib/Service/ZgwService.php** (19 suppressions)
Core ZGW service orchestrating zaak creation, JWT validation, sub-resource lookups, and API proxying. Class-level suppressions (4) for coupling, class length, class complexity, and TooManyMethods. Method-level suppressions on `validateJwtToken` (CC+NPath), `validateJwtSignature` (CC), `createZaak` (MethodLength), `handleSubResourceList` (CC+NPath+MethodLength), plus 4 sub-resource lookup methods each with CC+NPath (`lookupZaakObjecten`, `lookupRollen`, `lookupStatussen`, `lookupResultaten`).

**lib/Service/ZgwZrcRulesService.php** (17 suppressions)
ZGW Zaken Registry Component business rules validation. Class-level suppressions (7) for coupling, class complexity (2x), TooManyMethods, CC, NPath, and class length. Method-level suppressions on `validateCreateZaak` (CC), `validateStatusTransition` (NPath), `validateRolCreate` (CC+NPath), `handleZaakStatusUpdate` (CC+NPath+MethodLength), `validateZaakUpdate` (CC+NPath), and `validateImmutability` (CC).

**lib/Service/ZgwZtcRulesService.php** (16 suppressions)
ZGW Zaaktype Catalogus business rules validation. Class-level suppressions (6) for coupling, class complexity (2x), TooManyMethods, CC, and NPath. Method-level suppressions on `validateZaaktypeCreate` (CC+NPath), `validateStatusTypeCreate` (CC+NPath), `validateResultaatTypeCreate` (CC+NPath), `resolveZaaktypeReference` (CC), `validateEigenschapCreate` (CC+NPath), `validateInformatieObjectTypeCreate` (CC+NPath), and `resolveNestedObjectReferences` (CC+NPath).

**lib/Controller/ZtcController.php** (16 suppressions)
ZGW Zaaktype Catalogus REST controller. Class-level suppressions (5) for coupling, class length, class complexity, CC, and NPath. Method-level suppressions on `createStatusType` (CC+NPath), `createResultaatType` (CC+NPath), `createInformatieObjectType` (CC+NPath+MethodLength), `listCatalogi` (CC+NPath), and `listZaaktypen` (CC+NPath).

**lib/Service/ZgwBrcRulesService.php** (12 suppressions)
ZGW Besluiten (decisions) Registry Component business rules. Class-level suppressions (6) for coupling, class complexity (2x), TooManyMethods, CC, and NPath. Method-level suppressions on `validateBesluitCreate` (CC), `validateBesluitUpdate` (CC+NPath), and `validateBesluitInformatieObject` (CC+NPath+MethodLength).

**lib/Controller/DrcController.php** (9 suppressions)
ZGW Documenten (documents) Registry controller. Class-level suppressions (7) for coupling, class complexity, TooManyMethods, class length, method length, CC, and NPath. Method-level suppressions on `createDocument` (CC+NPath).

**lib/Controller/BrcController.php** (9 suppressions)
ZGW Besluiten (decisions) Registry controller. Class-level suppressions (5) for coupling, class length, class complexity, CC, and NPath. Method-level suppressions on `createBesluit` (CC+NPath+MethodLength) and `searchBesluiten` (CC).

**lib/Service/ZgwDrcRulesService.php** (9 suppressions)
ZGW Documenten Registry Component business rules. Class-level suppressions (4) for coupling, class complexity (2x), and TooManyMethods. Method-level suppressions on `validateDocumentCreate` (CC+NPath), `validateDocumentUpdate` (CC+NPath), and `validateCrossRegisterReferences` (CC).

**lib/Service/ZgwBusinessRulesService.php** (6 suppressions)
Shared ZGW business rules service. Class-level suppression for coupling. Method-level suppressions on `validatePagination` (CC+NPath), `validateDateFields` (CC+NPath), and `validateUrlFields` (CC).

**lib/Controller/AcController.php** (5 suppressions)
ZGW Autorisaties (authorizations) controller. Class-level suppressions (3) for class complexity, CC, and NPath. Method-level suppressions on `createAutorisatie` (CC+NPath).

### Priority 2 -- Medium complexity (files with 2-4 suppressions)

- `lib/Service/ZgwRulesBase.php` (4) -- Base class for all ZGW rules services with coupling, class complexity, TooManyMethods, and a CC suppression
- `lib/Repair/LoadDefaultZgwMappings.php` (4) -- Repair step loading default ZGW mappings with class length, method length (2x), and CC

### Priority 3 -- Single suppressions

- `lib/Service/ZgwMappingService.php` (1) -- ExcessiveClassLength
- `lib/Service/ZgwDocumentService.php` (1) -- CouplingBetweenObjects
- `lib/Service/NotificatieService.php` (1) -- CouplingBetweenObjects
- `lib/Middleware/ZgwAuthMiddleware.php` (1) -- CouplingBetweenObjects

## Decomposition Strategy

### For CyclomaticComplexity (>10 branches)
Extract conditional branches into private helper methods:
- Guard clauses: Extract early-return validation into `validate{Thing}()` methods
- Switch-like logic: Extract case handlers into `handle{Case}()` methods
- Nested conditions: Flatten by extracting inner blocks into descriptive methods

### For NPathComplexity (>200 paths)
Reduce execution paths by:
- Breaking method into pipeline stages (each stage = private method)
- Extracting independent conditional blocks into separate methods
- Using early returns to eliminate nested paths

### For ExcessiveMethodLength (>100 lines)
Split long methods into logical phases:
- Validation phase -> `validate{Input}()`
- Preparation phase -> `prepare{Data}()`
- Processing phase -> `process{Thing}()`
- Response phase -> `build{Response}()`

### For ExcessiveClassComplexity / ExcessiveClassLength
Extract method groups into Handler classes (existing pattern in codebase):
- Create `{ClassName}/{HandlerName}Handler.php`
- Move related methods to the handler
- Inject handler via constructor
- Delegate from original methods (keep public API stable)

### For CouplingBetweenObjects (>13 dependencies)
Reduce constructor parameters by:
- Grouping related dependencies into a single service
- Using lazy loading for rarely-used dependencies
- Moving methods that use specific deps to handler classes

## Testing Strategy

### Before decomposition
1. Run existing unit tests: `docker exec -w /var/www/html/custom_apps/procest nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
2. Note any pre-existing failures
3. Run PHPMD to record current suppression count: `./vendor/bin/phpmd lib/ text phpmd.xml 2>&1 | wc -l`

### During decomposition (per method)
1. Verify `php -l` passes on all changed files
2. Run unit tests for the specific class: `--filter ClassName`
3. Run PHPMD on the specific file to confirm suppression can be removed

### After decomposition
1. Full unit test suite passes
2. PHPMD reports 0 violations (no new warnings)
3. Total suppression count reduced by expected amount
4. `composer check:strict` passes
5. Manual smoke test in browser (http://localhost:3000)

## Acceptance Criteria
- [ ] All CyclomaticComplexity suppressions eliminated or reduced to <=5
- [ ] All NPathComplexity suppressions eliminated or reduced to <=5
- [ ] All ExcessiveMethodLength suppressions eliminated or reduced to <=5
- [ ] ExcessiveClassComplexity reduced by extracting handler classes
- [ ] No new PHPMD violations introduced
- [ ] All existing tests continue to pass
- [ ] No behavioral changes (pure refactoring)

## Dependencies

- **PHPMD**: PHP Mess Detector for complexity analysis
- **PHPCS**: PHP CodeSniffer for PSR-12 compliance (new extracted classes must comply)
- **Psalm/PHPStan**: Static analysis must pass on all new files
- **PHPUnit**: All existing tests must pass without modification
- **OpenRegister**: No changes to OpenRegister -- all decomposition is internal to Procest

## Standards & References

- **PHPMD rules**: `phpmd.xml` in Procest root defines thresholds (CC=10, NPath=200, MethodLength=100, ClassLength=1000)
- **PSR-12**: PHP coding style standard (enforced by PHPCS)
- **ISO 25010**: Software quality model -- maintainability, testability, modularity sub-characteristics
- **Clean Code (Robert C. Martin)**: Single Responsibility Principle, method length guidelines
- **Refactoring (Martin Fowler)**: Extract Method, Extract Class, Replace Conditional with Polymorphism patterns
