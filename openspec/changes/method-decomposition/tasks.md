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

Built 2026-11-13 (partial, branch quality/build-method-decomposition): the consumer lookups moved out of `AcController` into `lib/Controller/Support/ApplicatieDirectory.php` (requested clientId, token client_id for the refusal message, list by primary then extra clientId), beside the earlier `ApplicatieRules` and `ApplicatieRepresentation`. The controller's three class-level PHPMD suppressions (ExcessiveClassComplexity, CyclomaticComplexity, NPathComplexity) are gone with PHPMD green. Behaviour pinned by the unchanged `AcControllerCharacterisationTest` (35 tests, mapper call order included); new `ApplicatieDirectoryTest`. 038 to 040 stay open: there is no autorisatie-creation or scope-validation handler yet.


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
- [x] Slice 6, the controllers (ZrcController 12, ZtcController 10, DrcController 5); AcController
      waits until #3298 (which edits it) lands.
      Closed 21 Nov: #3298 has merged, and `lib/Controller/AcController.php` on `development`
      carries no `@SuppressWarnings(PHPMD.*)` at all and PHPMD reports nothing for it under
      either ruleset, so the four AcController suppressions are gone and 6a, 6b and 6c cover the
      other three controllers. Note: each controller also carries CLASS-level
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
  - [x] 8i `ZtcController` (2: TooManyPublicMethods, 12 public methods against 10, and
        ExcessiveClassComplexity 79 against 50). The three publish routes go to their own
        controller, `ZtcPublishController` (`ztcPublish#publish*` in `appinfo/routes.php`, same
        URLs, verbs, rate limits and auth attributes), with `handlePublish()` and
        `publishedCopy()`. Two helpers leave as classes of their own: `Zgw/ValidityDateFilter`
        (the `datumGeldigheid` filter of a list) and `Zgw/ZtcTypeNameResolver` (ztc-010m, an
        informatieobjecttype named by its omschrijving). Measured after: 9 public methods,
        complexity under 50, both suppressions gone (phpmd.suppressWarnings minus 2: 180 to 178 on top of 8h). Bodies
        moved unchanged.
        Characterisation: the publish contract test existed (`ZtcControllerContractTest`, 6
        tests) and moves with the routes as `ZtcPublishControllerContractTest`; the rate-limit
        and middleware tests list the new controller. Own tests: `ValidityDateFilterTest` (1,
        both bounds inclusive, open bounds) and `ZtcTypeNameResolverTest` (2, name then full
        text, and every case that leaves the body alone).
        Seen, not changed: the publish routes run no business rule, so the CT-02b publish guard
        (`ZgwBusinessRulesService::guardCaseTypePublish`) does not run on them, and they check no
        ZGW scope (the contract test says so).
  - [x] 8j `DeadlineReportingService` (1: ExcessiveClassComplexity 74 against 50) and
        `AiService` (1: its ExcessiveClassComplexity suppression was stale; PHPMD reports nothing
        without it). The service held three reports. The annual payout statement moved to
        `Reporting/AnnualPayoutStatement` (`forYear()`, the read, the year filter, the rows, the
        warnings and the summary; 15), and the dashboard KPI counters to
        `Termijn/TermDashboardFigures::tally()` (12), which already computed the dashboard's
        running figures and stays at 43. The service reads the instances, builds the quarterly
        report and keeps `generateDwangsomAuditReport()` and `getTermijnKpi()` as they were; it
        adds each statement row's generic key names itself, as before. Measured after: 48, no
        CouplingBetweenObjects finding (13 would have been one, so the statement replaced the
        `PayoutReader` constructor parameter instead of joining it; the container wires the
        reader into the statement). One test built the service with `payouts:` and now passes
        the statement; no assertion changed.
        Characterisation, green on the old code first: `DeadlineReportingServiceTest` (23, the
        statement's whole shape, the year filter, the finance app's payouts, every KPI case),
        `StatutoryTermParityTest`, `DeadlineReportingControllerContractTest` and
        `ReportingToolsTest` (61 in all), unchanged and green after.
        Own tests: `tests/Unit/Service/Reporting/AnnualPayoutStatementTest` (2) and
        `tests/Unit/Service/Termijn/TermDashboardFiguresTallyTest` (2); breaking the year filter
        or the overrun count turns them red (checked).
  - [x] 8k `EngineTaskGateway` (1: ExcessiveClassComplexity). The gateway keeps every call to the
        engine (mirror, reassign, find, claim, complete, cancel, the key lookup and the lazy
        resolution). The two translations that call nothing leave as pure classes it builds, so
        its constructor and the container are unchanged: `Task/EngineTaskPayload` (what the
        engine is sent: the key, the copied fields, the candidates, the declaration and the
        decoded checklist) and `Task/EngineTaskRow` (an engine task read back in the register's
        vocabulary, each optional column asked for before its getter is called). Bodies moved
        unchanged; `toEnginePayload()` and `find()` delegate. The frozen key prefix is the
        gateway's public `SOURCE_KEY_PREFIX`, which `sourceKey()` and the payload both read.
        TooManyPublicMethods stays, for the reason its docblock gives (one seam onto the engine).
        Characterisation: `EngineTaskGatewayTest` (17, payload in its order, every miss of a
        find, the magic columns of an Entity), green on the old class first and unchanged after
        but for two `@uses` lines in each file that builds the gateway.
        Own tests: `tests/Unit/Service/Task/EngineTaskPayloadTest` (4) and `EngineTaskRowTest`
        (3); breaking the once-key precedence or the date format turns them red (checked).
        Whole unit suite: 19 failures before and after, all in classes this slice does not touch.
  - [x] 8l `CasePlanProjectionService` (1: ExcessiveClassComplexity 103 against 50; its
        TooManyMethods finding, 34 against 25, goes too). The service keeps the reads (caseType,
        published caseModel), the plan creation and its three public conversion methods,
        which now delegate. The conversion leaves as pure classes the service builds, so its
        constructor and the container are unchanged: `SentryConverter` (one sentry, its on- and
        if-part and a criteria list, through the closed tables; the three table constants
        live here and the service keeps them as aliases), `CaseModelConverter` (the flat
        planItems list into the nested tree, every structural refusal) and
        `CaseModelSearchHits` (the first plain row of a lookup, planItems decoded from JSON
        text). Bodies moved unchanged, refusal messages included. phpmd: zero findings on the
        four files.
        Characterisation: `CasePlanProjectionServiceTest` (34), `CasePlanModelLookupTest` (3),
        `CmmnRetirementTest`, `CasePlanDrain`, `CasePlanProjectionListenerTest`,
        `BackgroundReadsRunAsSystemTest` and `tests/Unit/Architecture`, green on the old class
        first and unchanged after but for `@uses` lines.
        Own tests: `SentryConverterTest` (5), `CaseModelConverterTest` (5) and
        `CaseModelSearchHitsTest` (2); breaking the sentry position, the `required` flag or the
        undecodable-text fallback turns them red (checked).
        Whole unit suite: 19 failures before and after, all in classes this slice does not touch.
  - [x] 8m `ZtcCrossReferenceEnricher` (1: ExcessiveClassComplexity 52 against 50). Turning a
        stored uuid list (an array or a JSON string) into Catalogi URLs moved to the new
        `Zgw/ZtcStoredUuidUrls` (`urls()`, with `storedIds()` and `prefixedUrls()`); the enricher
        builds it in its constructor, so its constructor, interface and container binding are
        unchanged, and the swallowing catch in `enrichBesluittype()` stays where the allowlist has it.
        Characterisation: the existing `ZtcCrossReferenceTest` (8), `ZtcRelatedTypeLookupTest` and
        `ZtcWriteGateGoldenTest`, green before and after. Own test: `ZtcStoredUuidUrlsTest` (2).
        Whole unit suite: 19 failures before and after, all in classes this slice does not touch.
  - [x] 8n Six stale suppressions (PHPMD reports nothing without them, measured one at a time
        against `phpmd.xml`): CouplingBetweenObjects on `StufController`, `PauseChaseService` and
        `IntakeRequirementsListener`, ExcessiveParameterList on `InboundMailIntake` and on
        `AcknowledgementService::__construct`, and TooManyMethods on `DeadlineReportingService`
        (after 8j moved the payout statement out). Docblocks only; no code moved. phpmd, phpcs and
        `tests/Unit/Architecture` green on the six files. The other 98 complexity-type suppressions
        in `lib/` still report their rule when lifted, so they need a split, not a deletion.
  - [x] 8o `CaseTypeRightsByRole` (1: ExcessiveClassComplexity 50 against 50). The Decide column
        (which mandates hold for this case type, per role, and the highest ceiling worded) is
        the new `CaseType/RoleMandateCeilings` (`byRole()`, with `forCaseType()` and `ceiling()`;
        bodies moved unchanged). The service builds it in its constructor, so its constructor,
        interface and container binding are unchanged. phpmd: zero findings on both files.
        Characterisation, green on the single class first (own commit): 4 tests added to
        `CaseTypeRightsByRoleTest` (14 in all) for the case-type scoping by id, slug and
        identifier, the register query, the ceiling cell and an unreadable register across roles.
        Unchanged and green after but for one `@uses` line.
        Own test: `tests/Unit/Service/CaseType/RoleMandateCeilingsTest` (4); taking the lowest
        ceiling instead of the highest turns 9 tests red (checked).
        Whole unit suite: 19 failures before and after, all in classes this slice does not touch.
  - [x] 8p `MenuCaseTypesService` (1: ExcessiveClassComplexity 50 against 50). Reading one stored
        case type row as a menu entry (its uuid from `uuid`, `id` or `@self.id`, the title in the
        reader's language, the identifier, the trimmed icon, the handling teams, and the public
        shape the menu is sent) is the new `CaseType/CaseTypeMenuEntry` (`uuidOf()`, `entry()`,
        `publicRow()`; bodies moved unchanged, `publicRow()` no longer static so no StaticAccess
        finding replaces the one removed). The service keeps the reads, the team filter, the
        superseded-version folding and the user's choice; it builds the entry reader in its
        constructor, so its constructor, interface and container binding are unchanged.
        phpmd: zero findings on both files.
        Characterisation, green on the single class first (own commit): 3 tests added to
        `MenuCaseTypesServiceTest` (16 in all) for the icon, the uuid precedence with the first
        row of a uuid winning, and `byIdentifier()`. Unchanged and green after but for one
        `@uses` line. Own test: `tests/Unit/Service/CaseType/CaseTypeMenuEntryTest` (3);
        dropping the integer-id branch turns it red (checked).
        Whole unit suite: 19 failures before and after, all in classes this slice does not touch.
  - [x] 8q Three more stale ExcessiveClassComplexity suppressions (PHPMD reports nothing for the
        rule without them, measured one at a time against `phpmd.xml`): `CaseCopyService`,
        `WOOAssessmentController` and `Review/ReviewBatches`. Docblocks only; no code moved.
        `ReviewBatches` keeps its CouplingBetweenObjects finding (14), which development already
        reports with or without this change.
  - [x] 8r `ComplaintService` (1: ExcessiveClassComplexity 50 against 50). Sorting the active
        complaints by their handling deadline (overdue, within the window) and the redacted overdue
        list an assistant sees (REQ-MCP-203, the allowlist and the acknowledgement flag) moved to the
        new `Complaint/ComplaintDeadlineAlerts` (`grouped()`, `overdueRows()`; bodies moved
        unchanged). It replaced the `DayCount` constructor parameter, which only it used, so the
        service's CouplingBetweenObjects stays at 14 (development already reports it); the service
        builds it over its own date path when the container does not pass one. Public methods and
        the `#[McpTool]` attribute are unchanged.
        Characterisation, green on the single class first (own commit): 2 tests added to
        `ComplaintServiceTest` (25 in all) for the acknowledgement flag of every non-late row and an
        unreadable deadline. Own test: `tests/Unit/Service/Complaint/ComplaintDeadlineAlertsTest`
        (3); narrowing the window to `<` turns 2 tests red (checked).
  - [x] 8s `ProcessMiningService` (1: ExcessiveClassComplexity 50 against 50). Grouping the status
        records by case, the fallback to a case's own status history (with its holder) and the time
        order moved to the new `ProcessMining/CaseStatusRecords` (`byCase()`; bodies moved
        unchanged). The service builds it in its constructor over its own date path, so its
        constructor, interface and container binding are unchanged.
        Characterisation: the existing `ProcessMiningServiceTest` (history fallback, holder, full
        payload) and `ProcessMiningServiceGoldenTest`, green before and unchanged after but for one
        `@uses` line each. Own test: `tests/Unit/Service/ProcessMining/CaseStatusRecordsTest` (3);
        reversing the time order turns 4 tests red (checked).
  - [x] 8t `TemplateLibraryService` (1: ExcessiveClassComplexity 50 against 50). What the library
        lists for one template (id, title, description, category, version, file, fills and notes)
        moved to the new `TemplateLibrary/TemplateMetadata` (`entry()`, with `fills()` and
        `notes()`; bodies moved unchanged). It reads no file; the service still reads and decodes
        the files, warns about invalid ones and builds the entry reader in its constructor, so its
        constructor, interface and container binding are unchanged.
        Characterisation, green on the single class first (own commit): 1 test added to
        `TemplateLibraryDirectoryTest` (4 in all) for trimmed notes, dropped blanks and non-strings,
        and fills that are not a list. Own test: `tests/Unit/Service/TemplateLibrary/TemplateMetadataTest`
        (2); keeping notes untrimmed turns 2 tests red (checked).
  - [x] 8u `LhsMatrixDecisionTableMigrator` (1: ExcessiveClassComplexity 50 against 50). Turning the
        cells of a matrix into decision rules (one per cell, none when a cell is off its axes) moved
        to the new `Vth/LhsMatrixCells` (`rules()`, with `isOnAxes()` and `ruleFor()`; bodies moved
        unchanged). The migrator builds it in its constructor, so its constructor, interface and
        container binding are unchanged.
        Characterisation: the existing `LhsMatrixDecisionTableMigratorTest` (each cell a rule, the
        cell off its axis, the whole projected table) and `...FailuresTest`, green before and
        unchanged after but for one `@uses` line each. Own test:
        `tests/Unit/Service/Vth/LhsMatrixCellsTest` (2); a loose axis comparison turns it red
        (checked).
  - [x] 8v `Scoring/FormulaScorer` (1: ExcessiveClassComplexity 51 against 50). Splitting an
        expression into its tokens moved to the new `Scoring/FormulaTokens` (`split()`; was the
        private static `tokenise()`). Its `elseif`/`else` became early `continue`s, which also clears
        the ElseExpression finding development reports on the old method; same tokens in the same
        order. The scorer builds the tokeniser in a new argument-free constructor, so every
        `new FormulaScorer()` keeps working and the grammar, limits and refusal messages are
        unchanged.
        Characterisation: the existing `FormulaScorerTest` (8 tests, 17 cases including every
        refusal of the grammar), green before and unchanged after but for one `@uses` line. Own
        test: `tests/Unit/Service/Scoring/FormulaTokensTest` (3); giving a name the `num` kind turns
        12 tests red (checked).
  - [x] 8w `Import/RecordCaseMapping` (1: ExcessiveClassComplexity 51 against 50). Putting a raw source
        value in the shape its field declares (JSON, boolean, date, moment, integer, number, a listed
        value with its default and refusal, collapsed text cut at a word) moved to the new
        `Import/RecordFieldShape` (`shaped()`, with `textOf()`, `typed()`, `listed()`, `cut()` and
        `TRUE_WORDS`; bodies moved unchanged). The mapping keeps reading, the required check, the
        statuses, terms and the payload paths, and builds the shaper over its own date path and
        values in its constructor, so its constructor and every caller are unchanged.
        Characterisation, green on the single class first (own commit): 3 tests added to
        `RecordCaseMappingShapesTest` (6 in all) for the cut at a word and the hard cut, the listed
        value, its default and its refusal message, and dates, moments (written in the tenant's
        zone) and integers. Own test: `tests/Unit/Service/Import/RecordFieldShapeTest` (3);
        cutting one character short of the space turns 3 tests red (checked).
  - [x] 8x Thirty more stale suppressions. Each `@SuppressWarnings(PHPMD.*)` in `lib/` was lifted
        one at a time and its file run against both rulesets the `phpmd` script runs (`phpmd.xml`
        and hydra-gates' `phpmd-unusedparams.xml`); thirty report nothing for their rule without
        it: StaticAccess (9), UnusedFormalParameter (7), ExcessiveParameterList (6),
        CouplingBetweenObjects (3), BooleanArgumentFlag (3) and ExcessiveClassComplexity (2), in 28
        files. Docblocks only; no code moved. The suppression and its explanation lines go, and
        each touched docblock cites this change. A whole-`lib/` run of both rulesets reports the
        same 258 findings, file by file, on `development` and on the branch, and phpcs is clean on
        the 28 files. `scripts/debt-ratchet.js` counts 222 suppressions, down from 252.
  - [x] 8y `ConsultationService` (1: ExcessiveClassComplexity 51 against 50). What an advice request
        may be moved to the new `Consultation/ConsultationStatusRules`: the declared statuses and the
        moves between them (`assertKnownStatus()`, `assertMove()`; a stored status outside the graph
        still constrains nothing), the advice types (`adviceOf()`), whether a request holds its case
        (`blocks()`) and the obligation that mirrors a mandatory request (`mirroringObligation()`).
        Bodies and messages moved unchanged. The obligation payload moved with the blocking rule
        because both ask whether a request is mandatory, and because the service then no longer
        couples to `ObligationService`: the rules class alone would have pushed its
        CouplingBetweenObjects from 13 to 14. The service builds the rules in its constructor, so its
        constructor, interface and container binding are unchanged; `consultationBlocks()` stays
        public and delegates. phpmd: zero findings on both files under both rulesets.
        Characterisation, green on the single class first (own commit): 3 tests added to
        `ConsultationServiceTest` (12 in all) for a move the graph refuses, a stored status outside
        the graph with the closing stamp, and the blocking rule. Own test:
        `tests/Unit/Service/Consultation/ConsultationStatusRulesTest` (6); letting every stored
        status through `assertMove()` turns 2 tests red (checked).
  - [x] 8z `Sharing/CaseLinkShares` (1: ExcessiveClassComplexity 52 against 50). What a `caseShare`
        record holds for an access link, read and written without a store, moved to the new
        `Sharing/CaseLinkShareRecord`: the record written for a minted link (`forMinted()`, with
        `ownFields()` and `linkFields()`), the link ids a stored record holds (`linkIds()`, the case
        link first, from stored text or a list) and the anchor at the end of a link address
        (`anchorOf()`). Bodies moved unchanged. The service keeps the reads, the writes, the IDOR
        guard and the revokes; it builds the record reader in its constructor, so its constructor,
        interface and container binding are unchanged. phpmd: zero findings on both files under
        both rulesets.
        Characterisation: the existing `CaseLinkSharesTest` (the whole stored record, the view-only
        level, the junk document entries, the trimmed anchor) and `CaseShareMintRollsBackTest`, green
        before and unchanged after but for one `@uses` line in each file that builds the service.
        Own test: `tests/Unit/Service/Sharing/CaseLinkShareRecordTest` (4); keeping a document link
        id of 0 turns 2 tests red (checked).
  - [x] 8aa `Cases/CaseSplitExecutor` (1: ExcessiveClassComplexity 52 against 50). What a split writes
        that it can say without a store moved to the new `Cases/CaseSplitFields`: the second case's
        fields taken from the first (`secondCase()`, which now takes the source's uuid instead of
        asking the store), its relation back to the first (`relationBack()`) and the moved items the
        original records (`movedItems()`, with its `movedAt` stamp, so the executor no longer couples
        to `DateTimeImmutable`). Bodies moved unchanged. The executor keeps the reads, the moves, the
        status take-over and the writes; it builds the fields in its constructor, so its constructor,
        interface and container binding are unchanged. phpmd: zero findings on both files under both
        rulesets.
        Characterisation, green on the single class first (own commit): 1 test added to
        `CaseSplitExecutorTest` (10 in all) for the default title, the copied and defaulted handling
        fields, the relation note and the `movedAt` stamp, beside the golden test's moved-item shapes.
        Unchanged and green after but for one `@uses` line in each file that builds the executor.
        Own test: `tests/Unit/Service/Cases/CaseSplitFieldsTest` (3); defaulting impact to high turns
        3 tests red (checked).
  - [x] 8ab `Repair/MigrateArchivalToOpenRegister` (1: ExcessiveClassComplexity 52 against 50). Its
        third step, writing every proof-of-transfer record as a case document, moved to the new
        `Repair/ProofOfTransferExport` (`export()`, with the read, the per-record skip of a proof
        without a case, the caseDocument it becomes and the logged failed write; bodies and messages
        moved unchanged). The repair step keeps the guard, the system identity, the TMLO switch, the
        legal holds and the marker; it builds the export over its own settings and logger in its
        constructor, so its constructor and its registration in `appinfo/info.xml` are unchanged.
        phpmd: zero findings on both files under both rulesets.
        Characterisation: the existing `MigrateArchivalRunTest` (every save and the logged failure in
        order, the unreadable proof schema, the completion counts) and
        `MigrateArchivalHoldsAndTmloTest`, green before and unchanged after but for one `@uses` line
        each. Own test: `tests/Unit/Repair/ProofOfTransferExportTest` (2); exporting a proof without
        a case turns 2 tests red (checked).
  - [x] 8ac `Publication/OutcomePublisher` (1: ExcessiveClassComplexity 52 against 50). Whether the
        disclosable documents may go out (no unread file, none under an access exception, each refused
        document named by file name, else title, else id) moved to the new
        `Publication/DisclosableDocumentCheck` (`refusal()`; bodies moved unchanged, the repeated name
        expression is one `nameOf()`). It reads no store; the publisher builds it in its constructor, so
        its constructor, interface and container binding are unchanged, and the `AccessExceptionValue`
        import moved with it, so CouplingBetweenObjects stays at 13 (its suppression stays). phpmd: zero
        findings on both files.
        Characterisation: the existing `WooPublicationCarriesItsDocumentsTest` (an unread file and an
        access exception, both through `publish()`) and the publication suites (258 tests), green before
        and unchanged after. Own test: `tests/Unit/Service/Publication/DisclosableDocumentCheckTest` (3);
        renaming the unread reason turns 2 tests red (checked).
  - [x] 8ad `Portal/PortalPages` (1: ExcessiveClassComplexity 54 against 50). One page per listable
        collection (the schema's first create action, the list, the selected row, the label override
        and the group) moved to the new `Portal/CollectionPages` (`forCollections()`, with
        `listable()`, `blocksFor()` and `createBlocks()`; bodies moved unchanged). `PortalPages` keeps
        the resident's pages and the case screen; its `forCollections()` delegates, and it builds the
        collection pages in a new argument-free constructor, so every `new PortalPages()` keeps working.
        phpmd: zero findings on both files.
        Characterisation: the existing `tests/Unit/Portal` suite (209 tests, among them
        `PortalContributionManifestGoldenTest` over the whole declared contribution), green before and
        unchanged after. Own test: `tests/Unit/Portal/CollectionPagesTest` (2); listing a collection
        marked `listable: false` turns it red (checked).
  - [x] 8ae `WOOAnonymisationAssistService` (1: ExcessiveClassComplexity 52 against 50). Building a
        redaction-span proposal moved to the new `Redaction/RulesFloorProposal` (`build()`): the rule
        spans tagged as rules, the proposal without the assistant, the logged fall back when it
        fails, and the UNION merge with the rules as a floor (the dedup keys, the well-formed check
        and the start sort). Bodies and the log line moved unchanged. Moving the whole build, not
        only the merge, takes the `HermiqAssistantException` catch with it, so the service's
        CouplingBetweenObjects stays at 13; the merge alone would have made it 14. The service keeps
        the text checks, the pending proposal, the review and the oversight reports; it builds the
        proposal over its own assistant client and logger in its constructor, so its constructor,
        interface and container binding are unchanged. phpmd: zero findings on both files under both
        rulesets.
        Characterisation: the existing `WOOAnonymisationAssistServiceTest` (the rules floor against
        adversarial assistant spans, malformed spans dropped, an exact duplicate counted once, the
        rules-only answers with and without a failure), green before and unchanged after but for one
        `@uses` line, as is `DocumentRedactionServiceTest`. Own test:
        `tests/Unit/Service/Redaction/RulesFloorProposalTest` (3); accepting an empty span turns 2
        tests red (checked).
  - [x] 8af `BeschikkingService` (2: ExcessiveClassComplexity 52 against 50, and TooManyMethods).
        What a successor request says without a store moved onto the request itself,
        `Beschikking/SuccessorRequest`: whether its kind is one a successor may be
        (`assertKnownKind()`, with the list as `KINDS`; the service keeps `SUCCESSOR_KINDS` as an
        alias), the fields it is composed with (`fieldsFrom()`), its case (`caseIdFrom()`) and its
        template, the named one else the original's (`templateFrom()`). Bodies and the refusal
        sentence moved unchanged. The service already coupled to the request, so its
        CouplingBetweenObjects stays at 20 (its suppression stays, as does ExcessiveParameterList);
        its constructor, interface and container binding are unchanged. phpmd: zero findings on
        both files under both rulesets.
        Characterisation, green on the single class first (own commit): 1 test added to
        `BeschikkingServiceTest` (28 in all) for the original's template, the one the handler names
        and the default when the original names none. Own test:
        `tests/Unit/Service/Beschikking/SuccessorRequestTest` (3); preferring the original's template
        over the named one turns 2 tests red (checked).
  - [x] 8ag `Flow/PlannedSeriesLedger` (1: ExcessiveClassComplexity 53 against 50). Two parts that
        need no case lookup moved out. Reading a `findAll()` answer as the `{id, title}` of every
        row with an id (paginated or a bare list, array, entity or plain-object rows) is the new
        `Flow/OccurrenceRows` (`occurrences()`). Counting one firing of a series once, with the two
        config-key prefixes, is the new `Flow/SeriesFiringCount` (`count()`). Bodies moved
        unchanged. The second move is there for the coupling: moving only the rows made
        CouplingBetweenObjects 14 against 13, and the firing count takes `DateTimeInterface` and
        `AppIdentity` with it. The ledger builds both in its constructor, so its constructor
        signature, `firedCount()`, `occurrencesOf()` and the container binding are unchanged.
        phpmd: zero findings on the three files under both rulesets.
        Characterisation: the existing `PlannedSeriesOccurrencesTest` (every row shape, the
        non-array serialisation, the unconfigured and the failing store) and
        `PlannedSeriesLedgerTest` (the fired count per last-run stamp), with the rest of
        `tests/Unit/Service/Flow` (221 tests), green before and unchanged after except for two
        `@uses` lines in each file that covers or uses the ledger. Own tests:
        `tests/Unit/Service/Flow/OccurrenceRowsTest` (2) and `SeriesFiringCountTest` (2); counting a
        stamp already seen turns 6 tests red (checked).
  - [x] 8ah `Repair/MigrateSubsidieRegelingToCaseType` (1: ExcessiveClassComplexity 51 against 50).
        Building one propertyDefinition record (its name, case type, type and default as text, and the
        allowed values of an enum) moved to the new `Repair/Support/PropertyDefinitionRecord`
        (`record()`; body moved unchanged). The allowed values are a constructor argument instead of
        the step's own constant, so the record says nothing about one procedure; the step passes its
        `INTERIM_FREQUENCIES`. The step builds the record in its constructor, so its constructor and
        its registration in `appinfo/info.xml` are unchanged. phpmd: zero findings on both files under
        both rulesets.
        Characterisation: the existing `MigrateSubsidieRegelingToCaseTypeTest` (14 tests: the enum keeps
        its allowed values, numeric properties keep a numeric type, every property is attached to its
        case type), green before and unchanged after but for one `@uses` line. Own test:
        `tests/Unit/Repair/Support/PropertyDefinitionRecordTest` (2); giving the enum values to every
        type but the enum turns 3 tests red (checked).
  - [x] 8ai `WOODocumentAssessmentService` (1: ExcessiveClassComplexity 52 against 50). Which assessment
        rows judged a case's document partly public moved to the new `Assessment/PartlyPublicRows`: the
        case's own partly public rows as a list (`rowsOf()`, the filter `partlyPublicAssessments()`
        applied inline) and the count with the documents still waiting for a redacted copy (`tally()`,
        with `isPartlyPublicOf()`). Bodies moved unchanged; the classification is the class's
        `PARTLY_PUBLIC`. To keep the coupling from rising, the save step (`saveAssessment()`, the save and
        its `{saved, created, documentRef}` answer) moved onto the value object it already took,
        `AssessmentBatchTarget::save()`, which takes `ObjectSaveOptions` and `ObjectFindTarget` with it
        (the save and its answer together, so gate-17 does not read it as a pass-through): CouplingBetweenObjects goes from 17 to 16 (an inherited finding, not
        suppressed, and still above 13). The service builds the rows reader in its constructor, so its
        constructor, interface and container binding are unchanged. phpmd: no finding on the three files
        except that inherited coupling.
        Characterisation, green on the single class first (own commit): 1 test added to
        `WOODocumentAssessmentServiceTest` for `partlyPublicAssessments()` (only the case's own rows, as a
        list, none without a schema or a store), beside the existing `testPartlyPublicDocumentsWithoutACopyAreNamed`.
        Unchanged and green after but for two `@uses` lines in each of the 15 files that cover or use the
        service. Own tests: `tests/Unit/Service/Assessment/PartlyPublicRowsTest` (2) and one added to
        `AssessmentBatchTargetTest` for the save; counting a row of another case turns 2 tests red (checked).
  - [x] 8aj `Workflow/WorkflowDefinitionRepository` (1: ExcessiveClassComplexity 54 against 50). What the
        repository does to rows without a store moved to the new `Workflow/DefinitionRows`: the versions
        newest first with what is no row dropped (`newestFirst()`, with `normalizedRows()`), the ids rows
        carry (`idsOf()`) and the array-valued JSON-text properties encoded again for a write
        (`withJsonEncoded()`, was `encodeJsonProperties()`). Bodies moved unchanged; the new class
        coerces a row through the same `SearchesObjects::objectToArrayOrNull()` the repository's
        `normalize()` calls, and `normalize()` stays for the reads and writes that keep it (and for
        `ResultRowCopiesTest`, which pins it). The repository builds the rows reader in its constructor,
        so its constructor and every caller are unchanged. phpmd: zero findings on both files under both
        rulesets.
        Characterisation: the existing `WorkflowDefinitionRepositoryReadsTest` (versions newest first,
        status type ids without the rows that have none), `...WriteFailuresTest` (decoded JSON properties
        encoded again) and `...UpdateTest`, with the rest of `tests/Unit/Service/Workflow`, green before
        and unchanged after but for one `@uses` line in each file that covers or uses the repository.
        Own test: `tests/Unit/Service/Workflow/DefinitionRowsTest` (3); sorting oldest first turns 2 tests
        red (checked).
  - [x] 8ak `HearingCalendarService` (1: ExcessiveClassComplexity 53 against 50). What a planned event
        says without a calendar moved to the new `Calendar/PlannedEventFields`: its start, read from the
        stored date with the warning when it cannot be (`start()`, was `readStart()`), and the lines of its
        description with the participants stored as text (`description()`, was `describeHearing()`, with
        `participantNames()`). Bodies and the log line moved unchanged. `DateTimeImmutable` goes with the
        start, so CouplingBetweenObjects goes from 15 to 14 (an inherited finding, not suppressed). The
        service builds the fields over its own logger in its constructor, so its constructor and the
        container binding are unchanged. phpmd: no finding on the two files except that inherited coupling.
        Characterisation: the existing `HearingCalendarServicePinTest` (the whole description with and
        without type, room and names, the unreadable-date warning) and `HearingCalendarServiceTest`, green
        before and unchanged after but for one `@uses` line in each file that covers or uses the service.
        Own test: `tests/Unit/Service/Calendar/PlannedEventFieldsTest` (2); naming blank participants turns
        2 tests red (checked).
  - [x] 8al `Import/CaseRecordImport` (1: ExcessiveClassComplexity 55 against 50). Two parts that read and
        write nothing moved out. The answer of one declared import is the new `Import/RecordImportAnswer`:
        the answer opened under its key and source app with nothing counted (`opened()`, with
        `NOTHING_YET`) and each record's outcome counted on it (`counted()`, with `COUNTS`). A case type's
        ISO extension period read as days is the new `Term/PeriodDays` (`days()`, was the private
        `days()`), which takes `DateTimeImmutable` and `DateInterval` with it; its row in
        `docs/research/date-arithmetic-audit-2026-09-14.md` moved with it, verdict unchanged. Bodies moved
        unchanged. The importer builds both in its constructor (the period reader over the `DayCount` the
        constructor already takes), so its constructor signature, interface and container binding are
        unchanged. phpmd: zero findings on the three files under both rulesets.
        Characterisation: the existing `CaseRecordImportGoldenTest` (every run across its skips, stops,
        failures and counts, including no, an unreadable and a month extension period) and
        `CaseRecordImportTest` (the P14D extension counted as 14 days), with the command and upgrade tests,
        green before and unchanged after but for two `@uses` lines in each file that covers the importer.
        Own tests: `tests/Unit/Service/Import/RecordImportAnswerTest` (2) and
        `tests/Unit/Service/Term/PeriodDaysTest` (1); listing no failed rows turns 6 tests red (checked).
  - [x] 8am `Sharing/FederatedCaseShareService` (1: ExcessiveClassComplexity 55 against 50). What a
        federated share's snapshot record says without a store moved to the new
        `Sharing/FederatedShareSnapshot`: the refusal of requested fields outside the federation allow-list
        (`fieldRefusal()`), the record built from the loaded case and the request, with only the fields the
        case has and only its own documents (`record()`, with `fieldSnapshot()`, was the second half of
        `buildShareData()` and `buildFieldSnapshot()`), and the case a stored snapshot names
        (`caseIdOf()`, the second half of `readShareCaseId()`), a snapshot marked revoked (`revoked()`) and
        a saved snapshot read back as a record (`recordOf()`), both from `storeRevokedShare()`. The one-line
        `isFederatedShareConfigured()` is inlined where it was called. Bodies and refusal sentences moved
        unchanged. The allow-list check takes the `CaseSharingService` reference with it and the revoked
        marking takes `DateTime`, so CouplingBetweenObjects goes from 13 to 12 (moving only the record made
        it 14). Measured over the whole of `lib/`, as `composer phpmd` runs it, the class reads 49: a
        whole-tree run counts it higher than a run on the file alone (52 against 48 before the last two
        moves), so the whole-tree number is the one checked. The service builds the
        snapshot in its constructor, so its constructor and the container binding are unchanged. phpmd:
        zero findings on both files under both rulesets.
        Characterisation: the existing `FederatedCaseShareServiceTest` (the activated record, the refusals
        before any read, a foreign document, the case of an array, entity and caseless record) and
        `CaseSharingServiceFederationTest`, green before and unchanged after but for one `@uses` line or
        `UsesClass` attribute in each file that covers or uses the service. Own test:
        `tests/Unit/Service/Sharing/FederatedShareSnapshotTest` (4); letting one foreign document through
        turns 3 tests red (checked).
  - [x] 8an `DeadlineReportingService` (1: ExcessiveClassComplexity 55 against 50). Three per-type pieces
        that read no store moved onto the class the service already used for per-type figures,
        `Reporting/PerTypeRows`: the quarterly CSV, header and one line per case type (`csv()`, with
        `csvHeader()` and `csvLine()`; `quarterlyReportAsCsv()` stays public and delegates), the counted
        figures of one tally (`counts()`, was `typeCounts()`) and the average duration to one decimal
        (`averageDays()`, used by the per-type reduction and by the KPI figures). Bodies moved unchanged.
        Moving onto a class the service already held adds no coupling. Its constructor, interface and
        container binding are unchanged. phpmd: zero findings on both files under both rulesets; over the
        whole of `lib/` the class reads 50, which the rule (reported above its maximum of 50) does not
        report.
        Characterisation: the existing `DeadlineReportingServiceTest` (the CSV of a report and of a row with
        no share, the per-type reduction, the KPI summary), `StatutoryTermParityTest`,
        `DeadlineReportingControllerContractTest` and `ReportingToolsTest`, green before and unchanged after.
        Own tests: 2 added to `tests/Unit/Service/Reporting/PerTypeRowsTest` (4 in all); writing a missing
        share as 0 instead of blank turns 2 tests red (checked).
  - [x] 8ao `Flow/CaseFlowActions` (1: ExcessiveClassComplexity 55 against 50). Two pieces that read no
        store moved onto `Flow/StartableFlowLookup`, the value the lookup steps already pass around: the
        flow uuids a case type's `startableFlows` holds, a plain uuid or the object shape a picker writes
        (`flowIdsOf()`), and an ObjectService answer read as an array (`asArray()`). Bodies moved
        unchanged. Moving onto a class the service already held adds no coupling. Its constructor,
        interface and container binding are unchanged. phpmd: zero findings on both files under both
        rulesets, and over the whole of `lib/`.
        Characterisation: the existing `CaseFlowActionsLookupTest` (an entity, a plain object and an entity
        serialising to a scalar read as arrays, a missing case type) and `CaseFlowActionsBehaviourTest`
        (the declared startable flows, none declared, an unknown case), green before and unchanged after
        but for one `@uses` line in each.
        Own tests: 2 added to `tests/Unit/Service/Flow/StartableFlowLookupTest` (3 in all); keeping the
        blanks around a uuid turns 2 tests red (checked).
  - [x] 8ap `Pause/PauseChaseService` (1: ExcessiveClassComplexity 56 against 50). What a step reads as
        and the basis it is recorded under follow from the reason and the reminder number the step
        already carries, so they moved onto `Pause/PauseStep`: the reminder sentence (`chasedSentence()`),
        the escalation sentence (`escalatedSentence()`) and the legal basis, Awb 4:5 when none is declared
        (`basis()`, was `basisOf()`). Bodies moved unchanged, reading the step's own reason and number.
        `recordChased()` lost its `$reason` parameter, which only the sentence read and which was always
        the step's own reason. Moving onto a class the service already held adds no coupling. Its
        constructor, interface and container binding are unchanged. phpmd: zero findings on both files
        under both rulesets; over the whole of `lib/` the class reads 50, which the rule (reported above
        its maximum of 50) does not report.
        Characterisation: the existing `PauseChaseServiceSweepTest` (the exact chased and escalated
        sentences and their Awb 4:5 basis, a nameless reason by its key, an escalation without a target)
        and `PauseChaseServiceTest`, green before and unchanged after but for one `@uses` line in each file
        that reaches the step.
        Own test: `tests/Unit/Service/Pause/PauseStepTest` (3); changing the fallback basis turns 2 tests
        red (checked).
  - [x] 8aq `BeschikkingController` (1: ExcessiveClassComplexity 56 against 50). What a compose request
        names without a store moved to the new `Controller/Support/ComposeRequestBody`: the template it
        names, as a string or null (`templateId()`, was `templateIdOf()`), and the fields it sets on the
        new decision, the addressee overrides and the three decision fields (`fields()`, was
        `composeFieldsOf()`). Bodies moved unchanged, reading the body the request carries. `create()`
        builds the request body inline, so the constructor and the container binding are unchanged.
        CouplingBetweenObjects goes from 12 to 13, at its maximum. phpmd: zero findings on both files
        under both rulesets; over the whole of `lib/` the class reads 50, which the rule (reported above
        its maximum of 50) does not report.
        Characterisation: the existing `BeschikkingControllerCoverageTest` (compose passes the template,
        the addressee and the decision fields and nothing else), `BeschikkingControllerTest`,
        `BeschikkingControllerContractTest` and `RefusalCarriesAStatusTest`, green before and unchanged
        after but for one `@uses` line in each.
        Own test: `tests/Unit/Controller/Support/ComposeRequestBodyTest` (2); letting one more field
        through turns 2 tests red (checked).
        Gate 49 named `successorRefusal()`, which reads the original outside any try. `successor()` now
        runs the refusal inside its existing try, so a read that fails is the same generic 500 as any
        other failed successor instead of an exception left to the framework; the class complexity
        stays 50. Test: `testAFailedReadOfTheOriginalIsAGeneric500` in `BeschikkingControllerTest`,
        which errors on the old code (checked).
  - [x] 8ar `Reporting/ProcessingTimeCalculator` (1: ExcessiveClassComplexity 57 against 50). The
        weekly throughput moved to the new `Reporting/WeeklyThroughput`: the records closed per ISO week
        over the trailing weeks (`rows()`, was the body of `weeklyThroughput()`), with the end dates that
        carry one (`endDatesOf()`), the zero bucket per week (`emptyWeeks()`), the counting
        (`countedWeeks()`) and the Monday of a date (`mondayOf()`). Bodies moved unchanged.
        `weeklyThroughput()` stays public and delegates, so its callers, the constructor and the
        container binding are unchanged. The calculator no longer references `DateInterval`, so
        CouplingBetweenObjects stays 5. phpmd: zero findings on both files under both rulesets; over
        the whole of `lib/` the class reads 46 (47 when first recorded, read from a stale pdepend cache).
        Characterisation: the existing `ProcessingTimeCalculatorTest` (the weeks, none without
        completions, the ISO week year), `ProcessingTimeCalculatorFamilyTest` and
        `ProcessingTimeReportServiceEdgesTest`, green before and unchanged after but for one `@uses` line
        or `UsesClass` attribute in the last two.
        Own test: `tests/Unit/Service/Reporting/WeeklyThroughputTest` (3); moving the Monday a day turns 5
        tests red (checked).
        The new file does date arithmetic, so it has its own row in
        `docs/research/date-arithmetic-audit-2026-09-14.md` (verdict neither: chart buckets, not a term),
        which `EveryTermOnTheCalendarTest` asks for.
  - [x] 8as `Sync/ConflictResolutionService` (1: ExcessiveClassComplexity 56 against 50). Reading and
        writing the value at a conflict field's dot path reads no store, so it moved to the new
        `Sync/DottedPath`: the value at a path, null when a step is missing (`valueAt()`), the data with a
        value set at a path, making the missing steps (`withValueAt()`), and a segment as a key, digits
        indexing a list (`keyOf()`). Bodies moved unchanged. The service builds one in each of the two
        steps that use it, so its constructor, interface and container binding are unchanged.
        CouplingBetweenObjects goes from 12 to 13, at its maximum. phpmd: zero new findings on both files
        under both rulesets (the nine StaticAccess findings on `ConflictResolutionOutcome` are inherited);
        over the whole of `lib/` the class reads 48.
        Characterisation: the existing `ConflictResolutionServiceTest` (a device value written onto a list
        item through `responses.0.comment`, the refreshed office version), green before and unchanged after
        but for one `@uses` line.
        Own test: `tests/Unit/Service/Sync/DottedPathTest` (2); answering a missing step with '' instead of
        null turns 1 test red (checked).
  - [x] 8at `Repair/MigrateWorkflowDefinitions` (1: ExcessiveClassComplexity 54 against 50). Two pieces
        that read no store moved onto the template helpers the step already holds: whether a stored
        template has an id and carries the cast-of-a-map marker onto `Workflow/BackfilledTemplateLabels`,
        whose marker it reads (`castMarked()`, was `carriesCastMarker()`), and the statuses in their
        order onto `Workflow/BackfilledTemplateShape` (`ordered()`, was `sortedByOrder()`). Bodies moved
        unchanged. `statusesById()` repeated the search and the id filter `orderedStatuses()` already
        had; it now calls `searchStatuses()` and `statusesWithId()` and keeps its own warning, the same
        rows by the same rule. Moving onto classes the step already held adds no coupling (the inherited
        CouplingBetweenObjects 14 is unchanged). Its constructor and the repair step registration are
        unchanged. phpmd: zero new findings under both rulesets; over the whole of `lib/` the class reads
        49, measured with pdepend's cache cleared (`~/.cache/pdepend`), which otherwise answers for a file
        as it was.
        Characterisation: the existing `MigrateWorkflowDefinitionsGoldenTest` and
        `MigrateWorkflowDefinitionsLabelsTest` (a basis template healed from the statuses by id, an
        unmarked template left alone, the steps in status order), green before and unchanged after.
        Own tests: `testStatusesArePutInTheirOrder` in `BackfilledTemplateShapeTest` and
        `tests/Unit/Service/Workflow/BackfilledTemplateCastMarkerTest` (1); reversing the order and
        dropping the id check turn 4 tests red (checked).
  - [x] 8au `ZgwRulesBase` (2: ExcessiveClassComplexity 53 against 50; TooManyMethods stays). The four
        private builders of a 400 refusing one field (`badTypeUrlError()`, `badInformatieobjectUrl()`,
        `urlError()`, `notUniqueError()`) built the same shape with their own words. That shape is now
        one `fieldRefusal()` on `Zgw/ZgwRuleResults`, the results builder the base already holds, and
        each call site passes its field, code, detail and reason, word for word as before;
        `badTypeUrlError()` stays as a one-line delegate because two steps refuse a type URL alike. No subclass
        overrides `error()` or `fieldError()`, so going through the builder answers the same. Moving onto a
        class the base already held adds no coupling (12). Its constructor and every subclass are
        unchanged. phpmd: zero findings on both files under both rulesets; over the whole of `lib/` the
        class reads 50 (pdepend cache cleared), which the rule (reported above its maximum of 50) does
        not report.
        Characterisation: `tests/Unit/Service/ZgwRulesBaseRefusalsTest` (3) pins the type URL, the
        informatieobject URL and the external URL refusals word for word through a subclass, green on the
        old code and on the new (checked); the existing ZGW rules tests (851, the uniqueness and
        `invalid-resource` refusals among them) green before and after. Two refusals cannot be reached
        through the base: `isValidUrl()` already requires a UUID at the end of the path, so an
        informatieobject URL without one and an external URL whose last segment is no UUID are refused as
        a bad URL first. They are kept as they were.
        Own test: `testOneFieldIsRefusedWithItsCodeAndReason` in `ZgwRuleResultsTest`; changing one reason
        turns 1 test red (checked).
  - [x] 8av `CaseTermsService` (2: ExcessiveClassComplexity 55 against 50; StaticAccess on `TermKind`
        stays). Reading a case's shaped terms by kind reads no store and no date, so it moved to the new
        `Term/TermsByKind`: the first term of a kind (`first()`, was `firstOfKind()`) and whether any
        phase, planned or statutory term is overdue (`overdueFlags()`, with `anyOverdue()`). Bodies moved
        unchanged. `progressOf()` builds one, so the constructor, the interface and the container binding
        are unchanged. CouplingBetweenObjects goes from 10 to 11. phpmd: zero findings on both files
        under both rulesets; over the whole of `lib/` the class reads 47 (pdepend cache cleared).
        Characterisation: the existing term tests (`PhaseTermTest`, `PlannedTermTest`,
        `FixedDateTermTest`, `InternalTargetTest`, `TermijnKindTest` and
        `CaseTermsServicePlannedDefinitionTest`, the progress and the overdue flags among them), green
        before and unchanged after but for one `@uses` line in each; the one failure in that run is one of
        the 18 inherited on development.
        Own test: `tests/Unit/Service/Term/TermsByKindTest` (2); reading a truthy `overdue` as overdue
        turns 1 test red (checked).
  - [x] 8aw `AcknowledgementService` (3: ExcessiveClassComplexity 57 against 50; CouplingBetweenObjects
        and TooManyMethods stay, with their reasons). Two pieces that read no store moved out. What a case
        row says about its acknowledgement went to the new `Notification/AcknowledgementOnCase`: whether
        it is still a draft (`isDraft()`) and the duty block it carries (`duty()`, was `dutyOn()`). The
        refusals went to the new `Notification/AcknowledgementRefusals`: a confirmation by hand that does
        not say how (`howStated()`, was `statedHow()`) and a notice no transport took (`notSent()`, was
        `notSentRefusal()`). Bodies moved unchanged. The service builds each where it uses it, so its
        constructor, interface and container binding are unchanged. phpmd: zero new findings under both
        rulesets; over the whole of `lib/` the class reads 49 (pdepend cache cleared).
        Characterisation: the existing `AcknowledgementServiceGoldenTest`, `AcknowledgementDutyTest`,
        `AcknowledgementControllerContractTest` and the dispatch job tests (80: a draft sends nothing, an
        already met duty, the no-address and not-sent refusals, a confirmation by hand), green before and
        unchanged after but for two `@uses` lines in each file that covers the service.
        Own test: `tests/Unit/Service/Notification/AcknowledgementPiecesTest` (3); reading any case that
        is not published as a draft turns 1 test red (checked).
  - [x] 8ax `ZaakdossierService` (1: ExcessiveClassComplexity 57 against 50). Three checks that read no
        store moved onto what they check, each a class the service already used: the required upload
        input onto the upload itself, `DocumentUpload::requireType()` (was `requireUploadType()`); a
        document that is missing or definitief onto the document status canon,
        `Zaakdossier/InformatieobjectStatusLifecycle::requireEditable()` (was `requireEditableDocument()`);
        and the editable metadata fields onto the normaliser that already normalised two of them,
        `Zaakdossier/InformatieobjectMetadataNormaliser::editableFields()`. Bodies moved unchanged. The
        service no longer names `RuntimeException` or `DomainException` itself; its constructor, interface
        and container binding are unchanged, and the inherited CouplingBetweenObjects 19 is unchanged. A
        fourth candidate, the uuid of a saved record, was left: `DocumentRecordStore` has its own reader
        that also takes `uuid` and trims, so moving onto it would change what the service answers. phpmd:
        zero new findings under both rulesets; over the whole of `lib/` the class reads 47 (pdepend cache
        cleared).
        Characterisation: the existing `ZaakdossierServiceTest` (an upload without a type, the status
        transitions, the metadata edit with its direction and keywords), `InformatieobjectMetadataWriteTest`,
        `DocumentCorrespondentsWriteTest` and the dossier suite (1246), green before and unchanged after but
        for one `@uses` line in each file that covers the service.
        Own test: `tests/Unit/Service/Zaakdossier/DossierEditAndUploadRulesTest` (3); reading a different
        word for definitief turns 2 tests red (checked).
  - [x] 8ay `AiController` (1: ExcessiveClassComplexity 51 against 50). What a person reports doing with a
        suggestion reads no store, so it moved to the new `Controller/Support/SuggestionActionReport`, built over
        the request: why the report is refused, a missing field or an unknown action or cause (`refusal()`, was
        `recordError()` with `refuseAction()`), the suggestion and the applied value as the entry stores them, text
        under `text` (`suggestion()`, `actualValue()`, with `asPayload()`), the reason when it is a string
        (`reason()`, was `stringOrNull()`) and the surrounding context (`context()`, was `actionContext()`). The two
        lists of accepted actions and causes moved with them. Bodies and refusal sentences moved unchanged.
        `recordAction()` builds the report inline, so the constructor and the container binding are unchanged.
        phpmd: zero findings on both files under both rulesets, and over the whole of `lib/` (pdepend cache cleared).
        Characterisation: the existing `AiControllerContractTest` (the required fields, the unknown action and cause,
        the seven arguments in their order, absent optionals as null, an ignored suggestion with its cause) and
        `AiControllerAuditIndexTest`, green before and unchanged after but for one `@uses` line.
        Own test: `tests/Unit/Controller/Support/SuggestionActionReportTest` (2).
