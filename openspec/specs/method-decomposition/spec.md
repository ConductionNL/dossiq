---
status: draft
priority: high
estimated_effort: large
---

# Method Decomposition — Procest

## Goal
Eliminate 152 PHPMD complexity suppressions by decomposing complex methods into smaller, focused units. Each suppression represents a method or class that exceeds PHPMD's strict thresholds (CC>10, NPath>200, MethodLength>100, ClassLength>1000).

## Current State
- **CyclomaticComplexity suppressions:** 53 (methods with >10 branches)
- **NPathComplexity suppressions:** 41 (methods with >200 execution paths)
- **ExcessiveMethodLength suppressions:** 13 (methods >100 lines)
- **ExcessiveClassComplexity suppressions:** 15 (classes with too much logic)
- **ExcessiveClassLength suppressions:** 8 (classes >1000 lines)
- **CouplingBetweenObjects suppressions:** 14 (too many dependencies)
- **TooManyMethods suppressions:** 8

## Files Requiring Decomposition

### Priority 1 — Highest complexity (files with 5+ suppressions)

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

### Priority 2 — Medium complexity (files with 2-4 suppressions)

- `lib/Service/ZgwRulesBase.php` (4) — Base class for all ZGW rules services with coupling, class complexity, TooManyMethods, and a CC suppression
- `lib/Repair/LoadDefaultZgwMappings.php` (4) — Repair step loading default ZGW mappings with class length, method length (2x), and CC

### Priority 3 — Single suppressions

- `lib/Service/ZgwMappingService.php` (1) — ExcessiveClassLength
- `lib/Service/ZgwDocumentService.php` (1) — CouplingBetweenObjects
- `lib/Service/NotificatieService.php` (1) — CouplingBetweenObjects
- `lib/Middleware/ZgwAuthMiddleware.php` (1) — CouplingBetweenObjects

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
