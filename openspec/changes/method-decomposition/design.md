# Design — Method Decomposition

## Approach

Decompose 152 PHPMD complexity suppressions across 12 files by extracting complex methods into smaller, focused handler/service classes. The refactoring follows existing Dossiq patterns:

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

## StatusTransitionService: two facades (slice 8h)

Measured on the stack head (12 Oct): `StatusTransitionService` couples to 18 classes against a limit of 13 and takes 14 constructor arguments against 10. It carries two suppressions for it, `CouplingBetweenObjects` and `ExcessiveParameterList`. Its complexity is not the problem: the earlier split into `Transitions/*` already took the work out. What is left is an engine that holds every one of those classes itself.

A move is not a design here. Folding two collaborators into a third to lower the count would put a concern back where it was split from, which is what the suppression says. The engine asks two kinds of question, so it gets two collaborators.

### What the engine asks

| The engine asks | Today it holds | After |
|---|---|---|
| What does this case declare about its status and its moves? | `StatusDeclarations`, `TransitionDeclarations`, `OfferedTransitions`, `StatusChecklist` | `Transitions/CaseDeclarations` |
| What does a move write, and what did earlier moves write? | `CaseStatusStore`, `CaseResultWriter`, `SideEffectDispatcher` | `Transitions/CaseMoveLedger` |
| May this person take this move? | `TransitionAuthorizer`, `GuardRegistry`, `TransitionSpecReader`, `ProcessOwnedStatusRule` | unchanged, the engine keeps them |
| Which workflow does the case run on, and who is asking? | `WorkflowTemplateLoader`, `IUserSession`, `LoggerInterface` | unchanged |

The engine keeps the decision: which transition, in which order the checks run, what is refused and what the caller is told. That order is the specification (REQ-STE-5-002, REQ-STE-12) and stays readable in one method.

### `Transitions/CaseDeclarations` (the read side)

One class over the four that read what an administrator declared. No write to the store goes through it.

| Method | Takes over | Answers |
|---|---|---|
| `panel(array $case): array` | `StatusDeclarations::panelFor` | `waitingOn`, `dwell`, `derivation` of the status the case is in |
| `offered(array $transitions, array $case, string $currentId, string $userId): array` | `OfferedTransitions::from` | `transitions` and `withheld` |
| `assertOpen(array $transition, array $case, string $caseId, string $userId): void` | `TransitionDeclarations::withheldReasons`, `fourEyesRefusal`, `refusalSentence` and the two refusals the engine throws on them | throws `RefusedException` `transition-dependency-open` or `transition-four-eyes`, in that order |
| `stamp(array $case, string $toStatus): array` | `StatusDeclarations::applyStatusChange` | the case payload with the dwell bookkeeping of the status it enters |
| `retime(string $caseId, string $toStatus): void` | `StatusDeclarations::retime` | nothing; stops and starts the status timers |
| `checklistActions(string $statusTypeId, array $case, string $actor): array` | `StatusChecklist::actionsFor` | the actions the entered status brings |

`stamp()` and `retime()` are on the read side on purpose. They change nothing in the store themselves: `stamp()` returns a payload the ledger saves, and `retime()` talks to the term engine. They belong with the class that knows what a status declares.

### `Transitions/CaseMoveLedger` (the write side)

One class over the three that touch the store or the outside world. It takes whole steps from the engine, not single calls, so it is not a pass-through.

| Method | Takes over | Notes |
|---|---|---|
| `load(string $caseId): ?array` | `CaseStatusStore::loadCase` | |
| `statusFacts(string $statusTypeId): array` | `lookupStatusName`, `lookupStatusColour`, `lookupStatusDescription` | one call, keys `statusName`, `statusColour`, `statusDescription` |
| `reloadUnchanged(string $caseId, int $readVersion, string $currentId): array` | the engine's `assertNoConcurrentChange()` | throws `case_not_found` or `RefusedException` `transition-conflict` |
| `saveMove(array $case, string $caseId, string $toStatus, ?string $resultTypeId, int $readVersion): array` | the store half of `writeMove()` and all of `applyClosingResult()` | closing result, end date, archival future, status and `@self.version` in ONE save; returns the saved case and its version |
| `saveFreeForm(array $case, string $toStatus): array` | `assertStatusBelongsToCaseType` and the save of `executeFreeForm()` | no closing result, as today |
| `recordAndDispatch(array $case, array $move, array $actions): array` | `writeStatusRecord`, `SideEffectDispatcher::dispatch`, the engine's `persistDispatchedActions()` | `$move` is `fromStatus`, `toStatus`, `label`, `comment`, `actor`, `evaluatedGuards`, `noWorkflowTemplate`; returns the record and the dispatched rows |
| `history(string $caseId): ?array` | `findStatusRecords` | `replay()` keeps the sort |

`saveMove()` takes the payload AFTER `CaseDeclarations::stamp()`. The engine calls `stamp()`, then `saveMove()`, then `retime()`: the same order as today, and the only place the two facades meet.

### The engine after the split

Constructor, nine arguments: `WorkflowTemplateLoader`, `GuardRegistry`, `TransitionAuthorizer`, `TransitionSpecReader`, `IUserSession`, `LoggerInterface`, `ProcessOwnedStatusRule`, `CaseDeclarations`, `CaseMoveLedger`. With `RefusedException`, `GuardFailedException` and `RuntimeException` it couples to 12 classes. Both suppressions go and `phpmd.suppressWarnings` in `.debt-baseline.json` goes down by 2.

The public methods and what they return do not change: `getAvailableTransitions()`, `execute()`, `executeFreeForm()`, `replay()`, `isAdmin()`, `ADMIN_GROUP_ID`. The container builds both facades by type, so no registration changes. Every class that injects the engine (32 files under `lib/`) is untouched.

The seven classes behind the facades keep their own constructors and their own tests. Other callers of them, such as `Lifecycle/CaseActionProvider` and `CaseDocumentGenerationService` on `CaseStatusStore`, keep calling them directly.

### What can go wrong

- **The order of the refusals.** `assertTransitionAllowed()` refuses in a fixed order: from-status, open dependency, four eyes, group, guards. A caller sees the first. `assertOpen()` holds the second and third and is called between the first and the fourth. A characterisation test with a transition that fails all five pins the order before the split.
- **One save, not two.** The closing result, the dwell bookkeeping and the status land in one save. `saveMove()` must not save before the stamp is on the payload. Pinned by asserting the single `saveCase` payload.
- **The timer after the save.** `retime()` runs after `saveMove()` returns and not when it throws.
- **Coverage metadata.** Every test that reaches the engine executes the two new classes. Each needs both in `@uses`, or CI marks it risky. No coverage driver runs locally by default: use the pcov image (`d179-logs/pcov.sh`) on `tests/Unit` before the push.
- **Whole-tree PHPMD only.** Coupling does not show in a per-file run. Measure with a fresh cache (`HOME` and `TMPDIR` on empty directories).

### Characterisation first

Five test classes already cover the engine through the real collaborators: the group gate, the closing result, failed actions, replay, and the route a case runs on. Before the split, in its own commit and green on the single class, one more (`StatusTransitionServiceOrderTest`) pins what none of them does:

1. the order of the five refusals, and that nothing is saved on any of them;
2. `transition-conflict` when the version or the status changed between read and save;
3. `case_not_found`, `transition_not_found` and `transition_missing_to_status`;
4. the payload of the one save of a closing move, with the stamp and the version on it;
5. `retime()` after the save and not after a refused save;
6. a status record whose dispatched actions cannot be written back: logged at error, the move still answers;
7. the free-form refusals (not an administrator, a status the process owns, a status of another case type);
8. the shape of `getAvailableTransitions()` without a case, without a workflow, and with one: `withheld` always present.

The test builds the engine through one helper, so the constructor change in the next commit touches that helper and the six existing constructions only.

### Steps

1. This design.
2. Characterisation test, green on the single class (own commit).
3. `CaseDeclarations` and `CaseMoveLedger` with their own unit tests; the engine delegates; both suppressions removed; baseline lowered by 2. The characterisation test and the five existing ones stay green without an edit beyond the constructor helper.

## Implementation Order

1. **Phase 1 (Priority 1 controllers):** ZrcController, ZtcController, BrcController, DrcController
2. **Phase 2 (Priority 1 services):** ZgwService, ZgwZrcRulesService, ZgwZtcRulesService
3. **Phase 3 (Priority 1 remaining):** ZgwBrcRulesService, ZgwDrcRulesService, ZgwBusinessRulesService
4. **Phase 4 (Priority 2):** AcController, ZgwRulesBase, LoadDefaultZgwMappings, utility services
5. **Phase 5 (Priority 3):** Single-suppression files with coupling/length issues

## Testing Strategy

### Before decomposition
- Run existing unit tests: `docker exec -w /var/www/html/custom_apps/dossiq nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
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
