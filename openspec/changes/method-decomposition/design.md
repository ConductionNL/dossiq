# Design — Method Decomposition

## Approach

Decompose 152 PHPMD complexity suppressions across 12 files by extracting complex methods into smaller, focused handler/service classes. The refactoring follows existing Procest patterns:

- **Controllers (ZrcController, ZtcController, BrcController, DrcController, AcController):** Extract method groups into handler classes (e.g., `ZrcController/ZaakObjectHandler.php`), delegating from the original public API methods
- **Services (ZgwService, ZgwZrcRulesService, ZgwZtcRulesService, ZgwBrcRulesService, ZgwDrcRulesService, ZgwBusinessRulesService, ZgwRulesBase, ZgwMappingService):** Extract validation, resolution, and transformation logic into dedicated utility services
- **Repair steps (LoadDefaultZgwMappings):** Extract mapping data to JSON configuration files and split methods by mapping category

## Decomposition Strategy

### For CyclomaticComplexity (>10 branches)
Extract conditional branches into private helper methods using guard clauses and early returns:
- `validateInput()` - early-return validation checks
- `handle{Case}()` - case-handler methods
- `resolve{Type}()` - type-specific resolution

### For NPathComplexity (>200 paths)
Break methods into pipeline stages, each as a private method:
- Stage 1: `validate{Input}()`
- Stage 2: `prepare{Data}()`
- Stage 3: `process{Thing}()`
- Stage 4: `build{Response}()`

### For ExcessiveMethodLength (>100 lines)
Split into logical phases with descriptive method names following the pipeline pattern above.

### For ExcessiveClassComplexity / ExcessiveClassLength
Extract method groups into handler classes injected via constructor, maintaining public API stability.

### For CouplingBetweenObjects (>13 dependencies)
Reduce constructor parameters by grouping related dependencies into composite services or lazy-loading rarely-used ones.

## Priority 1 Files (V1 - Implementation)

| File | Suppressions | Strategy |
|------|--------------|----------|
| `lib/Controller/ZrcController.php` | 22 | Extract handlers: `ZaakObjectHandler`, `RolHandler`, `StatusHandler`, `ResultaatHandler` |
| `lib/Service/ZgwService.php` | 19 | Extract services: `JwtValidationService`, `ZgwSubResourceResolver` |
| `lib/Service/ZgwZrcRulesService.php` | 17 | Extract: `StatusTransitionValidator`, immutability checks, validation methods |
| `lib/Service/ZgwZtcRulesService.php` | 16 | Extract: `ZaaktypeValidator`, `ZgwReferenceResolver` (shared across rules services) |
| `lib/Controller/ZtcController.php` | 16 | Extract handlers: `InformatieObjectTypeHandler`, `StatusTypeHandler`, `ResultaatTypeHandler` |
| `lib/Service/ZgwBrcRulesService.php` | 12 | Extract: validation methods with early returns, shared pattern from other rules services |
| `lib/Controller/BrcController.php` | 9 | Extract handler: `BesluitHandler` |
| `lib/Controller/DrcController.php` | 9 | Extract handler: `DocumentHandler` |
| `lib/Service/ZgwDrcRulesService.php` | 9 | Extract: document validation, reuse `ZgwReferenceResolver` |
| `lib/Service/ZgwBusinessRulesService.php` | 6 | Extract: pagination validation, field validators |

## Priority 2 Files (V2 - Bonus)

| File | Suppressions | Strategy |
|------|--------------|----------|
| `lib/Service/ZgwRulesBase.php` | 4 | Move helpers to utility classes, keep base class lean |
| `lib/Repair/LoadDefaultZgwMappings.php` | 4 | Extract mapping data to JSON config files in `lib/Settings/zgw_mappings/` |
| `lib/Service/ZgwMappingService.php` | 1 | Extract transformation logic to `MappingTransformService` |
| `lib/Service/ZgwDocumentService.php` | 1 | Lazy-load rarely-used dependencies |
| `lib/Service/NotificatieService.php` | 1 | Lazy-load rarely-used dependencies |
| `lib/Middleware/ZgwAuthMiddleware.php` | 1 | Lazy-load rarely-used dependencies |

## New Shared Services (To Be Created)

- **`lib/Service/JwtValidationService.php`** — JWT token validation pipeline (extracted from ZgwService)
- **`lib/Service/ZgwSubResourceResolver.php`** — Generic sub-resource lookup for zaakobjecten, rollen, statussen, resultaten
- **`lib/Service/ZgwReferenceResolver.php`** — URL reference resolution with circular-reference detection (shared across all rules services)
- **`lib/Service/StatusTransitionValidator.php`** — Status transition validation with configurable rules matrix
- **`lib/Service/FieldValidator.php`** — Utility for date, URL, and field-format validation
- **`lib/Service/MappingTransformService.php`** — Mapping data transformation (extracted from ZgwMappingService)
- **`lib/Settings/zgw_mappings/*.json`** — Mapping configuration files (zaak.json, documents.json, besluit.json, catalogi.json)

## Implementation Order

1. **Phase 1 (Priority 1 controllers):** ZrcController, ZtcController, BrcController, DrcController
2. **Phase 2 (Priority 1 services):** ZgwService, ZgwZrcRulesService, ZgwZtcRulesService
3. **Phase 3 (Priority 1 remaining):** ZgwBrcRulesService, ZgwDrcRulesService, ZgwBusinessRulesService
4. **Phase 4 (Priority 2):** AcController, ZgwRulesBase, LoadDefaultZgwMappings, utility services
5. **Phase 5 (Priority 3):** Single-suppression files with coupling/length issues

## Testing Strategy

### Before decomposition
- Run existing unit tests: `docker exec -w /var/www/html/custom_apps/procest nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
- Record current PHPMD suppression count

### Per-file decomposition
- Verify syntax: `php -l` on all changed files
- Run unit tests: `phpunit --filter {ClassName}Test`
- Run PHPMD on the file: `./vendor/bin/phpmd lib/ text phpmd.xml` (confirm suppression can be removed)

### After all decompositions
- Full unit test suite passes
- `composer check:strict` passes (PHPMD 0 violations, PHPCS 0 violations, Psalm/PHPStan 0 issues)
- Manual smoke test in browser

## Acceptance Criteria

- All CyclomaticComplexity suppressions eliminated or reduced to <=5
- All NPathComplexity suppressions eliminated or reduced to <=5
- All ExcessiveMethodLength suppressions eliminated or reduced to <=5
- ExcessiveClassComplexity suppression count reduced by 75%+
- No new PHPMD, PHPCS, Psalm, or PHPStan violations
- All existing tests pass without modification (zero behavioral changes)
- Existing public API unchanged (controller methods signatures stable)
