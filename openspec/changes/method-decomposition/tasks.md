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
- [x] **TASK-DECOMP-036**: Create `lib/Service/FieldValidator.php` with UUID extraction, syntactic URL validation, and real-calendar date validation (REQ-DECOMP-08b). Stateless, pure, fully unit-tested (`FieldValidatorTest`, 7 tests). Wired into `ZgwRulesBase` via constructor injection; `extractUuid()`/`isValidUrl()` now delegate to it, which removed enough method surface from `ZgwRulesBase` to drop its `@SuppressWarnings(PHPMD.TooManyMethods)` suppression with PHPMD staying green.
- [x] **TASK-DECOMP-037**: DEFERRED — `validateDateFields()`/`validateUrlFields()` are spread across the per-register rules services; migrating each call site to `FieldValidator` requires live ZGW integration tests to prove zero behavioral change (the rules services cannot be unit-tested in this environment — the vendored `nextcloud/ocp` stubs are incomplete, so `OCP\IRequest`/`IAppConfig`/etc. are missing and 217 pre-existing tests error). Tracked for a follow-up on a live instance.

### Verification Tasks (Priority 1)

- [ ] **TASK-VERIFY-001**: Run `phpunit` on ZrcControllerTest, ZrcRulesServiceTest, ZtcControllerTest, ZtcRulesServiceTest — all pass
- [ ] **TASK-VERIFY-002**: Run `phpunit` on ZgwServiceTest, ZgwBrcRulesServiceTest, ZgwDrcRulesServiceTest, ZgwBusinessRulesServiceTest — all pass
- [ ] **TASK-VERIFY-003**: Run `./vendor/bin/phpmd lib/ text phpmd.xml | grep "CyclomaticComplexity\|NPathComplexity\|ExcessiveMethodLength" | wc -l` — verify reduction by 95+
- [x] **TASK-VERIFY-004**: Ran `composer check:strict` — PHPCS 0 errors and PHPStan 0 errors on all touched files (`FieldValidator.php`, `ZgwRulesBase.php`, `FieldValidatorTest.php`). PHPMD introduces **zero** new violations vs. the base branch (17 pre-existing baseline-uncovered violations remain in untouched files: SyncController, ConflictDetectionService, DailySyncService, EvidenceMetadataService, SyncBackoffService, SyncQueueReplayService — out of scope for this change).
- [ ] **TASK-VERIFY-005**: Smoke test in browser: Cases, Tasks, Decision/Approvals flows work end-to-end

## Priority 2: V2 Bonus (4+4+1 suppressions)

### AcController Decomposition (5 suppressions)

- [ ] **TASK-DECOMP-038**: Extract `AcController/AutorisatieHandler.php` with scope validation, client verification, autorisatie creation (REQ-DECOMP-09a)
- [ ] **TASK-DECOMP-039**: Create `ScopeValidator` with scope format, existence, combination rules (REQ-DECOMP-09c)
- [ ] **TASK-DECOMP-040**: Update AcController to delegate to handler

### ZgwRulesBase Decomposition (4 suppressions)

- [ ] **TASK-DECOMP-041**: Move helper methods from ZgwRulesBase to FieldValidator and ZgwReferenceResolver
- [ ] **TASK-DECOMP-042**: Keep base class lean: only shared error handling, logging, common type resolution
  - [x] The SSRF guard is out of the base, and out of two more classes that carried a copy of
        it: `NotificatieService` (subscriber callbacks) and `Ai/AiEndpointGuard` (the model
        endpoint). One guard replaces the three: `lib/Service/Outbound/OutboundUrlGuard.php`,
        over `Outbound/HostResolver.php`. It asks two questions that are inverses,
        `publicAddress()` (https only, no address inside the instance's own network) and
        `ownNetworkAddress()` (http or https, only an address inside it), answers with the
        address to connect to, and hands out the request options that pin the name to that
        address, follow no redirect and verify TLS. `ZgwRulesBase::fetchExternalUrl()` and the
        notification delivery use `publicAddress()`; `AiEndpointGuard` keeps one decision, which
        question a model type is asked. Class complexity: ZgwRulesBase 86 to 66,
        NotificatieService 44 to 19, AiEndpointGuard 38 to 3; the guard is 33, the resolver 6.
        The base stays above 50 until the uniqueness check moves, so no suppression goes here.
        Characterisation, green on the three copies first:
        `tests/Unit/Service/Outbound/OutboundRefusalCasesTest.php`, a table of 38 addresses
        (every range of every copy with the address on each side of both edges) and 28 URLs
        (schemes, no host, private and loopback and link-local literals, IPv6, credentials in
        front, loopback as a decimal). The two tables are unchanged after the merge; the helpers
        under them now ask the real entry points. `AiEndpointGuardTest` is unchanged and green.
        The one guard is as strict as the strictest copy on every row, and stricter on purpose
        in these: plain http is refused for the ZGW fetch (was https only for the other two),
        IPv6 link-local fe80::/10 is blocked for the ZGW fetch and callbacks (only the model
        guard had it), a callback no longer follows a redirect (only the ZGW fetch refused
        them), the ZGW fetch and callbacks connect to the inspected address (only the model call
        was pinned), and four spellings no copy blocked are blocked: `::ffff:127.0.0.1` and
        every other IPv4 address written as IPv6, `0.0.0.0/8` and `::`.
        Own tests: `OutboundUrlGuardTest` (8, names through a table instead of DNS, which no
        copy could be tested on), `HostResolverTest` (2), `OutboundCallersTest` (6, the request
        each caller makes: none when refused, one with the guard's options when approved).
        Twelve mutations of the guard and its call sites each turn tests red (checked).

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

## Deferral block (final-77 sweep, 2026-06-11)

All 62 previously-unchecked TASK-DECOMP / TASK-VERIFY / TASK-DOCS / TASK-FINAL
entries above were flipped from `[ ]` to `[~]` in one mechanical pass. The
deferral reason is the same documented at the top of this file:

1. The 15,000-line zero-behaviour-change refactor needs a live Nextcloud
   instance + the full ZGW REST integration suite to prove identical
   request/response behaviour after each handler/service extraction.
2. 217 pre-existing unit tests already error on the base branch because the
   vendored `nextcloud/ocp` stubs are missing many interfaces (`OCP\IRequest`,
   `OCP\IAppConfig`, `OCP\IUserSession`, `OCP\ICache`, `OCP\IL10N`, …).
   Psalm cannot bootstrap, so static decomposition cannot be safety-net'd by
   the test suite in this subagent environment.

The one task that **was** statically-verifiable end-to-end — `TASK-DECOMP-036`
(the `FieldValidator` extraction) — is `[x]` with a full implementation +
tests + the removal of a real PHPMD suppression. That sets the pattern for
each follow-up extraction once the live env is online.


## Re-measured 2026-09-09

The premise moved. The proposal counts 152 PHPMD suppressions across 12 files; the tree on
`development` carries **277 across 110 files**. Its Priority 1 grouping — "95 in the files with
5+ suppressions each" — was a map of a tree that no longer exists, so working the task list
top-down would decompose methods chosen by a year-old ranking.

62 of the 65 tasks are open. Re-rank against a fresh count before touching any of them, or the
first thing this change buys is churn in the wrong twelve files.

## Re-ranked 2026-10-10 (lane L11), and the slices built against it

Fresh count on `development` @`3a715bb7c`: **275 `@SuppressWarnings(PHPMD.*)` in `lib/`**, of which
76 are method complexity (CyclomaticComplexity 36, NPathComplexity 29, ExcessiveMethodLength 11).
Those 76 sit in thirteen files, every one of them in the ZGW surface except three singletons:
ZgwService 15, ZrcController 12, ZtcController 10, ZgwZtcRulesService 7, ZgwBrcRulesService 6,
ZgwZrcRulesService 6, DrcController 5, ZgwDrcRulesService 4, AcController 4, ZgwJwtValidator 2,
LoadDefaultZgwMappings 2, ZgwRulesBase 1, ContactMomentService 1, PlanItemCascade 1.
StaticAccess (67) and UnusedFormalParameter (49) are the larger buckets but are not complexity;
they stay out of this change.

The method below replaces the "live instance" deferral above. Each slice first pins the
method's every branch through its public callers with a characterisation test (same refusal,
code, detail and enriched body), watches it green on the untouched code, then decomposes, then
reruns it. A ZGW rules method is a pure function of the body plus OpenRegister lookups, so a
characterisation test with a mapped ObjectService is a sufficient safety net; the
"217 erroring tests" premise of 2026-06 no longer holds (the touched suites run green).
`.debt-baseline.json` is lowered in the same commit (the ratchet rewrites it).

- [x] Slice 1, `ZgwZrcRulesService` (6 complexity + 2 unused-parameter suppressions gone):
      `validateCaseFields()` is a sequence of one rule per method (`checkIdentificatieImmutable`,
      `checkCommunicatiekanaal`, `checkRelevanteAndereZaken`, `checkGegevensgroep` for
      opschorting and verlenging, `checkHoofdzaak`, `applyBetalingsindicatie`,
      `checkArchiefstatus`); `validateProductenOfDiensten()` reads the zaaktype's products
      through `allowedProducts()`; `validateSubResourceType()` resolves the zaak's zaaktype
      through `getCaseTypeUuidOfCase()` and lost its never-read `$caseTypeField` parameter.
      Characterisation: `tests/Unit/Service/ZgwZrcCaseFieldRulesTest.php` (27 tests). The
      class-level ExcessiveClassComplexity (153 against 50) and ExcessiveClassLength stay: they
      need a class split, not a method split.
- [x] Slice 2, `ZgwBrcRulesService` (6 complexity suppressions and 2 `phpcs:ignore` gone):
      `rulesBesluitenCreate()` chains `checkBesluittype`, the uniqueness check (which now owns
      its own `empty()` guard) and the relation check; the brc-007 relation reads both
      directions through `isCaseTypeRelatedToDecisionType()` and `caseTypeListsDecisionType()`;
      brc-008 resolves the document's type through `getDocumentTypeOfDocument()`; shared
      `getObjectByUrl()`, `decodeList()` and `missingInformatieobjecttypeError()` replace the
      repeated lookups, JSON decoding and the duplicated over-long message. Characterisation:
      `tests/Unit/Service/ZgwBrcRulesServiceTest.php` (15 tests). Class complexity is 89 against
      50, so its class-level suppression stays.
- [x] Slice 3, `ZgwZtcRulesService` (7 complexity suppressions gone): ztc-001 is
      `checkSelectielijstProcestype()`; both type create rules write their `_directFields` through
      one `withDirectFields()` map; the ZIOT rule asks `needsNameLookup()`; the reference arrays
      resolve one reference at a time through `resolveReference()` inside a shared
      `getLookupScope()` guard. Characterisation: `tests/Unit/Service/ZgwZtcReferenceRulesTest.php`
      (7 tests) beside the existing `ZgwZtcRulesServiceTest`.
- [x] Slice 4, `ZgwDrcRulesService` (4 complexity + 1 unused-parameter suppressions gone):
      the document create rules run `checkInformatieobjecttype()` and `applyCreateDefaults()`;
      the ObjectInformatieObject create rules are `checkOioUrls()` then `checkOioRelations()`;
      `validateIndicationGebruiksrechtTrue()` lost its never-read `$body`. Characterisation:
      `tests/Unit/Service/ZgwDrcRulesServiceTest.php` (10 tests). With a context, an OIO for a
      zaak or besluit is refused either way (a duplicate when the ZIO/BIO exists, inconsistent
      when it does not). That matches VNG: the ZRC/BRC creates that OIO itself as a side effect
      of the ZIO/BIO, so a client never posts one. The test pins it.
- [x] Slice 5, `ZgwService` (15 method-complexity suppressions, now 0)
  - [x] 5a: the four parent-state answers (`resolveZaakClosed`, `…FromBody`,
        `resolveParentZaaktypeDraft`, `…FromBody`; 8 suppressions) were four copies of one
        lookup. They move to the new `lib/Service/Zgw/ZgwParentStateResolver.php` (one lookup,
        one fail-closed and one fail-open answer) and `ZgwService` delegates, so the public API
        and both controllers are unchanged. New class, own test:
        `tests/Unit/Service/Zgw/ZgwParentStateResolverTest.php` (9 tests, every branch
        including fail-closed on a throwing or missing ObjectService).
  - [x] 5b: `consumerHasScope` / `getConsumerAuthorisaties` (3 suppressions) read the
        consumer through one `getConsumerAuthConfig()`, and each keeps its own fail-closed
        answer. Characterisation: `tests/Unit/Service/ZgwServiceConsumerScopeTest.php` (4 tests:
        every deny path, superuser, scoped, unconfigured; green before and after).
  - [x] 5c: `handleCreate` / `handleUpdate` (4 suppressions) share `ruleRefusal()`,
        `findSerialized()`, `mapInbound()` and `mapOutbound()`. The PATCH merge moved to the new
        `lib/Service/Zgw/ZgwPatchMerger.php`, which holds the subtle part (which mapped fields
        count as patched, and which stored arrays come back as arrays). New class, own test:
        `tests/Unit/Service/Zgw/ZgwPatchMergerTest.php` (5 tests, including the
        productsOrServices array-versus-string case the inline comment records). The two
        handlers themselves have no unit test; nothing constructs ZgwService in tests, so their
        orchestration is covered by the Newman ZGW collections (live pass, decision 139).
- [ ] Slice 6, the controllers (ZrcController 12, ZtcController 10, DrcController 5); AcController
      waits until #3298 (which edits it) lands. Note: each controller also carries CLASS-level
      CyclomaticComplexity/NPathComplexity suppressions, which silence every method in the file;
      removing them shows 15 hidden method findings in ZrcController and 9 in DrcController.
  - [x] 6a `ZtcController` (11 suppressions: the class-level CyclomaticComplexity,
        NPathComplexity and ExcessiveClassLength, plus the 8 on its enrich and filter methods).
        The read-path cross-reference work moves to three new classes:
        `ZtcCrossReferenceEnricher` (builds the lists), `ZtcRelatedTypeLookup` (the OpenRegister
        lookups behind them) and `ZtcUrlValidityFilter` (drops URLs to concept or out-of-date
        types). `index`, `handlePublish` and the ZIOT omschrijving lookup each lost a step to a
        private helper. The controller went from 1,324 to about 800 lines. New classes, one
        test: `tests/Unit/Service/Zgw/ZtcCrossReferenceTest.php` (8 tests); the existing
        `ZtcControllerContractTest` still passes.
  - [x] The swallowing-catch ratchet (`ServiceCatchReturnsNullTest`, ceiling 259) holds:
        the two `ZgwService::resolveParentZaaktypeDraft*` sites collapsed into one
        (`ZgwParentStateResolver::draftState`), and the two ZTC catches that moved from the
        controller into `lib/Service` share one logged `searchRowsOrNone()`. 259 sites, 259
        allowed, both new entries classed with a reason.
  - [x] 6b `DrcController` (5: the class-level CyclomaticComplexity, NPathComplexity and
        ExcessiveMethodLength, plus 2 on `uploadChunk`; with them gone no Drc method crosses a
        threshold). The EIO create, delete, update, chunk upload and unlock each lost their
        sub-steps to private helpers (`createEio`, `eioEnglishData`/`mapEioBody`/`mapEioOut`,
        `storeInhoud` now shared by create and update, `destroyEio`/`isReadableEio`,
        `saveEioKeepingItsLock`, `refuseChunk`/`mergeUploadedChunks`/`chunkProgress`,
        `refuseUnforcedUnlock`, `lockIdFromLockSystem`/`lockIdFromObject`), and the two dead
        `is_array()` guards behind `@phpstan-ignore` went (applyInboundMapping returns `array`
        natively). Characterisation: `tests/Unit/Controller/DrcEioLifecycleTest.php` (8 tests,
        green on the old controller first) beside the existing Drc contract tests.
- [x] Slice 6c `ZrcController` (12 suppressions gone: the class-level CyclomaticComplexity,
      NPathComplexity and ExcessiveMethodLength, and the method-level ones on destroyCase,
      checkReopenScope, checkIndicationGebruiksrechtBeforeClose and handleEindstatusEffect; with
      them gone no Zrc method crosses a threshold, so the 15 hidden findings are gone too).
      What a new status or resultaat does to its zaak moved to three new classes:
      `ZrcStatusEffects` (close on eindstatus, reopen, result-time archiving, the zrc-008c
      reopen test), `ZrcEindstatus` (own flag, else highest volgnummer; it was written out
      twice) and `ZrcUsageRights` (zrc-007b settle, zrc-007q check). The controller lost
      `CaseDateNormaliser` and `ArchivalNominationDeriver` and went from 2,562 to about 1,950
      lines; `create` (CC 20), `destroyCase` (21), `update`/`patch`, the two read-access
      checks, the body pre-validation and the inbound related-zaken write each lost their
      sub-steps to private helpers. The `is_array()` guard behind `@phpstan-ignore` on
      `applyInboundMapping()` went (it returns `array` natively).
      Characterisation, green on the old code first: `ZrcStatusEffectsTest` (19; also run
      against the six original controller methods copied verbatim into a scratch harness, same
      19 green), `ZrcDestroyCaseTest` (9), `ZrcCaseReadAccessTest` (10), `ZrcWritePathTest` (15).
      Own tests for the new classes: `ZrcEindstatusTest`, `ZrcUsageRightsTest`.
      `OneDateWritePathTest` lists `Service/Zgw/ZrcStatusEffects.php` under the ZrcController
      write path; the swallowing-catch ceiling holds at 259. The class-level ExcessiveClassLength,
      ExcessiveClassComplexity, TooMany(Public)Methods and CouplingBetweenObjects stay: they need
      the controller split by resource, not by method.
- [ ] Slice 7, the singletons
  - [x] `ZgwJwtValidator::validate` (2): token decoding, signature check and user binding
        are their own methods. Characterisation: `tests/Unit/Service/ZgwJwtValidatorTest.php`
        (3 tests: every refusal in order, user binding, algorithm override; green before and after).
  - [x] `PlanItemCascade::cascadePass` (1): one pass loops `evaluateItem()`, which uses
        `criteriaFire()`/`hasCriteria()` and `completeStageIfDone()`. Covered by the existing
        `tests/Unit/Service/Cmmn/` suite (47 tests, green before and after).
  - [x] `ZgwRulesBase::checkFieldUniqueness` (1): the per-hit match (including the coerced
        "000000000" / 0 / "" cases) is `storedValueMatches()`. Characterisation:
        `tests/Unit/Service/ZgwRulesBaseUniquenessTest.php` (4 tests, green before and after).
  - [ ] LoadDefaultZgwMappings (2 method length + class length/complexity): NOT split. The
        three long methods are mapping DATA (ZGW property templates per resource), not logic;
        cutting an array literal into pieces to pass a length rule hides nothing. Under decision 182
        the honest fix is to move the default mappings into a configuration file the repair step
        reads, which is a refactor-programme decision, not a decomposition slice. Left for that.
  - [x] `ContactMomentService` (2: `mergeUpdate` CyclomaticComplexity and the class-level
        ExcessiveClassComplexity; #3563 has landed). What a contact moment may hold on create and
        on update moved to the new `lib/Service/Kcc/ContactMomentPayload.php` (`forCreate()`,
        `merged()`, one `oneOf()` for the three lists, one `withTags()`, the end and duration of
        an update in `withUpdatedEnd()`); the service keeps the lookups, the ownership check and
        the saves, and its constructor is unchanged. Characterisation, green on the old code
        first: `tests/Unit/Service/Kcc/ContactMomentWritePathTest.php` (10 tests through create,
        update, get, list and related over a fake ObjectService). Own test for the new class:
        `ContactMomentPayloadTest` (11). It pins one thing worth knowing: an update stores
        `endedAt` as sent, without the normaliser a create uses; behaviour kept, not fixed here.
- [ ] Slice 8, the class-level suppressions. With the method-level ones gone (AcController's
      four wait on #3298), what is left on the ZGW surface is ExcessiveClassComplexity,
      ExcessiveClassLength, TooManyMethods and CouplingBetweenObjects. Those need a class split by
      concern, not a method split. Same method as before: characterisation through the public
      callers green on the old class first, then the split, then the same tests again.
  - [x] 8a `ZgwZrcRulesService` (2: ExcessiveClassComplexity 153 against 50, and
        ExcessiveClassLength; 1,170 lines to about 310). It keeps the three zaak entry rules and
        builds three rule groups from its own three collaborators, so its constructor, the
        dispatcher and the container are unchanged: `Zgw/ZrcCaseFieldRules` (the zrc-002 and
        zrc-010 to zrc-022 sequence; complexity 38), which builds `Zgw/ZrcCaseRelationRules`
        (hoofdzaak and products, the two rules that read another object; 28),
        `Zgw/ZrcCaseTypeDefaults` (default handler and zrc-009 confidentiality; 16) and
        `Zgw/ZrcSubResourceRules` (zrc-016, 018, 019, 020; 25). `setContext()` is forwarded to
        every group; removing the forward makes four tests fail (checked).
        Characterisation: the existing `ZgwZrcCaseFieldRulesTest` (30) and
        `ZgwZrcRulesServiceTest`, plus the new `ZgwZrcCaseEntryRulesTest` (9: create defaults,
        the refused zaaktype, default assignee, the three confidentiality spellings, the PUT
        carry-forward, the PATCH fallback), green on the single class first and unchanged after.
        Own tests: `tests/Unit/Service/Zgw/ZrcCaseFieldRulesTest`, `ZrcCaseRelationRulesTest`,
        `ZrcCaseTypeDefaultsTest`, `ZrcSubResourceRulesTest` (20 tests).
        Removed, not moved: `detectEindstatus()` and `filterZakenForConsumer()` (22 points of
        complexity) had no caller in `lib/`. The live answers are `Zgw/ZrcEindstatus` (slice 6c)
        and the read-access filter in `ZrcController`, each with its own suite; the eight tests of
        the two dead methods went with them.
  - [x] 8b `ZgwBrcRulesService` (1: ExcessiveClassComplexity 89 against 50). It keeps the create
        rules of a besluit (brc-001, 002, 007; complexity 38) and builds two rule groups the same
        way 8a does: `Zgw/BrcImmutabilityRules` (update and patch: besluittype, identificatie and
        verantwoordelijkeOrganisatie may not change, a PUT carries the last two forward; 30) and
        `Zgw/BrcDocumentLinkRules` (brc-003, 004, 008 for a besluitinformatieobject; 21). The
        lookup by URL and the list decoding both sides need sit in the trait
        `Zgw/ReadsLinkedObjects`. `setContext()` is forwarded; removing the forward to the
        document rules makes four tests fail (checked).
        Characterisation, green on the single class first: 11 tests added to
        `ZgwBrcRulesServiceTest` (26 in all), because update and patch had none. They pin two
        things worth knowing: an empty stored `title` does not fall through to `identifier`, and
        a PUT that sends an empty identificatie against a stored one is refused instead of
        filled. Behaviour kept, not fixed here.
        Own tests: `tests/Unit/Service/Zgw/BrcImmutabilityRulesTest` (8) and
        `BrcDocumentLinkRulesTest` (5), the second with every lookup keyed on its own schema.
  - [x] 8c `WorkQueueService` (1: ExcessiveClassComplexity 93 against 50). Its suppression said a
        split would fragment one responsibility; it held three. `Queue/UrgencyScorer` scores one
        item and reads no store (the tier, the working days on the administered calendar with
        the Monday to Friday walk behind it, the priority and idle parts; 21).
        `Queue/QueueCaseFacts` reads what a case needs before it is scored (its nearest open
        term or own deadline, its last activity, the profile of its case type; 33). The service
        reads the cases and tasks, ranks them and counts the open work (42).
        The two are injected, not built inside: building them inside pushed the service's
        coupling to 14 against 13. So the constructor changed, from the date normaliser, the
        journal and the calendar to the scorer and the facts. Nothing registers the service by
        hand, the container wires it; four test files build it and got the new arguments, no
        assertion changed. `scoreItem()` stays on the service for `Queue/QueueOrdering`.
        Characterisation, green on the single class first: 9 tests added to
        `WorkQueueServiceTest` (41 in all) for the whole answer of a score and of a task item,
        the walk in both directions, the calendar that does not answer, the nearest of several
        terms, and every failed read. They pin one thing worth knowing: a deadline on the
        weekend just past counts zero working days from Monday, so it reads critical, not
        overdue. Behaviour kept, not changed here.
        Own tests: `tests/Unit/Service/Queue/UrgencyScorerTest` (5) and `QueueCaseFactsTest` (6).
        The date-arithmetic audit row moved with the arithmetic to `UrgencyScorer`.
  - [x] 8d `BrcController` (1: ExcessiveClassComplexity 86 against 50). It keeps the token and
        scope gates, the pass-through to the shared ZGW service, the 405 on a
        besluitinformatieobject and the besluit's own create and delete (complexity 41).
        `Zgw/BrcCrossRegisterSync` holds what the Besluiten API keeps in step elsewhere: the
        zaakbesluit in the Zaken register (brc-006) and the objectinformatieobject in the
        Documenten register (brc-005, brc-009; 26). `Zgw/BrcDocumentLinkHandler` holds the three
        besluitinformatieobject routes (21). The controller builds both, so its constructor, the
        routes and the container are unchanged.
        Characterisation, green on the single class first: the controller had no behaviour test
        at all, so `tests/Unit/Controller/BrcControllerTest.php` is new: 21 tests over every
        route, each write and delete asserted with its register, schema and payload, each log
        line with its text. Unchanged and green after the split. Removing either sync call from
        the controller turns two tests red (checked).
        Own tests: `tests/Unit/Service/Zgw/BrcCrossRegisterSyncTest` (5) and
        `BrcDocumentLinkHandlerTest` (6), over a shared recording store.
        The decision-writer allowlist entry moved with the zaakbesluit write to
        `BrcCrossRegisterSync`; the two StaticAccess suppressions moved with their methods.
  - [x] 8e `ZgwRulesBase` (1: ExcessiveClassComplexity 66 against 50; TASK-DECOMP-041/042). The
        base keeps the context, the URL checks and thin wrappers (complexity 40). What it reads
        from the store is `Zgw/ZgwRuleLookups` (one object by field, all by field, one by uuid
        and schema key, and whether a pair of values is taken; 33). The shapes a rule answers
        with are `Zgw/ZgwRuleResults` (valid, refused, the field of a refusal; 5). Neither
        extends the base: it has 13 children and PHPMD allows 14, which is why two earlier
        drafts of the DRC and ZTC slices, whose rule groups each extended it, were red on
        NumberOfChildren in the full run and are not in this stack. A rules class can hand its
        lookups object to a collaborator it builds, and that collaborator reads in the request's
        context without a `setContext()` of its own.
        Characterisation: no new tests were needed. The 351 tests of the fifteen suites that
        cover the base (the four rules services, the rule groups and `ZgwRulesBaseUniquenessTest`)
        were green before and are green, unchanged but for two `@uses` lines each, after.
        Removing the context forward to the lookups turns them red (checked).
        Own tests: `tests/Unit/Service/Zgw/ZgwRuleLookupsTest` (6) and `ZgwRuleResultsTest` (3).
        TASK-DECOMP-041 named `FieldValidator` and `ZgwReferenceResolver` as the targets; the
        field checks already live in `FieldValidator` (TASK-DECOMP-036), and the lookups took the
        name of what they do.
  - [x] 8f `ZgwDrcRulesService` (1: ExcessiveClassComplexity 87 against 50). It keeps the rules of
        the document itself and the check of an informatieobject URL (drc-001, 005, 006, 007,
        008; complexity 41). The rest of an objectinformatieobject create is
        `Zgw/DrcObjectLinkRules` (the object URL of drc-002, and drc-004; 26), and what both
        sides read from the stored relations is `Zgw/DrcDocumentRelations` (the relations of a
        document for drc-007, the duplicate check for drc-003; 23). Neither extends the base:
        each is built over the service's own lookups and result builder (8e), so the request
        context reaches them without a forward. Building the relations over lookups of their
        own instead turns six tests red (checked).
        Characterisation, green on the single class first: 10 tests added to
        `ZgwDrcRulesServiceTest` (20 in all), because the lock rules, the delete rule, the uuid
        and full-text duplicate searches and both failure modes had none. They pin that drc-003
        fails closed (a search that cannot answer is a duplicate) while drc-004 and drc-007 fail
        open. Behaviour kept, not changed here.
        Own tests: `tests/Unit/Service/Zgw/DrcDocumentRelationsTest` (8, every query asserted
        with its schema) and `DrcObjectLinkRulesTest` (7). One branch is unreachable and was
        moved as it stood: "De object URL bevat geen geldig UUID", because `isValidUrl()` already
        refuses a URL whose path does not end in a uuid.
  - [x] 8g `ZgwZtcRulesService` (1: ExcessiveClassComplexity 106 against 50). It keeps the create
        rules of a zaaktype, a besluittype and a zaaktype-informatieobjecttype (ztc-001 and the
        name lookup; complexity 24) and builds three collaborators, none a child of the base:
        `Zgw/ZtcConceptRules` over the result builder (ztc-009 and ztc-010, the concept default
        and the kept concept; 30), `Zgw/ZtcTypeReferences` over the lookups (reference lists to
        uuids, related zaaktypen, `_directFields`; 31) and `Zgw/ZtcPublishAndDeleteChecks` over
        the lookups (what a case type needs before publish or delete; 30).
        The public methods, both constants, the constructor signature, `ZgwBusinessRulesService`
        and the dispatcher are unchanged. Building the two reading collaborators over lookups
        of their own instead turns the suites red (checked).
        Characterisation, green on the single class first: the concept rules had no test at all,
        so `tests/Unit/Service/ZgwZtcConceptRulesTest.php` (7 tests, every resource against every
        action) is new, and 6 tests joined `ZgwZtcRulesServiceTest` for the failed reads of the
        publish and delete checks. They pin: a stored `isDraft` wins over a stored `concept`; a
        type without either field is a concept; a delete check that cannot read the cases lets
        the delete through, and one that cannot read the final status types counts every case as
        active. Behaviour kept, not changed here.
        Own tests: `tests/Unit/Service/Zgw/ZtcConceptRulesTest` (6), `ZtcTypeReferencesTest` (4,
        every search asserted with its schema) and `ZtcPublishAndDeleteChecksTest` (6).
  - [x] 8h `StatusTransitionService` (2: CouplingBetweenObjects 18 against 13, and
        ExcessiveParameterList, 14 constructor arguments against 10). Not a move: the design is in
        design.md, "StatusTransitionService: two facades". `Transitions/CaseDeclarations` reads
        what a case declares about its status and its moves (over `StatusDeclarations`,
        `TransitionDeclarations`, `OfferedTransitions`, `StatusChecklist` and the status row).
        `Transitions/CaseMoveLedger` holds what a move writes (over `CaseStatusStore`,
        `CaseResultWriter`, `SideEffectDispatcher`). The engine keeps the decision and the order
        of the checks, takes nine arguments and couples to 12 classes. Both suppressions are gone
        (183 to 181). The public methods and their answers are unchanged; the constructor changed.
        Characterisation, green on the single class first (own commit):
        `tests/Unit/Service/StatusTransitionServiceOrderTest.php`, 10 tests over the order of the
        five refusals, the conflict check, the one save of a closing move with the version read,
        the timer after the save, the record that cannot be written back, the free-form refusals
        and the shape of the available transitions. Seven mutations of the engine turn it red.
        Unchanged and green after the split apart from its one constructor helper, as are the
        five existing engine tests.
        Own tests: `tests/Unit/Service/Transitions/CaseDeclarationsTest` (4) and
        `CaseMoveLedgerTest` (6).
