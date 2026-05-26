# Method Decomposition — Procest

## Why

Procest has accumulated 152 PHPMD complexity suppressions across 12 files, with 95 in Priority 1 (5+ suppressions each). These suppressions hide maintainability issues that hinder code quality audits and make Dutch government tender compliance (ISO 25010 software quality) harder to demonstrate.

### Business Driver
Dutch government procurement increasingly requires documented code quality metrics. ISO 25010 compliance requires demonstrable maintainability via clean code metrics. Removing PHPMD suppressions proves we meet those standards without resorting to suppression pragmatism.

### Technical Driver
Complex methods are harder to test, review, and debug. Each suppression represents a method or class that exceeds PHPMD thresholds:
- **CyclomaticComplexity >10** (53 suppressions) — methods with >10 conditional branches
- **NPathComplexity >200** (41 suppressions) — methods with >200 execution paths
- **ExcessiveMethodLength >100 lines** (13 suppressions)
- **ExcessiveClassComplexity** (15 suppressions)
- **ExcessiveClassLength >1000 lines** (8 suppressions)
- **CouplingBetweenObjects >13** (14 suppressions)
- **TooManyMethods** (8 suppressions)

### Standards
- **PHPMD** — PHP Mess Detector for complexity analysis
- **PHPCS** (PSR-12) — PHP CodeSniffer for style compliance
- **Psalm / PHPStan** — Static analysis
- **ISO 25010** — Software quality model (maintainability, testability, modularity)

## What Changes

A pure refactoring decomposing complex methods into smaller, focused handler/service classes with **zero behavioral changes**:

### Controllers → Handler Classes
Extract method groups from controllers into handler classes injected via constructor:
- **ZrcController** (22 suppressions) → `ZaakObjectHandler`, `RolHandler`, `StatusHandler`, `ResultaatHandler`
- **ZtcController** (16 suppressions) → `InformatieObjectTypeHandler`, `StatusTypeHandler`, `ResultaatTypeHandler`
- **BrcController** (9 suppressions) → `BesluitHandler`
- **DrcController** (9 suppressions) → `DocumentHandler`
- **AcController** (5 suppressions; V2) → `AutorisatieHandler`

### Services → Utility Services (Shared)
Create new services for validation, resolution, and transformation (shared across multiple rules services):
- **`JwtValidationService`** — JWT token validation pipeline
- **`ZgwSubResourceResolver`** — Generic sub-resource lookup (zaakobjecten, rollen, statussen, resultaten)
- **`ZgwReferenceResolver`** — URL reference resolution with circular-reference detection
- **`StatusTransitionValidator`** — Status transition validation with configurable rules matrix
- **`FieldValidator`** — Date, URL, and field-format validation (reusable)
- **`ScopeValidator`** — Autorisatie scope validation (V2)
- **`MappingTransformService`** — Mapping transformation logic (V2)

### Services → Refactored Validation
Break down monolithic validation methods in rules services:
- **ZgwZrcRulesService** (17 suppressions) — split into `validateCreateZaak()`, `StatusTransitionValidator`, `ImmutabilityChecker`
- **ZgwZtcRulesService** (16 suppressions) — split into `ZaaktypeValidator`, reuse `ZgwReferenceResolver`
- **ZgwBrcRulesService** (12 suppressions) — early-return guards in validation methods
- **ZgwDrcRulesService** (9 suppressions) — reuse `ZgwReferenceResolver` for cross-register validation
- **ZgwService** (19 suppressions) — extract JWT validation, sub-resource lookup into services
- **ZgwBusinessRulesService** (6 suppressions) — split pagination and field validation
- **ZgwRulesBase** (4 suppressions; V2) — move helpers to utility classes

### Repair Steps → Configuration Files
Extract hardcoded mapping data to JSON configuration:
- **LoadDefaultZgwMappings** (4 suppressions; V2) — move mapping arrays to `lib/Settings/zgw_mappings/*.json`

### Single-Suppression Files
Reduce coupling via lazy loading or service grouping:
- **ZgwMappingService** (1 suppression; V2) → extract `MappingTransformService`
- **ZgwDocumentService** (1 suppression; V2) → lazy-load rarely-used dependencies
- **NotificatieService** (1 suppression; V2) → lazy-load rarely-used dependencies
- **ZgwAuthMiddleware** (1 suppression; V2) → lazy-load rarely-used dependencies

## Scope

### In Scope (V1 — 95 suppressions)
- **10 files with 5+ suppressions each:** ZrcController, ZgwService, ZgwZrcRulesService, ZgwZtcRulesService, ZtcController, ZgwBrcRulesService, BrcController, DrcController, ZgwDrcRulesService, ZgwBusinessRulesService
- All CyclomaticComplexity suppressions (53)
- All NPathComplexity suppressions (41)
- All ExcessiveMethodLength suppressions (13)
- 75%+ of ExcessiveClassComplexity/ClassLength suppressions

### V2 Bonus (57 suppressions)
- Priority 2 files with 2–4 suppressions (AcController, ZgwRulesBase, LoadDefaultZgwMappings)
- Priority 3 single-suppression files with coupling issues

### Out of Scope
- Refactoring unrelated files
- Behavioral changes to any public API
- Database schema changes
- OpenRegister integration modifications

## Approach

### Phase 1: Priority 1 Controllers (40 suppressions)
Extract method groups from ZrcController, ZtcController, BrcController, DrcController into handler classes. Keep public API stable.

### Phase 2: Priority 1 Services (66 suppressions)
Create shared utility services (JwtValidationService, ZgwSubResourceResolver, ZgwReferenceResolver). Decompose rules services with guard clauses and early returns.

### Phase 3: Shared Services
Create FieldValidator, StatusTransitionValidator, ScopeValidator (V2), MappingTransformService (V2).

### Phase 4: Repair Steps & Single-Suppression Files (V2)
Extract mapping data to JSON configs. Lazy-load dependencies.

### Phase 5: Verification
Run phpunit, PHPMD, PHPCS, Psalm, PHPStan. Smoke test in browser.

## Impact

### Code
- **New files** — 7 new handler classes + 5 new utility services + JSON config files
- **Modified files** — 12 controller/service files decomposed
- **Deleted files** — None (refactoring only)
- **Public API** — Controller method signatures unchanged (backwards-compatible)

### Dependencies
- **OpenRegister** — No changes (all refactoring is internal to Procest)
- **Nextcloud platform APIs** — No changes
- **PHPMD, PHPCS, Psalm, PHPStan** — Existing versions; no library upgrades needed

### Testing
- **Unit tests** — All 152+ existing tests must pass without modification
- **Integration tests** — Case workflows (create, assign, status update, decision) unchanged
- **Smoke test** — Manual browser test of 5 key journeys

## Acceptance Criteria

- [ ] All 152 suppressions eliminated or reduced to ≤5 (per-file)
- [ ] CyclomaticComplexity suppressions: 0 new violations
- [ ] NPathComplexity suppressions: 0 new violations
- [ ] ExcessiveMethodLength suppressions: 0 new violations
- [ ] All existing tests pass (zero behavioral changes)
- [ ] `composer check:strict` passes (0 PHPMD, 0 PHPCS, 0 Psalm, 0 PHPStan violations)
- [ ] Manual smoke test in browser passes (cases, tasks, decisions workflows)

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Handler class injection increases constructor parameters | Extract into composite services (e.g., ZrcHandlers bundle) |
| Test modifications required | Strict policy: NO test changes, only refactoring the implementation |
| Performance regression from extra layer of indirection | Handler classes are thin wrappers; use profiling to confirm |
| Missed suppression categories | Run PHPMD after each phase; track suppression counts in commits |
