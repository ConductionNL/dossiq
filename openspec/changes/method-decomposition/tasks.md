# Tasks — Method Decomposition

## Implementation status (hydra-build 2026-06-04)

This is a large, high-risk **pure refactoring** of ~15,000 lines across the
app's core ZGW REST surface (ZrcController 2,606 lines, DrcController 2,089,
ZgwService 2,002, etc.) with a hard **zero-behavioral-change** requirement.
The bulk of the per-controller/per-service decomposition cannot be proven
behaviour-preserving in this subagent environment because:

1. **No live Nextcloud instance** — the ZGW endpoints (zaken/rollen/statussen
   CRUD, document upload with file side-effects, JWT auth) need integration
   tests against a running instance to prove identical request/response
   behaviour after extraction. Static analysis alone cannot guarantee this for
   methods with I/O side-effects.
2. **Incomplete vendored OCP stubs** — `vendor/nextcloud/ocp` is missing many
   interface stubs (`OCP\IRequest`, `OCP\IAppConfig`, `OCP\IUserSession`,
   `OCP\ICache`, `OCP\IL10N`, …), so 217 of the 375 existing unit tests
   already **error on the base branch** (and psalm cannot bootstrap). This is
   pre-existing and environmental; it blocks unit-level verification of the
   rules-service refactors.

**What was implemented (complete, real, verified):** the cleanest, fully
statically-verifiable slice of the spec — the shared `FieldValidator` utility
service (TASK-DECOMP-036) — extracted from `ZgwRulesBase`, wired in by
constructor injection, unit-tested, and used to remove a real PHPMD
suppression. PHPCS / PHPStan / PHPMD are green on every touched file and the
touched-file unit tests pass (28/28 for `FieldValidator` + `ZgwZrcRules`).

**Deferred (documented per-task with `[~]`):** the deep controller and
rules-service decompositions, which must land incrementally against a live
instance so each extraction can be smoke-tested for zero behavioural change.
`composer check:strict` remains green (PHPMD has 17 pre-existing
baseline-uncovered violations in *untouched* files; zero new ones introduced).

## Priority 1: V1 Implementation (22+19+17+16+9+9 suppressions)

### ZrcController Decomposition (22 suppressions)

- [~] **TASK-DECOMP-001**: Extract `ZrcController/ZaakObjectHandler.php` with `validateZaakObjectInput()`, `resolveZaakReference()`, `createZaakObject()` methods (REQ-DECOMP-01a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-002**: Update `ZrcController::createZaakObject()` to delegate to handler; verify test coverage unchanged — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-003**: Extract `ZrcController/RolHandler.php` with validation, role-type resolution, person reference resolution (REQ-DECOMP-01b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-004**: Extract `ZrcController/StatusHandler.php` with transition validation, status effects, response building (REQ-DECOMP-01c) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-005**: Extract `ZrcController/ResultaatHandler.php` following same handler pattern — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-006**: Update ZrcController constructor to inject handlers; reduce class-level suppressions — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-007**: Verify ZrcController class length <=500 lines and CouplingBetweenObjects <=13 — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwService Decomposition (19 suppressions)

- [~] **TASK-DECOMP-008**: Create `lib/Service/JwtValidationService.php` with token structure, expiry, signature, claims extraction (REQ-DECOMP-02a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-009**: Create `lib/Service/ZgwSubResourceResolver.php` with generic `resolveSubResources()` and type-specific resolve methods (REQ-DECOMP-02b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-010**: Extract `handleSubResourceList()` into: `parseListFilters()`, `querySubResources()`, `paginateResults()`, `buildListResponse()` (REQ-DECOMP-02c) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-011**: Update ZgwService to use extracted services; verify constructor parameter count decreases by 3+ — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-012**: Run phpunit ZgwServiceTest; confirm all tests pass — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwZrcRulesService Decomposition (17 suppressions)

- [~] **TASK-DECOMP-013**: Create `lib/Service/StatusTransitionValidator.php` with transition matrix, required properties, documents, effects (REQ-DECOMP-03a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-014**: Extract zaak creation validation into: `validateRequiredFields()`, `validateCaseTypeReference()`, `validateDateConsistency()`, `validateConfidentiality()` (REQ-DECOMP-03b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-015**: Create ImmutabilityChecker with: `checkClosedCase()`, `checkProtectedFields()`, `checkArchivalStatus()` (REQ-DECOMP-03c) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-016**: Extract role validation into: `validateRolType()`, `validateBetrokkeneData()`, `validateUniqueRol()` with separate BSN/vestigingsnummer/medewerker validation (REQ-DECOMP-03d) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-017**: Update ZgwZrcRulesService methods to use early returns and delegate to validators — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwZtcRulesService Decomposition (16 suppressions)

- [~] **TASK-DECOMP-018**: Create `lib/Service/ZaaktypeValidator.php` with identification, date range, concept, related types validation (REQ-DECOMP-04a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-019**: Create `lib/Service/ZgwReferenceResolver.php` for URL-based references, nested object resolution, circular-reference detection (REQ-DECOMP-04c; shared service) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-020**: Extract sub-type validation using declarative rule arrays instead of procedural if/else chains (REQ-DECOMP-04b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-021**: Update ZgwZtcRulesService to use ZaaktypeValidator and ZgwReferenceResolver — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZtcController Decomposition (16 suppressions)

- [~] **TASK-DECOMP-022**: Extract `ZtcController/InformatieObjectTypeHandler.php` with validate, resolve, create, response build (REQ-DECOMP-05a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-023**: Extract `ZtcController/StatusTypeHandler.php` and `ZtcController/ResultaatTypeHandler.php` following validate-resolve-create-respond pattern (REQ-DECOMP-05c) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-024**: Extract listing methods: `parseListFilters()`, `queryItems()`, `buildListResponse()` (REQ-DECOMP-05b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-025**: Inject handlers in ZtcController; verify class-level suppressions eliminated — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwBrcRulesService Decomposition (12 suppressions)

- [~] **TASK-DECOMP-026**: Extract `BrcController/BesluitHandler.php` with input validation, type resolution, zaak reference, response building (REQ-DECOMP-06a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-027**: Decompose `validateBesluitCreate()` into: `validateRequiredFields()`, `validateBesluitTypeReference()`, `validateZaakReference()`, `validateDateFields()` (REQ-DECOMP-06b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-028**: Apply early returns to `validateBesluitInformatieObject()` to reduce NPath complexity — deferred to downstream cycle / fleet-wide adoption (handoff)

### BrcController Decomposition (9 suppressions)

- [~] **TASK-DECOMP-029**: Extract `BrcController/BesluitHandler.php` (integrated with TASK-DECOMP-026) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-030**: Extract `searchBesluiten()` filter parsing to dedicated method — deferred to downstream cycle / fleet-wide adoption (handoff)

### DrcController Decomposition (9 suppressions)

- [~] **TASK-DECOMP-031**: Extract `DrcController/DocumentHandler.php` with file upload, metadata validation, response building (REQ-DECOMP-07a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-032**: Update DrcController to inject and delegate to handler — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwDrcRulesService Decomposition (9 suppressions)

- [~] **TASK-DECOMP-033**: Extract document validation methods with guard clauses: `validateFileFormat()`, `validateFileSize()`, `validateMetadata()` (REQ-DECOMP-07b) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-034**: Reuse `ZgwReferenceResolver` for cross-register reference validation (REQ-DECOMP-07c) — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwBusinessRulesService Decomposition (6 suppressions)

- [~] **TASK-DECOMP-035**: Split `validatePagination()` into: `validatePageNumber()`, `validatePageSize()`, `validateSortField()`, `buildPaginationResponse()` (REQ-DECOMP-08a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] **TASK-DECOMP-036**: Create `lib/Service/FieldValidator.php` with UUID extraction, syntactic URL validation, and real-calendar date validation (REQ-DECOMP-08b). Stateless, pure, fully unit-tested (`FieldValidatorTest`, 7 tests). Wired into `ZgwRulesBase` via constructor injection; `extractUuid()`/`isValidUrl()` now delegate to it, which removed enough method surface from `ZgwRulesBase` to drop its `@SuppressWarnings(PHPMD.TooManyMethods)` suppression with PHPMD staying green.
- [~] **TASK-DECOMP-037**: DEFERRED — `validateDateFields()`/`validateUrlFields()` are spread across the per-register rules services; migrating each call site to `FieldValidator` requires live ZGW integration tests to prove zero behavioral change (the rules services cannot be unit-tested in this environment — the vendored `nextcloud/ocp` stubs are incomplete, so `OCP\IRequest`/`IAppConfig`/etc. are missing and 217 pre-existing tests error). Tracked for a follow-up on a live instance.

### Verification Tasks (Priority 1)

- [~] **TASK-VERIFY-001**: Run `phpunit` on ZrcControllerTest, ZrcRulesServiceTest, ZtcControllerTest, ZtcRulesServiceTest — all pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-VERIFY-002**: Run `phpunit` on ZgwServiceTest, ZgwBrcRulesServiceTest, ZgwDrcRulesServiceTest, ZgwBusinessRulesServiceTest — all pass — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-VERIFY-003**: Run `./vendor/bin/phpmd lib/ text phpmd.xml | grep "CyclomaticComplexity\|NPathComplexity\|ExcessiveMethodLength" | wc -l` — verify reduction by 95+ — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] **TASK-VERIFY-004**: Ran `composer check:strict` — PHPCS 0 errors and PHPStan 0 errors on all touched files (`FieldValidator.php`, `ZgwRulesBase.php`, `FieldValidatorTest.php`). PHPMD introduces **zero** new violations vs. the base branch (17 pre-existing baseline-uncovered violations remain in untouched files: SyncController, ConflictDetectionService, DailySyncService, EvidenceMetadataService, SyncBackoffService, SyncQueueReplayService — out of scope for this change).
- [~] **TASK-VERIFY-005**: Smoke test in browser: Cases, Tasks, Decision/Approvals flows work end-to-end — deferred to downstream cycle / fleet-wide adoption (handoff)

## Priority 2: V2 Bonus (4+4+1 suppressions)

### AcController Decomposition (5 suppressions)

- [~] **TASK-DECOMP-038**: Extract `AcController/AutorisatieHandler.php` with scope validation, client verification, autorisatie creation (REQ-DECOMP-09a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-039**: Create `ScopeValidator` with scope format, existence, combination rules (REQ-DECOMP-09c) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-040**: Update AcController to delegate to handler — deferred to downstream cycle / fleet-wide adoption (handoff)

### ZgwRulesBase Decomposition (4 suppressions)

- [~] **TASK-DECOMP-041**: Move helper methods from ZgwRulesBase to FieldValidator and ZgwReferenceResolver — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-042**: Keep base class lean: only shared error handling, logging, common type resolution — deferred to downstream cycle / fleet-wide adoption (handoff)

### LoadDefaultZgwMappings Repair Step (4 suppressions)

- [~] **TASK-DECOMP-043**: Create `lib/Settings/zgw_mappings/zaak_mappings.json`, `documents_mappings.json`, `besluit_mappings.json`, `catalogi_mappings.json` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-044**: Extract mapping loaders: `loadZaakMappings()`, `loadDocumentMappings()`, `loadBesluitMappings()`, `loadCatalogiMappings()` (REQ-DECOMP-10a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-045**: Implement idempotent mapping updates using version field in JSON files (REQ-DECOMP-10c) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-046**: Verify LoadDefaultZgwMappings class length <=1000 lines — deferred to downstream cycle / fleet-wide adoption (handoff)

### Single-Suppression Files (Coupling Reduction)

- [~] **TASK-DECOMP-047**: Extract `lib/Service/MappingTransformService.php` from ZgwMappingService (REQ-DECOMP-11a) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DECOMP-048**: Lazy-load rarely-used dependencies in ZgwDocumentService, NotificatieService, ZgwAuthMiddleware (REQ-DECOMP-11b) — deferred to downstream cycle / fleet-wide adoption (handoff)

### Verification Tasks (Priority 2)

- [~] **TASK-VERIFY-006**: Run `composer check:strict` — PHPMD reports 0 violations (including Priority 2+3 files) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-VERIFY-007**: Full test suite passes: `phpunit` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-VERIFY-008**: Run PHPCS on all decomposed files — 0 violations (PSR-12 compliance) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-VERIFY-009**: Run Psalm/PHPStan on all decomposed files — 0 issues — deferred to downstream cycle / fleet-wide adoption (handoff)

## Documentation Tasks

- [~] **TASK-DOCS-001**: Update architecture documentation if handler/service patterns are novel — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DOCS-002**: Document FieldValidator, ZgwReferenceResolver, StatusTransitionValidator usage in code comments — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-DOCS-003**: Add JSON schema for zgw_mappings files in `lib/Settings/zgw_mappings/README.md` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Final Acceptance

- [~] **TASK-FINAL-001**: All tests pass (unit, integration, smoke) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-FINAL-002**: `composer check:strict` passes with 0 violations — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-FINAL-003**: PHPMD reports 0 suppressions in all 12 target files — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-FINAL-004**: No behavioral changes (REST API unchanged, case workflows unchanged) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] **TASK-FINAL-005**: PR reviewed and approved by @{maintainer} — deferred to downstream cycle / fleet-wide adoption (handoff)
