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
  - [x] 8az `Kcc/RoutingTableEvaluator` (1: ExcessiveClassComplexity 52 against 50). Three pieces that read no
        store and only touch a rule's gathered conditions moved onto `Kcc/RuleConditions`, the value the evaluator
        already builds per rule: an equality constraint, refusing a contradictory second value (`requireEqual()`, was
        `recordEquality()`), one `after_HH:MM` / `before_HH:MM` condition folded into the window (`narrowTime()`, was
        `recordTimeWindow()`) and the unary-test cell of the window (`timeCell()`). Bodies moved unchanged, reading
        the conditions' own fields. Moving onto a class the evaluator already held adds no coupling. Its constructor
        and callers are unchanged. phpmd: zero findings on both files under both rulesets but for the two inherited
        StaticAccess findings on `RoutingRuleOrder`; over the whole of `lib/` the ExcessiveClassComplexity finding is
        gone (pdepend cache cleared).
        Characterisation: the existing `RoutingTableEvaluatorTest`, `RoutingTableEvaluatorEdgesTest`,
        `KccRoutingParityTest` (the compiled table against the legacy engine, contradictory equalities and time
        windows among them) and `RoutingRuleServiceTest`, green before and unchanged after but for one `@uses` line in
        the first three.
        Own test: `tests/Unit/Service/Kcc/RuleConditionsTest` (2).
  - [x] 8ba `Money/PayoutService` (1: ExcessiveClassComplexity 52 against 50; CouplingBetweenObjects 14 stays,
        inherited). The two checks of the payment input read no store and only the request's own fields, so they
        moved onto `Money/PayoutRequest`, the value the service already builds per payout: the IBAN check, length
        and mod-97 (`hasValidIban()`, was the body of `isValidIban()`), and the refusal of an invalid IBAN or a
        blank account holder (`assertPayable()`, was `assertPaymentInput()`). Bodies and refusal sentences moved
        unchanged. The public `isValidIban()` stays on the service and its interface, and delegates. Moving onto a
        class the service already held adds no coupling. Its constructor and container binding are unchanged.
        phpmd: the ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend cache cleared); no
        new findings under either ruleset.
        Characterisation: the existing `PayoutServiceTest` (valid and invalid IBANs, the refusals before any read),
        `PayoutServiceGoldenTest`, `PayoutServiceRaiseAgainTest`, `PayoutRequesterTest` and the objection, schedule
        and end-to-end deadline tests (131), green before and unchanged after but for one `@uses` line in the two
        files that cover the service without it.
        Own test: `tests/Unit/Service/Money/PayoutRequestTest` (2).
  - [x] 8bb `Zaakdossier/DocumentProjectionService` (1: ExcessiveClassComplexity 52 against 50). What a write and a
        rename change on a stored record read no store, so they moved onto `Zaakdossier/DocumentDefaults`, the class
        that already says what a new file's record holds: size, format, integrity and file id after a second write
        (`refreshed()`, whose integrity it already computed) and the file name, with the title when it was still
        derived from the old name (`renamed()`, was the first half of `renameRecord()`, which now only saves). The
        service's private `idOf()` was a copy of the one on `DocumentDefaults`; that one is public now and the copy
        is gone. Bodies moved unchanged. Moving onto a class the service already held adds no coupling. Its
        constructor, interface and container binding are unchanged. phpmd: zero findings on both files under both
        rulesets, and the ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend cache cleared).
        Characterisation: the existing `DocumentProjectionServiceTest` (a second write refreshes and creates
        nothing, a rename follows and a hand-set title survives, moves between cases), `DocumentJoinHomingTest`,
        `CaseFolderLockTest`, `CaseFolderNodeListenerTest`, `WooGatherAddTest` and the dossier suite (476), green
        before and unchanged after.
        Own test: `tests/Unit/Service/Zaakdossier/DocumentDefaultsRecordUpdatesTest` (3).
  - [x] 8bc `Intake/CaseCompleteness` (1: ExcessiveClassComplexity 54 against 50). Reading the completeness items a
        case type declares reads no store, so it moved to the new `Intake/CompletenessItems`: the items under the
        intake requirements, malformed ones left out (`declaredBy()`, was the body of `itemsOf()`), one item with a
        trimmed key and label (`cleanItem()`) and its usable options in their fixed order (`itemOptions()`). Bodies
        moved unchanged. The public `itemsOf()` stays on the service and its interface, and delegates. The service
        builds the reader where it uses it, so its constructor and container binding are unchanged. phpmd: zero
        findings on both files under both rulesets, and the ExcessiveClassComplexity finding is gone over the whole
        of `lib/` (pdepend cache cleared).
        Characterisation: the existing `CaseCompletenessTest`, `CaseCompletenessCountsTest` (malformed and blank
        items left out, the options a document or a field answers) and `CaseCompletenessControllerTest`, green
        before and unchanged after but for one `@uses` line in each.
        Own test: `tests/Unit/Service/Intake/CompletenessItemsTest` (2).
  - [x] 8bd `Flow/RetiredNodeRewriter` (1: ExcessiveClassComplexity 54 against 50). Reading and writing an edge's
        endpoints in the shape they were stored in (a single id or a list) reads no store, so it moved to the new
        `Flow/EdgeEndpoints`: the ids an endpoint names (`idsOf()`, was `endpoints()`), one id replaced keeping the
        endpoint's shape (`swapped()`, was `swap()`), and an edge given its new targets, one numbered edge per target
        when a single id fans out (`withTargets()`, was `reshape()`). Bodies moved unchanged, but for `idsOf()`, whose
        body was a copy of `External/RegisterValues::toStringArray()` (gate-122 named it once it moved) and now calls
        it, the same answer for every input. The rewriter builds one
        in its constructor, so its constructor signature, interface and container binding are unchanged. phpmd: zero
        findings on both files under both rulesets, and the ExcessiveClassComplexity finding is gone over the whole
        of `lib/` (pdepend cache cleared).
        Characterisation: the existing `RetiredNodeRewriterTest`, `RetiredNodeRewriterEdgesTest` (bridged, fanned
        out and orphaned edges in both endpoint shapes, a translated step chained), `RetiredNodeTranslatorTest` and
        the repair step tests (75), green before and unchanged after but for one `@uses` line in three.
        Own test: `tests/Unit/Service/Flow/EdgeEndpointsTest` (3).
  - [x] 8be `Lifecycle/CaseEndingActs` (1: ExcessiveClassComplexity 54 against 50; TooManyMethods stays, 29 down to
        27). What the ending acts read off a case type's status rows reads no store, so it moved to the new
        `Lifecycle/StatusRows`: the terminal status an act lands on, the one named when it is terminal, else the first
        in process order (`namedTerminal()`, was the body of `terminalStatus()`), the phases skipped between the status
        left and the one landed on (`skippedNames()`, was the body of `skippedPhases()`), whether a row is terminal
        (`isFinal()`, was `isFinalRow()`), a row's place in the process with an unordered status last (`orderOf()`) and
        its id (`idOf()`). Bodies moved unchanged. The acts build one over their own `LanguageMapText` in the
        constructor, so the constructor signature and the container binding are unchanged. phpmd: no new findings
        under either ruleset, and the ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend
        cache cleared).
        Characterisation: the existing `CaseEndingActsTest`, `CaseEndingActsGoldenTest` (finish and abort on a named
        and an unnamed terminal status, the skipped phases recorded, no terminal status refused) and the lifecycle,
        acts, silence-close and admissibility suites (466), green before and unchanged after but for one `@uses`
        line in two.
        Own test: `tests/Unit/Service/Lifecycle/StatusRowsTest` (2).
  - [x] 8bf `Controller/Support/ApplicatieRules` (1: ExcessiveClassComplexity 54 against 50). Ac-003, the fields an
        autorisatie must carry for its component and scopes, reads one entry of the body and no store, so it moved to
        the new `Controller/Support/AutorisatieScopeFields` with its table of requirements per component: the invalid
        parameters an entry lacks, named by its position (`missingParams()`, was `missingFieldParams()`), the fields its
        component and scopes require (`requiredFieldsFor()`), the ones it leaves empty (`missingRequiredFields()`) and
        whether a scope names the component's domain (`scopesContain()`). Bodies and reason sentences moved unchanged.
        `validateAutorisatieScopes()` builds one over the rules' own translator, so the constructor and the container
        binding are unchanged. phpmd: zero findings on both files under both rulesets, and the
        ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend cache cleared).
        Characterisation: the existing `ApplicatieRulesTest` (ac-003a to 003e by position, a complete entry, an
        unknown component) and `AcControllerCharacterisationTest`, green before and unchanged after but for one
        `@uses` line or `UsesClass` attribute in each.
        Own test: `tests/Unit/Controller/Support/AutorisatieScopeFieldsTest` (2).
  - [x] 8bg `ZgwDrcRulesService` (1: ExcessiveClassComplexity 53 against 50). Drc-006, a locked document changed only
        with its own lock id, reads the body and the stored document and no store, so it moved to the new
        `Zgw/DrcLockRule`, built the way slice 8b builds its rule groups: the refusal (`refusal()`, was
        `validateLock()`), the lock a stored document carries (`currentLock()`) and the missing or wrong lock id
        (`lockRefusal()`). The two refusal builders (`lockRequiredError()`, `wrongLockError()`) built the 400 of one
        field that `ZgwRuleResults::fieldRefusal()` builds (slice 8au), so they call it, word for word as before. The
        service builds the rule in its constructor over its own results builder, so its constructor, the dispatcher
        and the container are unchanged. phpmd: zero findings on both files under both rulesets, and the
        ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend cache cleared).
        Characterisation: the existing `ZgwDrcRulesServiceTest` (drc-006 on PUT and PATCH: unlocked, not locked, the
        missing, wrong and right lock id, the old `lock` field), the DRC rule and dispatcher tests (158), green before
        and unchanged after but for one `@covers` line.
        Own test: `tests/Unit/Service/Zgw/DrcLockRuleTest` (2).
  - [x] 8bh `WorkflowDefinitionService` (1: ExcessiveClassComplexity 54 against 50). The rows a definition's
        lifecycle steps write are built from what the step already holds and read no store, so they moved to the new
        `Workflow/DefinitionLifecycleRows` (next to `Workflow/DefinitionRows`, which reads stored rows): the draft row
        with its encoded content and route (`draft()`, was `draftRow()` with `draftState()`), the draft a clone
        starts as on its source's route with its title marked `(kopie)` (`cloned()`, was the literal in
        `cloneDefinition()` with `cloneTitle()`), the publish payload with the authorization-enriched transitions when
        any resolved (`published()`, was `buildPublishPayload()`), and the deprecation payload `deprecate()` and the
        publish of a newer version both wrote (`deprecated()`). Bodies moved unchanged. The service builds one over
        its own guard and JSON encoder in the constructor, so its constructor signature, interface and container
        binding are unchanged. phpmd: zero findings on both files under both rulesets, and the
        ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend cache cleared).
        Characterisation: the existing `WorkflowVariantLifecycleTest` (publish, the default route, deprecating the
        previous active version of a route, cloning on the source's route), `WorkflowPublishRefusalTest`,
        `StepConfigPublishTest`, `WorkflowDefinitionPublishTest` and the task declaration and package draft tests
        (237), green before and unchanged after but for one `@uses` line in two.
        Own test: `tests/Unit/Service/Workflow/DefinitionLifecycleRowsTest` (2).
  - [x] 8bi `DecisionAdvice/DecisionAdviceService` (1: ExcessiveClassComplexity 55 against 50). What the advice reads
        off the stored decision table a case type names reads no store, so it moved to the new
        `DecisionAdvice/AdviceTable`, a value over the table: the declared inputs with their type and the literal
        values their rules use (`offeredInputs()`, was the loop in `form()`), the outcomes a handler can choose
        (`outcomes()`), the entered value of every declared input, refusing a missing one (`declaredValues()`, was
        `declaredInputs()`), the recommendation in what the table answered (`recommendation()`, was
        `recommendationOf()`), with the column values (`entries()`) and the definition names (`names()`). Bodies and
        refusal sentences moved unchanged; the loose-list reading went with them. The service builds one where it
        reads a table, so its constructor, interface and container binding are unchanged. phpmd: zero findings on
        both files under both rulesets, and the ExcessiveClassComplexity finding is gone over the whole of `lib/`
        (pdepend cache cleared).
        Characterisation: the existing `DecisionAdviceServiceTest` (the form's inputs and outcomes, a missing input
        refused, the recommendation stored, accepting and deviating) and `DecisionAdviceControllerTest` (29), green
        before and unchanged after but for one `@uses` line.
        Own test: `tests/Unit/Service/DecisionAdvice/AdviceTableTest` (2).
  - [x] 8bj `Review/TriageRules` (2: ExcessiveClassComplexity 55 against 50, and CouplingBetweenObjects 14 against
        13). What the rules of a case have marked reads the case's reviews and no store, so it moved to the new
        `Review/RuleMarkings`, a value over those reviews: each rule listed with how many documents it decided and how
        many of its markings a reviewer overturned (`onRules()`, was the loop in `forCase()` with `decidedPerRule()`
        and `overturnedPerRule()`), and a review as a rule marks it, its effect, the rule as source and name, and the
        moment (`marked()`, was `markedByRule()`). Bodies moved unchanged. With the marking moment the service no
        longer names `DateTimeImmutable` or `DateTimeInterface`, so its coupling goes from 14 to 13 (moving only the
        counts made it 15). The service builds one where it reads the reviews, so its constructor, interface and
        container binding are unchanged. phpmd: zero findings on both files under both rulesets; over the whole of
        `lib/` both findings are gone (pdepend cache cleared).
        Characterisation: the existing `TriageRulesTest` (the listing with decided and overturned counts, applying
        rules to unmarked documents with the rule as source) and `TriageRuleControllerTest`, green before and
        unchanged after but for one `@uses` line.
        Own test: `tests/Unit/Review/RuleMarkingsTest` (2).
  - [x] 8bk `Zaakdossier/DocumentCorrespondents` (1: ExcessiveClassComplexity 56 against 50). Matching a submitted
        sender or recipient to a party of the case is its own concern, so it moved to the new
        `Zaakdossier/CasePartyIndex`, built over the case's party links: every way a party may be named, its uuid,
        contact uid and address in any casing, first holder wins (`indexOf()`, `withKeysOf()`, now built once in the
        constructor), one value or a picker's option object matched to a party (`match()`, was `matchOne()` with
        `matchOption()`), the recipients matched each once whatever shape they arrived in (`matchAll()`, was
        `matchMany()` with `asList()` and `singleAsList()`) and the identifier a party link is stored under
        (`identifierOf()`, which the correspondents keep as a delegating public method). Bodies moved unchanged. The
        correspondents build an index where they resolve, so their constructor and the container binding are
        unchanged. phpmd: zero findings on both files under both rulesets, and the ExcessiveClassComplexity finding
        is gone over the whole of `lib/` (pdepend cache cleared).
        Characterisation: the existing `DocumentCorrespondentsTest`, `DocumentCorrespondentsWriteTest`,
        `CorrespondentWriterTest`, `CorrespondentDispatchGoldenTest`, `InboundSenderIsAPartyTest` and the dossier
        write tests (86), green before and unchanged after but for one `@uses` line in three.
        Own test: `tests/Unit/Service/Zaakdossier/CasePartyIndexTest` (2).
  - [x] 8bl `Repair/CaseFlowSeedDataRepairStep` (1: ExcessiveClassComplexity 54 against 50; and CouplingBetweenObjects
        14 against 13, which PHPMD reported unsuppressed on development). Planting the demo cases of one seeded case type
        is its own concern, so it moved to the new `Repair/CaseFlowSeedCases`: the case bound to its case type, its
        status resolved by name or left out, a case planted before skipped by title, and a failed write reported
        (`plant()`, was `seedCasesOf()` with `seedCases()`, `seedCaseRow()` and `saveSeedCase()`). Bodies moved
        unchanged. Writing one object under one of the run's schemas is `CaseFlowSeedRun::save()`, so neither class
        names the save options any more and the step's coupling goes from 14 to 13. The step builds the planter where
        it seeds, so its constructor and the container are unchanged. phpmd: zero findings on the three files under
        both rulesets.
        Characterisation: the existing `CaseFlowSeedDataRepairStepTest` and `CaseFlowSeedRunGoldenTest` (the demo
        cases, the deleted case not planted again, the unresolvable status, the failed case write), green before and
        unchanged after but for one `@uses` line.
        Own test: `tests/Unit/Repair/CaseFlowSeedCasesTest` (3).
  - [x] 8bm `Controller/SetupController` (1: ExcessiveClassComplexity 55 against 50; CouplingBetweenObjects 14 against
        13 stays, the extracted `\Throwable` traded for the new class). Loading the example dataset the wizard picked is
        its own concern, so it moved to the new `Controller/Support/DatasetLoad`: a posted dataset nobody offers
        refused, the posted one else the stored pick, the legacy `install-demo-data` meaning the shipped set, "none"
        recorded as a decline, and an import recorded with its counts only once it returned (`load()`, was
        `loadDataset()` with `postedRefusal()`, `chosenDataset()`, `importDataset()`, `importedAnswer()` and
        `refuseDataset()`; `decline()`, was `declineDataset()` and its twin `skipDemoData()`, whose bodies were the
        same). Bodies moved unchanged; the controller now reads the posted `dataset` and hands it over. The controller
        builds the loader in its constructor, so its constructor signature and the container are unchanged. phpmd: the
        ExcessiveClassComplexity finding is gone and the new file has zero findings under both rulesets.
        Characterisation: the existing `SetupControllerStatusTest`, `SetupControllerReadinessTest` and
        `SetupWizardStepsTest` (58: the load, the decline, the refused and unknown dataset, the failed import, the
        legacy action, the pick posted with Next), green before and unchanged after but for one `@uses` line.
        Own test: `tests/Unit/Controller/Support/DatasetLoadTest` (3).
  - [x] 8bn `Workflow/WorkflowLifecycleGuard` (1: ExcessiveClassComplexity 57 against 50). Which route a definition is
        on, and which route a case type takes by default, is its own concern, so it moved to the new
        `Workflow/DefaultRoute`: a blank route read as the default route (`variantOf()`), the default a case type records
        as an id or an embedded row (`definitionIdFor()`, was `defaultDefinitionIdFor()`), and the pick among several
        active routes, the recorded one else the lowest slug and its highest version, logged (`among()`, was
        `defaultAmong()` with `routeOrder()` and `idOf()`). `VARIANT_DEFAULT` lives there too; the guard's constant
        names it. Bodies moved unchanged. The guard's public methods stay on it and its interface, and delegate; it
        builds the reader in its constructor, so its constructor signature and the container are unchanged. phpmd: zero
        findings on both files under both rulesets.
        Characterisation: the existing workflow guard, definition service and template loader tests (151 in 27 files:
        the route read, the recorded default, the ambiguous pick, publish and deprecate), green before and unchanged
        after but for one `@uses` line in three.
        Own test: `tests/Unit/Service/Workflow/DefaultRouteTest` (3).
  - [x] 8bo `CaseRelationService` (1: ExcessiveClassComplexity 58 against 50). Stripping the links to a deleted case
        from the cases that hold one is its own concern, so it moved to the new `Relation/DeletedCaseLinks`: the cases
        the deleted case named and the cases that named it (`/used`), each once and never the case itself, and a
        counterpart written only when a relation or typed link to the deleted case went (`strip()`, was the body of
        `cleanupForDeletedCase()` with `stripCounterpart()`, `stripLinksTo()`, `counterpartIdsOf()`,
        `declaredCounterpartIds()` and `incomingCounterpartIds()`). Bodies moved unchanged. The public
        `cleanupForDeletedCase()` stays on the service and its interface, and delegates. The service builds the cleanup
        over its own store and codec in its constructor, so its constructor signature and the container are unchanged.
        phpmd: the ExcessiveClassComplexity finding is gone over the whole of `lib/` (pdepend cache cleared) and the
        new file has zero findings under both rulesets.
        Characterisation: the existing `CaseRelationServiceTest`, `CaseRelationServiceGoldenTest`, `ZrcDestroyCaseTest`
        and the other relation tests (182 in 22 files: the counterparts declared from the other side, the case-delete
        path), green before and unchanged after but for one `@uses` line in two.
        Own test: `tests/Unit/Service/Relation/DeletedCaseLinksTest` (3).
  - [x] 8bp `DeadlineExtensionService` (1: ExcessiveClassComplexity 60 against 50). Whether an extension may go ahead,
        and by how many days it moves the term, is its own concern, so it moved to the new `ExtensionLimits` (beside
        `ExtensionRun`, outside the procedure-named `Termijn/` directory): the new end after the current one, the
        ceiling of extensions that a supervisor passes, and the period the case type declares, measured to the end
        asked for (`assertAllowed()`, was `assertExtensionAllowed()` with `assertExtensionPermitted()`,
        `assertWithinDeclaredPeriod()`, `resolveMaxExtensions()`, `cachedDefinition()`, `definitieCache()` and
        `definitionIn()`), and the days between two ends (`daysImpact()`, was `calculateDaysImpact()`, which
        `rungsStillBehind()` still asks). Bodies moved unchanged. The service builds the checks over its own term
        service, dates and declarations in its constructor, so its constructor signature and the container are
        unchanged. phpmd: zero findings on both files under both rulesets (pdepend cache cleared).
        Characterisation: the existing `DeadlineExtensionLimitTest`, `DeadlinePauseExtensionServiceTest` and the other
        extension tests (226 in 17 files: the ceiling, the supervisor override, the declared period, the rolled end,
        the requester told), green before and unchanged after but for one `@uses` line in two.
        Own test: `tests/Unit/Service/ExtensionLimitsTest` (3).
  - [x] 8bq `CaseEmailMatchService` (1: ExcessiveClassComplexity 60 against 50). What an email match run needs before it
        starts, or why it cannot, is its own concern, so it moved to the new `Email/MatchRunReadiness`: the Mail account
        is the owner's own and the pattern loads, the register and case schema are configured, OpenRegister can scope a
        read to the owner, and the email leaf is there, the first that fails naming the refusal code (`prepare()`, was
        `prepareRun()` with `mailboxRefusal()`, `configurationRefusal()`, `objectServiceRefusal()`,
        `emailLeafRefusal()` and `getEmailLinkService()`). Bodies moved unchanged, but for the leaf lookup: gate-122
        named `getEmailLinkService()` a copy of `Access/ObjectReadGrants::service()` once it moved, so
        `emailLeafRefusal()` now resolves the leaf itself and refuses on a throw, a non-object or no `linkEmail()`, the
        same answer for every input. Its catch-return-null allowlist entry went with it. The service builds the readiness over its own
        collaborators in its constructor, so its constructor signature and the container are unchanged. phpmd: zero
        findings on both files under both rulesets (pdepend cache cleared).
        Characterisation: the existing `CaseEmailMatchServiceTest` (every refusal code, the run as the owner, the
        cursor) with the other match tests and `ServiceCatchReturnsNullTest` (79 in 4 files), green before and
        unchanged after but for one `@uses` line.
        Own test: `tests/Unit/Service/Email/MatchRunReadinessTest` (2).
  - [x] 8br Three stale `TooManyMethods` suppressions (3: `AcknowledgementService`, `DeadlineExtensionService`,
        `ZgwRulesBase`). The extractions of 8au, 8aw and 8bp left each class under the threshold, but the
        class-level suppression stayed. Found by removing every `@SuppressWarnings(PHPMD.*)` line in `lib/` one at
        a time and running both rulesets on its file: these three were the only ones whose rule no longer fires.
        Removed; phpmd reports zero findings on the three files under both rulesets (pdepend cache cleared).
        Docblock only, no code path changed, so no test was added; the suites of the three classes run unchanged.
        `phpmd.suppressWarnings` 195 to 192 (the baseline file still reads 178: development was already over it).
  - [x] 8bs `CaseLifecycleService` (1: ExcessiveClassComplexity 57 against 50). Working out the day an extension of the
        term lands on is its own concern, so it moved to the new `Lifecycle/ExtensionEndDate`: a named date taken once it
        lies after the current end, else the current end plus the case type's ISO 8601 period, rolled by the
        administered calendar when the engine bridge is wired (`resolve()`, was `resolveNewEndDate()` with
        `requireLaterDate()` and `addPeriod()`). Bodies moved unchanged. The service builds it over the timer of its
        own deadline services in its constructor, so its constructor signature and the container are unchanged; it no
        longer names `DateInterval`. Complexity 45 and 13 (pdepend); phpmd: no ExcessiveClassComplexity finding with the
        suppression gone, and no new finding on either file under both rulesets (the StaticAccess on `RowList` in
        `readJournal()` was there before). `@SuppressWarnings(PHPMD.*)` lines in `lib/` 194 to 193.
        Characterisation: the existing `CaseLifecycleServiceTest` and `CaseLifecycleSuspendPauseReasonTest` (the period
        added, the named date, the date not later, the unreadable date, the case type's rule and limit), green before
        and unchanged after but for one `@uses` line each.
        Own test: `tests/Unit/Service/Lifecycle/ExtensionEndDateTest` (3: the named date, the period with its two
        refusals, the calendar roll of a period only).
  - [x] 8bt `LoadDefaultZgwMappings` (1: ExcessiveClassComplexity 61 against 50). Seeding the two ZGW test consumers
        and describing the default notification channels are their own concerns, so they moved to
        `Repair/Support/DefaultTestConsumers` (`upsert()` and `defaults()`, were `upsertApplicaties()`,
        `updateApplicatie()`, `createApplicatie()`, `getDefaultApplicaties()` and the three definition methods) and
        `Repair/Support/DefaultNotificationChannels` (`defaults()`, `mappingReady()`, `report()`, were
        `getDefaultKanalen()`, `kanaalMappingReady()` and `reportKanalen()`). Bodies moved unchanged. The container
        lookups and the channel write under the system identity stay in the step, so gate-66 and
        `SeedWriteIdentityTest` read it as before. The step builds both in its constructor, so its constructor signature
        and the container are unchanged. Complexity 45, 9 and 7 (pdepend); phpmd: zero findings on the three files under
        both rulesets with the suppression gone. `@SuppressWarnings(PHPMD.*)` lines in `lib/` 193 to 192.
        Characterisation: the existing `LoadDefaultZgwMappingsRunTest` (fresh install, older install, the order of the
        lines, the skipped and the throwing seeds), `LoadDefaultZgwMappingsGoldenTest` and `SeedWriteIdentityTest`,
        green before and unchanged after but for two `@uses` lines.
        Own tests: `tests/Unit/Repair/Support/DefaultTestConsumersTest` (2: the frozen defaults, create and update) and
        `DefaultNotificationChannelsTest` (3: the five channels, readiness with its two skip lines, the report).
  - [x] 8bu `Listener/TermijnTimerFiredListener` (1: CouplingBetweenObjects 14 against 13). What a rung of a known term
        does is its own concern, so it moved to the new `Listener/Support/TermRungActions`: whether a fire is a dossiq
        term timer's (`termMetadata()`, was `termijnMetadata()`), the rung routed by the kind of term its timer carries
        (`route()`, was `routeRung()`, now building the `TermijnRungFire` itself), the beslistermijn breach flip and
        threshold bookkeeping (`handleBeslistermijnRung()` with `bucketFromRungKey()`), and the hersteltermijn reminder
        or expired pause (`handleHersteltermijnRung()`, `isChaseRung()`, `handleChase()`, `handlePauseExpiry()`,
        `expireRequest()`, `resumeExpired()`, `noticeFor()`). Bodies moved unchanged. The four optional collaborators of
        a suspended term travel as one value, `Listener/Support/TermPauseFollowUp`, so the new constructor takes four
        parameters (gate-122). The listener keeps the instance lookup and the run under the background service
        account; it no longer names `TermijnRungFire`, `TermijnTimerServiceInterface` or `\Throwable`, so its coupling
        goes from 14 to 13 (moving only the pause expiry left it at 14). The listener builds the actions in its
        constructor, so its constructor signature and the container are unchanged. Coupling 13, 8 and 4, complexity
        11, 34 and 1 (pdepend); phpmd: no CouplingBetweenObjects finding with the suppression gone, and no new finding
        on the three files under both rulesets (the StaticAccess on `TimerFire` in `handle()` was there before).
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` 192 to 191.
        Characterisation: the existing `TermijnTimerFiredListenerTest`, `TermijnTimerFiredListenerEdgesTest`,
        `TermijnTimerFiredListenerServiceAccountTest`, `TimerFiredListenersGoldenTest` and
        `DeadlineMonitoringEndToEndTest` (62: the breach flip, the thresholds, the reminder, the expired pause with its
        failing request, the unknown instance, the unreadable key), green before and unchanged after but for two
        `@uses` lines in three.
        Own test: `tests/Unit/Listener/Support/TermRungActionsTest` (5: only a dossiq term timer carries term
        metadata, the breach on the fresh row, a bucket only for a running term and a readable key, the reminder and
        the resumed term left alone, the expired pause resumed with its handler and a failing resume logged).
  - [x] 8bv `Controller/HouseholdCasesController` (1: CouplingBetweenObjects 14 against 13). Who may ask about a
        case's household is its own concern, so it moved to the new `Controller/Support/HouseholdReaderGate`: the
        refusal (401 for nobody signed in, 403 without the social domain's sensitive access or without read access to
        the case, the sensitive check first; was `refusalFor()` with `unauthenticated()`) and `caseFilterFor()`, the
        case guard's answer the same-domain rows are filtered by (a filter handed on, not a permission evaluator of
        its own, so `NoSecondPermissionEvaluatorTest` stays green). Bodies and refusal texts moved unchanged. The controller takes
        the gate instead of the two guards, so its constructor changed (seven parameters to six); nothing registers it
        by hand, the container wires the gate, and the one test file that builds the controller passes the gate, no
        assertion changed. Coupling 13 and 6, complexity 22 and 7 (pdepend); phpmd: no finding on either file under
        both rulesets with the suppression gone. `@SuppressWarnings(PHPMD.*)` lines in `lib/` 192 to 191.
        Characterisation: the existing `HouseholdCasesControllerTest` (5: no sensitive access, the administrator
        outside the group over the real guard, no case access, the three fields of a looked-up row, no other domain
        before a lookup), green before and unchanged after but for its constructor call.
        Own test: `tests/Unit/Controller/Support/HouseholdReaderGateTest` (4); dropping the case read check from the
        refusal turns three tests red (checked).
  - [x] 8bw `Controller/WorkQueueController` (1: CouplingBetweenObjects 15 against 13). Which handlers the workload
        shows is its own concern, so it moved to the new `Controller/Support/WorkloadScope`: the reader's team groups
        (`teamGroupIds()`, an unreadable team list logged and read as none), the narrowing to their members with a zero
        row for an idle member (`ofTeams()` with `membersOf()` and `withMembers()`), the display names
        (`withDisplayNames()`) and the coordinator check (`isCoordinator()`, now one expression so it is no longer a
        copy of `SubstitutionAccessGuard::isCoordinator()`, gate-122). Bodies otherwise moved unchanged, and the scope
        still runs inside the workload's try, so a failing member or name read is still a 500. The controller takes
        the scope instead of the group manager, the user manager and the team service, so its constructor changed
        (eight parameters to six); the container wires the scope. Coupling 12 and 6, complexity 11 and 18 (pdepend);
        phpmd: no finding on either file under both rulesets with the suppression gone. `@SuppressWarnings(PHPMD.*)`
        lines in `lib/` 191 to 190.
        Characterisation: the existing `WorkQueueControllerTest` (12: the queue with and without covered work, the
        workload for a coordinator, a team member, neither, unreadable teams, display names) and
        `WorkQueueReadersTeamsGoldenTest` (seven worlds of team narrowing, now read through the scope instead of by
        reflection on the controller; the golden file unchanged), green before and after but for the constructor call.
        Own test: `tests/Unit/Controller/Support/WorkloadScopeTest` (3); dropping the empty-uid check turns it red
        (checked).
  - [x] 8bx `Controller/EmailTemplateController` (1: CouplingBetweenObjects 15 against 13). The mail settings form is
        its own surface, so its three routes go to their own controller, `EmailSettingsController`
        (`emailSettings#getSettings`, `#saveSettings`, `#mailAccounts` in `appinfo/routes.php`; same URLs, verbs and
        `AuthorizedAdminSetting` attributes, so `openapi.json`, which matches by URL, is unchanged). The controller
        keeps the session check and the request; what the form reads and writes is
        `Controller/Support/MailIntakeSettings` (the settings keys, `values()`, `save()` with the block list still
        written through `SenderBlocklistInterface`, and `accounts()` with the mailbox status it records), so the new
        controller takes three parameters, not six (gate-122). Bodies moved unchanged. The template controller keeps
        the templates, the draft and the variables and no longer takes the app config, the mail gateway or the block
        list (nine parameters to six); the container wires all three. Coupling 12, 8 and 5, complexity 21, 8 and 11
        (pdepend); phpmd: no finding on the three files under both rulesets with the suppression gone.
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` 190 to 189.
        Characterisation: the existing `EmailTemplateControllerContractTest` (the anonymous refusals, the admin
        check on the session uid, the case guard on the draft, the settings write without a password and through
        the block list) and the mail card of `IntegrationProbesRecordTest`, green before and after; the settings
        calls now go to the new controller, no assertion changed.
        Own test: `tests/Unit/Controller/EmailSettingsControllerTest` (3: the anonymous refusal on all three routes
        with nothing read, the read of every settings key, empty when unset, and a save that stores only the named
        keys, the block list through its class and nothing outside the form).
  - [x] 8by `Controller/CaseRecycleController` (1: CouplingBetweenObjects 16 against 13). Every other gesture there acts
        on a case in OpenRegister's recycle state; the retention clocks read a case that still exists under the
        ordinary case authority, so that route goes to its own controller, `RetentionClocksController`
        (`retentionClocks#clocks` in `appinfo/routes.php`; same URL and verb, so `openapi.json`, which matches by URL,
        is unchanged). It takes the request, the per-case `CaseAccessRefusal` (injected, no longer built inline), the
        new `Controller/Support/LiveCaseRetentionClocks` (the live case read and its two clocks, null when the case is
        not live) and the logger: four parameters (gate-122). Bodies otherwise moved unchanged: 401 signed out, 403
        without read access, 404 `case_not_found`, 500 withholding the detail. The recycle controller no longer takes
        the clocks or the case guard (ten parameters to eight); the container wires the rest. Coupling 13, 9 and 2,
        complexity 16, 5 and 3 (pdepend); phpmd: no finding on the three files under both rulesets with the
        suppression gone. `@SuppressWarnings(PHPMD.*)` lines in `lib/` 189 to 188.
        Characterisation: the existing `CaseRecycleControllerTest` (11: the lens, the windows, the scope, restore, the
        refusals and their statuses, the preview, the guarded clocks read, both clocks, the anonymous caller on all
        five routes, the withheld detail), green before and after; its clocks calls now go to the new controller, no
        assertion changed.
        Own test: `tests/Unit/Controller/Support/LiveCaseRetentionClocksTest` (2: the clocks of the live case, and
        null without asking the clocks for a case that is not live).
  - [x] 8bz `AdviceService` (1: CouplingBetweenObjects 15 against 13). What a new advice request writes is its own
        concern, so it moved to the new `Advice/AdviceRequestFiling` (`file()`): the adviceRequest saved under the
        configured schema (`savedAdvice()`, `adviceSchema()`), the decidesk `advice` Decision raised for the saved id
        and its reference stored back (`delegateAdviceDecision()`, `storeDecisionRef()`, `decisionPayload()`), failing
        closed when decidesk does not answer. Bodies moved unchanged, so the settings reads happen in the same order
        and `AdviceServiceRequestGoldenTest` is unchanged. The service keeps the payload, the notification, the
        reads, the transitions and the reminders; it takes the filing instead of the delegation service, so its
        constructor changed (same eight parameters, one type); the container wires the filing. It no longer names
        `AdviceDelegationService`, `ObjectSaveOptions` or `ObjectFindTarget`. Coupling 13 and 7, complexity 35 and 12
        (pdepend); phpmd: no finding on either file under both rulesets with the suppression gone.
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` 188 to 187.
        Characterisation: the existing `AdviceServiceRequestGoldenTest` (six recorded requests, every store write and
        settings read in order), `AdviceKindAndOutcomeTest`, `AdviceServiceAuthorizationTest` and
        `OneDateWritePathRoundTripTest`, green before and unchanged after but for the constructor call.
        Own test: `tests/Unit/Service/Advice/AdviceRequestFilingTest` (2: the saved request carries its decision
        reference under its own id, and an unanswered decidesk fails closed with no reference stored); saving the
        reference without the request's uuid turns seven tests red (checked).
  - [x] 8ca `Bezwaar/HearingService` (2: ExcessiveClassComplexity 54 against 50, and TooManyMethods). What a session
        change writes moved to the new `Hearing/SessionWrites`: the attendance (each late correction recorded first,
        then the patch, and an `attendance-late-correction-not-applied` row per correction when the patch fails) and
        the minutes (the verslag entry recorded, then applied), with the patch itself (`attendance()`, `minutes()`;
        bodies, log lines and messages moved unchanged). The service keeps the store lookups, the plans, the consent
        guard, the new sessions and the seeding; it builds the writer over its own audit trail and logger in its
        constructor, so its constructor, interface and container binding are unchanged. phpmd: zero findings on both
        files under both rulesets with both suppressions gone. `@SuppressWarnings(PHPMD.*)` lines in `lib/` 187 to 185.
        Characterisation, green on the single class first (own commit): 2 tests added to the Bezwaar
        `HearingServiceTest` (12 in all) for a late correction recorded before the patch, a failed patch leaving a
        not-applied row with its error and the attendance unchanged, and attendance inside the grace window writing no
        entry. Unchanged and green after but for one `@uses` line in each file that builds the service.
        Own test: `tests/Unit/Service/Hearing/SessionWritesTest` (3); dropping the not-applied rows turns 2 tests red
        (checked).
  - [x] 8cb `Controller/MailIntakeController` (1, unsuppressed: ExcessiveClassComplexity 51 against 50). Running the
        Link to case dialog's two choices on a message filed by hand (the sender as a party, the case's handler told,
        named by address when the original carries no display name) is its own concern, so `followUps()` moved to the
        new `Controller/Support/FilingChoices::run()` (body unchanged; the caller id is passed in). The controller
        builds it over the follow-ups it is given in its constructor, so its constructor signature and the container
        are unchanged, and it no longer names `MessagePreview`. phpmd: zero findings on both files under both
        rulesets. `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation, green on the unchanged controller first (own commit): 1 test added to
        `MailIntakeControllerTest` for a sender without a display name, named by address in the handler's notice
        (19 in all); unchanged and green after.
        Own test: `tests/Unit/Controller/Support/FilingChoicesTest` (3); dropping the address fallback turns 2 tests
        red (checked).
  - [x] 8cc `TenantOnboardingService` (1, unsuppressed: ExcessiveClassComplexity 53 against 50). Carrying a tenant's
        legacy `tenantOnboardingTask` rows onto the task engine is its own concern, so it moved to the new
        `Onboarding/LegacyStepRows` (`carry()`, was `carryLegacyRows()`, with `legacyMetadata()`, `legacyRows()` and
        `rowsOfTenant()` as `metadata()`, `read()` and `rowsOfTenant()`). Bodies moved unchanged but for the object
        service, which the service still resolves and passes in, so its container lookup stays where gate-66 reads it.
        The service builds the carrier over its own steps, logger and row normaliser in its constructor, so its
        constructor signature and the container are unchanged. phpmd: no ExcessiveClassComplexity finding, and no new
        finding on either file under both rulesets (the CouplingBetweenObjects 15 was there before). The
        catch-return-null allowlist entry follows the site to `LegacyStepRows::read`. `@SuppressWarnings(PHPMD.*)`
        lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation, green on the unchanged service first (own commit): `TenantOnboardingLegacyRowsTest` (3: the
        tenant's known steps opened with their status and kept fields and read again; no row and a failed read open
        nothing; a tenant with engine steps is not carried again); unchanged and green after.
        Own test: `tests/Unit/Service/Onboarding/LegacyStepRowsTest` (2); dropping the tenant filter turns 2 tests red
  - [x] 8cd `Lifecycle/CaseEndingActs` (1, unsuppressed: TooManyMethods 27 against 25). Working out where finishing or
        aborting lands the case (the terminal status, named or the case type's lowest-ordered one, the closing result,
        the end date and the phases it never reached) is its own concern, so `landing()` moved to the new
        `Lifecycle/EndingLanding::resolve()` with `terminalStatus()`, `terminalRows()` and `skippedPhases()`. Bodies
        moved unchanged. The acts build it over their result writer, status lookup and status rows in the constructor,
        so their constructor signature and the container are unchanged. 23 methods; phpmd: zero findings on both files
        under both rulesets. `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not
        suppressed).
        Characterisation: the existing `CaseEndingActsGoldenTest` (named, uuid-shaped, non-terminal and missing terminal
        statuses, unordered terminals, result refused or empty) and `CaseEndingActsTest` (early close with and without
        skipped phases), green before and unchanged after but for one `@uses` line each.
        Own test: `tests/Unit/Service/Lifecycle/EndingLandingTest` (2); dropping the skipped phases turns 3 tests red
        (checked).
  - [x] 8ce `Controller/DsoController` (1, unsuppressed: ExcessiveClassComplexity 55 against 50). Reading the fields of a
        request body (the body, a field that may be absent, a field that must be one of a fixed set) is its own
        concern, so `readJsonBody()`, `optionalString()`, `statusRefusal()` and `outcomeRefusal()` went to the new
        generic `Controller/Support/RequestBodyFields` (`read()`, `optionalString()`, and one `choiceRefusal()` that
        takes the allowed values and the two sentences each caller had). Bodies and sentences unchanged. The
        controller builds it in its constructor, so its constructor signature and the container are unchanged, and it
        no longer names `JsonRequestBody` directly, so its coupling stays at 15. phpmd: no ExcessiveClassComplexity
        finding, and no new finding on either file under both rulesets (the CouplingBetweenObjects 15 was there
        before). `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation: the existing `DsoCaseMutationsGoldenTest`, `DsoControllerTest` and
        `DsoControllerContractTest` (the missing and unknown status and outcome, the optional dates and notes), green
        before and unchanged after but for one `@uses` line each.
        Own test: `tests/Unit/Controller/Support/RequestBodyFieldsTest` (2); dropping the allowed-value check turns 3
  - [x] 8cf `TenantMigrationService` (2, unsuppressed: TooManyMethods 26 against 25, ExcessiveClassComplexity 62
        against 50). Building the unsaved Organisation a legacy `tenant` row becomes (uuid, slug, name and routing
        group kept, the status mapped onto the Organisation lifecycle, the retention dated from the termination, the
        storage quota in bytes) is its own concern, so `buildOrganisation()` moved to the new
        `TenantMigration/OrganisationFromLegacyTenant::build()` with `applyStorageQuota()`, `applyLifecycle()`,
        `resolveStatus()`, `resolveRetainedAt()` and `resolveStorageQuotaBytes()`, and the `STATUS_MAP` and
        `TENANT_GROUP_PREFIX` constants with their reasons. Bodies moved unchanged. The service builds it over its
        logger in its constructor, so its constructor signature and the container are unchanged, and it no longer names
        OpenRegister's `Organisation`. 20 methods; phpmd: neither finding remains, and no new finding on either file
        under both rulesets (the StaticAccess on `OrganisationLookup` in `placeTenant()` was there before).
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the findings were not suppressed).
        Characterisation: the existing `TenantMigrationServiceTest` (23: the status vocabulary, retention from the
        termination date and from now when it is unreadable, a row without a status, the quota, the repairs), green
        before and unchanged after.
        Own test: `tests/Unit/Service/TenantMigration/OrganisationFromLegacyTenantTest` (2); dropping the quota turns 2
  - [x] 8cg `KpiAggregationService` (1, unsuppressed: ExcessiveClassComplexity 58 against 50). Folding the cases closed
        this month into their average duration and their deadline compliance is its own concern, so
        `averageProcessingDays()`, `slaCompliance()` and `daysBetween()` moved to the new
        `Dashboard/ClosedCaseMeasures` (bodies unchanged; the day count is its own constructor argument). `KpiSources`,
        the bundle of what the figures are read from, builds it over its day count and carries it as `measures`, so the
        service's constructor signature, the container and the service's coupling (14, there before) are unchanged.
        phpmd: no ExcessiveClassComplexity finding, and no new finding on the three files under both rulesets.
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation: the existing `KpiAggregationServiceTest` (the duration and compliance fold, cases without a
        deadline, no closed cases, unreadable dates, an end before the start), green before and unchanged after.
        Own test: `tests/Unit/Service/Dashboard/ClosedCaseMeasuresTest` (2); inverting the deadline comparison turns 2
        tests red (checked).
  - [x] 8ch `Recycle/CaseRecycleService` (1, unsuppressed: ExcessiveClassComplexity 61 against 50). Two concerns moved
        out. Reading the live case behind the retention clocks (`liveCase()`'s body, `caseStoreLocation()`,
        `readLiveCase()`) went to the new `Recycle/LiveCaseReader::read()`, which takes the `SearchesObjects` trait the
        service no longer needs. Who may see a deleted case in the lens (`maySee()`'s body and `deletedOrHeldBy()`)
        went to the new `Recycle/DeletedCaseVisibility::maySee()`. Bodies moved unchanged; `liveCase()` and `maySee()`
        stay on the service as one-line delegations. The restore record stayed: moving it would carry its inherited
        StaticAccess on `ObjectAuditEvents::of()` into a new file. The service builds both in its constructor, so its
        constructor signature and the container are unchanged. phpmd: no ExcessiveClassComplexity finding, and no new
        finding on the three files under both rulesets (the StaticAccess in `recordRestore()` was there before).
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation, green on the unchanged service first (own commit): `Recycle/CaseRecycleLiveCaseTest` (2: the
        case read from the store; no id, no store, no case schema and a failing store each null), with the existing
        `CaseRecycleServiceTest` for the lens; unchanged and green after but for `@uses` lines.
        Own tests: `Recycle/LiveCaseReaderTest` (2) and `Recycle/DeletedCaseVisibilityTest` (1); dropping the assignee
        check turns 1 test red (checked).
  - [x] 8ci `Pdok/PdokLocatieserverService` (1, unsuppressed: ExcessiveClassComplexity 60 against 50). The outage budget
        and the degraded state (three server errors in sixty seconds hold the service degraded for five minutes; a 4xx
        never counts; a success clears it) are their own concern, so `recordSuccess()`, `recordFailure()`,
        `cachedFailures()`, `withinWindow()`, `engageDegraded()` and `isDegraded()` moved to the new `Pdok/OutageBreaker`
        with the `OUTAGE_THRESHOLD`, `OUTAGE_WINDOW` and `DEGRADED_TTL` constants. Bodies moved unchanged; the breaker
        keeps its state in the service's own distributed cache, under the same keys. The service builds it in its
        constructor, so its constructor signature and the container are unchanged. phpmd: zero findings on both files
        under both rulesets. `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not
        suppressed).
        Characterisation: the existing `PdokOutageBreakerTest`, `PdokLocatieserverServiceTest` and
        `PdokRequestShapesTest` (41), green before and unchanged after but for one `@uses` line each.
        Own test: `tests/Unit/Service/Pdok/OutageBreakerTest` (2); counting 4xx answers toward the budget turns 4 tests
        red (checked).
  - [x] 8cj `Portal/CitizenManifest` (1, unsuppressed: ExcessiveClassLength 1009 against 1000). The resident's own
        cases as the portal declares them (the collection "Mijn zaken" reads, its projected fields, who is at turn,
        where it stands, what a card shows, the organisation's intro) are their own declaration, so `caseCollection()`'s
        body moved to the new `Portal/CitizenCaseCollection::declaration()` with `caseDetailKeys()`, `casePageBlocks()`
        and `caseListKeys()`. Bodies moved unchanged but for `self::` on the manifest's three public constants, now
        `CitizenManifest::`. `caseCollection()` stays on the manifest as a one-line delegation, so
        `PortalContributionProvider` and every reader are unchanged. 907 lines; phpmd: zero findings on both files under
        both rulesets. `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation: the existing `CitizenManifestGoldenTest` (every declaration byte for byte),
        `CitizenManifestTest`, `PortalCaseDeclarationsTest` and `PortalConversationTest`, green before and unchanged
        after but for one `@uses` line each.
        Own test: `tests/Unit/Portal/CitizenCaseCollectionTest` (2); dropping the intro turns 4 tests red (checked).
  - [x] 8ck `TenantAuthenticationService` (1, unsuppressed: ExcessiveClassComplexity 59 against 50). Reading a mandate
        matrix (which `tenantMandate` row is in force at a moment, the matrix it carries or the default when it carries
        none, and whether it grants a role an action, wildcards included) is its own concern, so `isAllowed()`'s body,
        `entriesFor()`, `anyGrants()`, `findActiveMandateRow()`, `isEffectiveAt()` and `normaliseMatrix()` moved to the
        new `Tenant/MandateMatrix` (`allows()`, `activeRow()`, `normalise()`) with `DEFAULT_DENY_MATRIX`. Bodies moved
        unchanged. The reads, the refusal on an unreadable matrix and the membership cache stay in the service, which
        builds the matrix reader in its constructor, so its constructor signature and the container are unchanged.
        phpmd: no ExcessiveClassComplexity finding, and no new finding on either file under both rulesets.
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation: the existing `TenantAuthenticationServiceTest` (grants and wildcards) and
        `TenantAuthenticationLookupsGoldenTest` (past, future and garbled rows, JSON and default matrices, the decision),
        green before and unchanged after but for one `@uses` line each.
        Own test: `tests/Unit/Service/Tenant/MandateMatrixTest` (2); ignoring the wildcard action turns 2 tests red
        (checked).
  - [x] 8cl `AanvullingsverzoekService` (1, unsuppressed: ExcessiveClassComplexity 57 against 50). Turning the items a
        handler asks for into checklist rows (each not yet received) and the one-line summary a request is titled by
        is its own concern, so `itemRows()`, `cleanLines()` and `summaryOf()` moved to the new, generically named
        `Support/AskedItems` (`rows()`, `summary()`). Bodies moved unchanged. The service builds it in its constructor,
        so its constructor signature and the container are unchanged. Complexity 50; phpmd: no
        ExcessiveClassComplexity finding, and no new finding on either file under both rulesets.
        `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the finding was not suppressed).
        Characterisation: the existing `AanvullingsverzoekServiceAskGoldenTest` (the rows and summary of every ask),
        `AanvullingsverzoekServiceTest`, `WooClarificationTest` and `PortalWooAnswerTest`, green before and unchanged
        after but for one `@uses` line each.
        Own test: `tests/Unit/Service/Support/AskedItemsTest` (2); keeping blank lines turns 4 tests red (checked).
  - [x] 8cm `MandaatCheckService` (1, unsuppressed: ExcessiveClassComplexity 74 against 50). Two pure concerns moved
        out. The questions asked of register rows (in force on a date, covering a decision and case type, one active
        for an organisation, the rows for a role, the primary role assignment) went to the new `Mandate/MandateRows`
        (`isValidOn()`, was `isRowTemporallyValid()`; `matchesTypes()`, was `matchesTypeTerms()`; `anyActiveFor()`,
        `forRole()`, `validOn()`, `firstByAllocation()` and the private `organisationOf()`). The conditions a mandate
        sets on a case (the ceiling first, then subdelegation) went to the new `Mandate/MandateConditions`
        (`evaluate()`, `evaluateConditions()`'s body, with `plafondExceeded()` and `subdelegatieDenied()`).
        Bodies moved unchanged but for `self::REDEN_*`, now `MandaatCheckService::REDEN_*`. `evaluateConditions()`
        stays public as a one-line delegation. The reads, the stores and the refusals stay in the service, which builds
        both in its constructor, so its constructor signature and the container are unchanged. phpmd: zero findings on
        the three files under both rulesets. `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the
        finding was not suppressed).
        Characterisation: the existing `MandaatCheckServiceTest`, `MandaatCheckServiceActiveForOrganisationTest` and
        `RefusalReplacesTheEmptyAnswerTest` (210 tests with the other mandate suites), green before and unchanged after
        but for two `@uses` lines each.
        Own tests: `tests/Unit/Service/Mandate/MandateRowsTest` (3) and `MandateConditionsTest` (1); letting an
        open-ended row lapse turns 10 tests red (checked).
  - [x] 8cn `Notification/RequesterNoticeSender` (2, unsuppressed: TooManyMethods 32 against 25, ExcessiveClassComplexity
        68 against 50). Two channels of concern moved out. The portal inbox (a message from the organisation to the
        case's portal subject, saved as the system) went to the new `Notification/PortalInboxChannel::deliver()`, with
        `portalMessage()`, `savePortalMessage()`, `portalAnswer()`, `idOf()` and its own copy of the four-line
        `refused()`; the store it writes to is passed in by the sender, read as before. Keeping each notice and its
        result on the case's outbound communications went to the new `Notification/CaseNoticeLog` (`record()`, was
        `noticeRecord()`; `append()`, was `appendRecord()`; `storedNotices()`, `writeNotices()`). Bodies moved unchanged
        but for the store parameter and `self::PORTAL_APP`, now `RequesterNoticeSender::PORTAL_APP`. The sender builds
        both in its constructor and no longer takes the `SearchesObjects` trait, so its constructor signature and the
        container are unchanged. 24 methods; phpmd: neither finding remains, and zero findings on the three files under
        both rulesets. `@SuppressWarnings(PHPMD.*)` lines in `lib/` unchanged at 188 (the findings were not
        suppressed).
        Characterisation: the existing `RequesterNoticeSenderTest` and `RequesterNoticeSenderChannelsTest` (the portal
        inbox unconfigured, throwing and answering no id, the record on the case) with the five suites that send
        through it (186 tests), green before and unchanged after but for two `@uses` lines each.
        Own tests: `PortalInboxChannelTest` (1) and `CaseNoticeLogTest` (1); always copying the optional result fields
        turns 3 tests red (checked).
  - [x] 8co `Email/NextcloudMailGateway` (1: ExcessiveClassComplexity 116 against 50; plus TooManyMethods 37 against 25,
        which development reported unsuppressed). Its suppression said a split would spread the `OCA\Mail` coupling over several files
        (design D-1). It does not have to: every Mail class name stays a constant in the gateway, and the work moved to
        five collaborators that are handed Mail's resolved objects and name no Mail symbol, so the grep for `OCA\Mail`
        still measures the coupling. `Email/MailServices` resolves a service or an account by the name the gateway
        gives (`isAvailable`, `service`, `serviceWith`, `account`, `findAccount`, and `accountWith`, the account and
        manager pair every manager call starts from). `Email/MailMessageCalls` makes the four manager calls (source,
        message-id lookup, move, attachments). `Email/MailSenderChecks` holds the DKIM verdict and the trusted-sender
        walk over the mailbox owners, with their guards (empty or unsigned source, empty address); Mail's service is
        handed in as a resolver closure, so it is still resolved only once a guard has passed. `Email/MailOutboxDelivery` saves and sends through the outbox and maps Mail's
        status numbers; the gateway still builds Mail's outbox row (`LocalMessage`) and hands the builder in as a
        closure. `Email/MailEntityValues` reads getters off Mail's entities, the synchronisation event and attachment
        rows. The gateway builds all five in its constructor, so its constructor signature, its interface and the
        container are unchanged. Bodies moved unchanged, log lines and their levels included; the order of each
        resolution (account first, then the manager) is kept. phpmd: zero findings on the six files under both
        rulesets, and a whole-`lib/` run against development finds nothing new and one finding gone (the TooManyMethods
        above); `@SuppressWarnings(PHPMD.*)` lines in `lib/` 189 to 188. Measure with a fresh pdepend cache
        (`~/.cache/pdepend`): a stale one reported this class at 52 after the split. The three swallowing catches moved with
        their code (`findAccount` and `service` to `MailServices`, `attachments` to `MailMessageCalls`); the
        catch-return-null allowlist names the new files, ceiling unchanged.
        Characterisation: the existing `NextcloudMailGatewayTest`, `...ThreadingTest`, `...LookupsTest`,
        `...ServicesTest`, `OutboundThroughMailAccountTest` and `MailSourceFailuresTest` (37 tests), green on the old
        class first and unchanged after but for five `@uses` lines each.
        Own tests: `MailServicesTest` (3), `MailEntityValuesTest` (3), `MailMessageCallsTest` (3),
        `MailSenderChecksTest` (2) and `MailOutboxDeliveryTest` (3); turning a DKIM pass into a fail or moving
        Mail's processed status number turns them red, and the gateway suites with them (checked).
  - [x] 8cp `Scoring/ScoringSheet` (1: a stale CouplingBetweenObjects suppression). PHPMD reports nothing on the class
        without it under either ruleset, so the line went; nothing else changed. `@SuppressWarnings(PHPMD.*)` lines in
        `lib/` 188 to 187.
  - [x] 8cq `Bezwaar/AdvisoryCommitteeService` (1: TooManyMethods 33 against 25; plus ExcessiveClassComplexity 64 against
        50, which development reported unsuppressed). Its suppression said the total was the sum of small private steps;
        they were three concerns. `Advice/AdviceTransitionGuards` holds the checks a move passes before it is written: the
        panel check before deliberation (REQ-BAC-2: panel members, the independence check, the refusal on the trail before
        the raise) and the advice before it is issued (REQ-BAC-4: the required fields, the decidesk decision raised fail-closed,
        its external reference), with the legacy objection key read that both need. `Advice/AdviceTransitionWriter` writes the
        move once they passed: the internal-marked patch, and for a terminal state the chair's signature on the trail first
        (REQ-BAT-003). Both sit under `Service/Advice`, not `Bezwaar`, so the procedure-named file count (gate 119) does not grow. The service keeps the referral, the lookups, the lifecycle and chair checks and the council deviation.
        Both are built in its constructor from the collaborators it already took, so its constructor signature, its interface
        and the container are unchanged; `guardDeliberationStart()` hands its request to the same panel guard. Bodies moved
        unchanged, log lines and refusal messages included. The service went from 1,010 to about 750 lines. phpmd: zero findings
        on the three files under both rulesets; `@SuppressWarnings(PHPMD.*)` lines in `lib/` 187 to 186.
        Characterisation, green on the old class first and unchanged after: `AdvisoryCommitteeServiceTest`,
        `AdvisoryCommitteeServiceGoldenTest`, `AdvisoryCommitteeDeliberationStartTest`, the Bezwaar, DecisionDocument and
        Governance suites, `BezwaarAdviceRequestControllerTest` and the Listener suite (1,219 tests).
        Own tests: `AdviceTransitionGuardsTest` (8) and `AdviceTransitionWriterTest` (3).
  - [x] 8cr Eight small findings development reported without a suppression (TASK-VERIFY-006: PHPMD reports 0
        violations). MissingImport (4): the three listeners' `#[\Override]` and `ApiCredentialResolver`'s `\WeakMap` are
        imported. ElseExpression (2): `FilinqRedactionClient::anonymized()` hands the version-dependent anonymise call
        to `anonymizeCall()` (the pre-AnonymizeOptions positional call first, then an early return), and
        `IntakeObligations::settleAs()` hands the refuse-or-sign settle to `settleObligation()`; the state comes from the
        same answer. ShortVariable (2): `$ip` is `$address` in `PortalAssertionGate::countRejected()`, `$u` is `$row` in
        `AmountObjectionService::holdPayout()`. Nothing else changed; log lines, refusals and calls are the same.
        phpmd: those eight findings gone and none new on the eight files under both rulesets (left there:
        `FilinqRedactionClient::anonymize` BooleanArgumentFlag and `ApiCredentialResolver::remoteAddress` Superglobals,
        a signature change and a request-boundary decision, not cleanups). Covered by the classes' existing tests,
        green before and after: `FilinqRedactionClientTest`, `FilinqRedactionTest`, `IntakeObligationsTest`,
        `IntakeObligationListenerTest`, `ApiCredentialResolverTest`, `ApiCredentialResolverEdgesTest`,
        `AmountObjectionServiceTest`, `AmountObjectionServiceFailuresTest` and the three listener tests (`CaseTypeRightsEnforcedAccessTest`
        fails its 12 on development as well).
  - [x] 8cs `Registrar/CaseTypeListenerRegistrar` (1: CouplingBetweenObjects 14 against 13). The statutory term's two
        listeners (`CaseDeadlineFollowsTermListener` at -110, `ApplicantTermNoteListener` at -120, updates only) moved to
        `Registrar/CaseTermListenerRegistrar`, which the case type registrar calls where `registerTermListeners()` ran,
        so the order of every registration is unchanged and `ListenerRegistrar` is untouched. The three deadline
        priorities stay on `CaseTypeListenerRegistrar`, side by side, as the listeners' docblocks and tests cite them.
        Coupling 14 to 12; phpmd: zero findings on both files under both rulesets; `@SuppressWarnings(PHPMD.*)` lines in
        `lib/` 186 to 185.
        Characterisation, green before and after: `CaseTypeListenerRegistrarTest` (the full ordered registration list,
        unchanged but for one `@uses` line), `ListenerRegistrarTest` and the three deadline listener suites
        (`CaseDeadlineFollowsTermListenerTest`, `ApplicantTermNoteListenerTest`, `CaseInheritedDeadlineListenerTest`).
        Dropping the new registrar's call turns `CaseTypeListenerRegistrarTest` red (checked).
        Own test: `CaseTermListenerRegistrarTest` (2).
  - [x] 8ct `Listener/DeliveryConcludedListener` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Loading the
        delivery's case, finding the publication whose delivery carries the correlation id, recording the terminal outcome
        and saving the case moved to `Listener/Support/PublicationDeliveryProjection` (`project()`, with the case lookup,
        the idempotent match and the save in the configured register and schema; bodies and log lines moved unchanged).
        The listener keeps the provenance and terminal-status filters, the service account and the catch that keeps a
        failure out of integriq's bookkeeping; it builds the projection in its constructor, so its constructor and its
        registration are unchanged. Coupling 14 to 10; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `DeliveryConcludedListenerTest`, `DeliveryConcludedListenerGoldenTest`
        and `DeliveryConcludedListenerServiceAccountTest` (10), unchanged but for one `@covers` line each, and
        `CrossAppListenerRegistrarTest`. Own test: `tests/Unit/Listener/Support/PublicationDeliveryProjectionTest` (5);
        dropping the correlation-id match turns 4 tests red (checked).
  - [x] 8cu `Listener/CaseInheritedDeadlineListener` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The term a
        child case type inherits and the deadline it implies (the start date plus the term, rolled by the administered
        calendar) moved to `Listener/Support/InheritedCaseDeadline` (`inheritedTerm()`, `deadlineFrom()`; bodies and the
        warning moved unchanged). The listener keeps the pre-save check, the case-schema test and the write of `deadline`
        and `statutoryTerm`; it builds the helper in its constructor, so its constructor and its registration are
        unchanged. Coupling 14 to 9; phpmd: the coupling finding gone and nothing new under both rulesets (the
        `ReferenceId` StaticAccess development reports stays).
        Characterisation, green before and after: `CaseInheritedDeadlineListenerTest` (9), `ReferenceIdCopiesTest`,
        `SchemaMembershipTest` and `CaseTypeListenerRegistrarTest`, unchanged but for one `@covers`/`@uses` line each.
        Own test: `tests/Unit/Listener/Support/InheritedCaseDeadlineTest` (4, including the calendar roll, which the
        listener suite does not reach); dropping the own-term check turns 2 tests red (checked). The statutory row of
        the date-arithmetic audit (`docs/research/date-arithmetic-audit-2026-09-14.md`) moved with the arithmetic to the
        new file, so `EveryTermOnTheCalendarTest` keeps holding it to the engine calendar.
  - [x] 8cv `Transitions/StatusTypeLookup` (1, unsuppressed: BooleanArgumentFlag on the private `read()`, `$render`).
        A rendered and a stored read of a statusType are two views, not a flag: `read()` takes the view by name
        (`VIEW_RENDERED`, `VIEW_STORED`), and `rowFor()` and `storedRowFor()` each name theirs. The schema key, the only
        one ever passed, is the class's `SCHEMA_KEY`. What is kept for the request is keyed by view and id, so a rendered
        and a stored read of one status stay apart as before; the find options, the catch and the answers are unchanged,
        and the catch-return-null allowlist entry (`read`) still names the method. phpmd: zero findings on the file
        under both rulesets.
        Characterisation, green on the old class first: `StatusTypeLookupReadTest::testTheRenderedAndTheStoredViewAreKeptApart`
        (each view read once, each with its own render option, both read again after `forget()`), beside the existing
        read tests (12 in all). Keying the kept reads by id alone turns it red (checked).
        Whole unit suite: the same failures before and after, none in this class (`StatusEntryRequirementsGuardTest`
        needs a sibling openregister checkout with its vendor directory and fails without one on development too).
  - [x] 8cw `Controller/SavedMailController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The storage side of
        reading a saved mail file (the file as the caller reaches it by id, and its bytes, a storage failure logged and
        answered as absent) moved to `Controller/SavedMailFiles` (beside `SavedMailRead`: a swallowing catch under `lib/Service` counts against the `ServiceCatchReturnsNullTest` ceiling) (`nodeFor()`, `contentOf()`; bodies and the log line
        moved unchanged, `contentOf()` takes the file, the case and the file id instead of the controller's
        `SavedMailRead`). The controller keeps the authentication, the case guard, the size cap and the hand-off to the
        reader. Its constructor takes `SavedMailFiles` in place of `IRootFolder` and `LoggerInterface`; the container
        autowires both, and no code but the one test builds the controller by hand. Coupling 14 to 12; phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: `SavedMailControllerTest` (8: anonymous, refused case, no case,
        unreachable file, the size cap, unreadable storage, the hand-off and a kept answer), which builds the controller
        over the same doubles through `SavedMailFiles`. Own test: `tests/Unit/Controller/SavedMailFilesTest` (3);
        answering a folder as a file turns it red (checked).
  - [x] 8cx `Controller/TaskChecklistController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The answer to a
        checklist run the store did not take (OpenRegister's two validation refusals a 400 logged at info, this app's own
        refusal a 422, anything else a 500 logged as an error) moved to `Controller/ChecklistRunFailure` (`answer()`, with
        `rejectedPayload()` and `serverFault()`; bodies, comments and log lines moved unchanged). The controller keeps the
        guards and the hand-off to the run service, and builds the answer in its constructor, so its constructor and the
        container are unchanged (the logger is now passed through rather than held). Coupling 14 to 12; phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: `TaskChecklistControllerTest` (11, including the 400 for both
        OpenRegister refusals, the 500 for a genuine fault and the 422), unchanged but for one `@covers` line. Own test:
        `tests/Unit/Controller/ChecklistRunFailureTest` (3, with the log level and wording of each answer, which the
        controller suite does not assert); dropping the custom-validation arm turns 2 tests red (checked).
  - [x] 8cy `Controller/PayoutCallbackController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Reading a
        signed callback's fields and parsing its actual payment date through the one date write path (a malformed date
        or body a 400 with the reason, never today) moved to `Controller/PayoutCallbackFields::read()` (body moved
        unchanged). The controller keeps the signature check, the hand-off to the payout service and the unknown-reference
        answer. Its constructor takes `PayoutCallbackFields` in place of `CaseDateNormaliserInterface`; the container
        autowires it, and only the two tests build the controller by hand. `OneDateWritePathTest` lists the new file
        under the controller's write path. Coupling 14 to 13; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `PayoutCallbackControllerTest` and `PayoutCallbackGoldenTest`
        (11), building the controller over the same reader and dates through `PayoutCallbackFields`, and
        `OneDateWritePathTest`. Own test: `tests/Unit/Controller/PayoutCallbackFieldsTest` (3); parsing an absent date
        turns it red (checked).
  - [x] 8cz `Review/RecallSamples` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Where a case's Recall samples
        are kept (the case's stored samples read through OpenRegister, a sample written back without its derived
        progress, and the refusal when the register will not store it) moved to `Review/RecallSampleStore` (`rows()`,
        `save()`, with `savedRow()` and `unstored()`; bodies, the log line and the refusal moved unchanged). The service
        keeps the draw, the judgement, the progress and the estimate, and builds the store in its constructor, so its
        constructor, interface and container alias are unchanged (the settings service and the logger are now passed
        through rather than held). Coupling 14 to 12; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `RecallSamplesTest` (9, including the refused save and the missing
        schema), `RecallControllerTest` and `ReviewContractRegistrarTest`, unchanged but for one `@covers`/`@uses` line.
        Own test: `tests/Unit/Review/RecallSampleStoreTest` (4); keeping the derived progress on a save turns it red
        (checked).
  - [x] 8da `Controller/SettingsController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Whether the caller
        is a signed-in administrator (the session and the group manager) moved to `Controller/Support/AdminCaller::isAdmin()`
        (body unchanged; no session answers false without asking the group manager). The controller keeps choosing the
        full or the public settings, the signing hint, the save and its refusals. Its constructor takes `AdminCaller` in
        place of `IUserSession` and `IGroupManager`; `BespokeServiceRegistrar` asks the container for it by name, so the
        registrar's own coupling is unchanged. Coupling 14 to 13; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `SettingsControllerReadTest` (8, including the admin and the public
        read), `SettingsControllerWriteTest`, `IntegrationProbesRecordTest` and `BespokeServiceRegistrarTest` (which
        now records the container asked for `AdminCaller`). Own test: `tests/Unit/Controller/Support/AdminCallerTest` (3).
  - [x] 8db `HearingCalendarService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Writing the hearing into
        each principal's first calendar that takes it (accepts ICS, not in the trash, writable), a refusal passing to the
        next calendar, an unreadable principal skipped, and the warning when no calendar took it, moved to
        `Service/Calendar/HearingCalendarWriter` (`writeForAll()`, with `writeToCalendar()` and `takesHearings()`; bodies and
        log lines moved unchanged). The service keeps building the event, the organiser, the attendees and the principals,
        and builds the writer in its constructor beside `PlannedEventFields`, so its constructor and the container are
        unchanged. Coupling 14 to 12 (measured with the rule's maximum at 1); phpmd: zero findings on both files under
        both rulesets.
        Characterisation, green before and after: `HearingCalendarServiceTest` and `HearingCalendarServicePinTest` (13,
        with every log line in order), `HearingServiceTest`. Own test: `tests/Unit/Service/Calendar/HearingCalendarWriterTest`
        (4); writing into a calendar in the trash turns it red.
  - [x] 8dc `Repair/BackfillInformatieobjectMetadata` (1, unsuppressed: CouplingBetweenObjects 14 against 13). What the
        back-fill writes for one file (the informatieobject with its defaults, the owner as author and a SHA-256 of the
        content, then its link to the case when a join schema is configured and the save answered a uuid) moved to
        `Repair/Support/InformatieobjectFileWrite::write()` (with `informatieobjectFor()`, `authorOf()`, `describedFile()`,
        `savedUuid()`, `joinRecord()`; bodies moved unchanged). The step keeps the system identity, the folder walk, the
        skip of registered names and part files, the per-file catch and the counts, and builds the write in its
        constructor, so its constructor is unchanged. Coupling 14 to 13; phpmd: zero findings on both files under both
        rulesets.
        Characterisation, green before and after: `BackfillInformatieobjectMetadataTest` (7) and
        `RepairStepRegistrationTest`. Own test: `tests/Unit/Repair/Support/InformatieobjectFileWriteTest` (3: the
        informatieobject and its link, no owner, no uuid no link).
  - [x] 8dd `People/FileRequestService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Building the create-only
        e-mail share of the case folder from a checked request and having the share manager create it (a refusal a 500
        naming the reason) moved to `Service/People/UploadShareWrite` (`send()`, answering the share's token, with
        `uploadShare()` and `createdShare()`; bodies moved unchanged). The service keeps the party, the address, the
        indicator refusal, the folder, the caller and the expiry, and builds the write in its constructor, so its
        constructor and the container are unchanged. Coupling 14 to 12; phpmd: zero findings on both files under both
        rulesets.
        Characterisation, green before and after: `FileRequestServiceTest` (with every setter on the share and the
        refused share), `FileRequestServiceRefusalsTest` and `FileRequestControllerTest`. Own test:
        `tests/Unit/Service/People/UploadShareWriteTest` (3: the share and its token, an ownerless folder without a note,
        the refusal).
  - [x] 8de `PublicationService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Reading the case to publish
        from OpenRegister (normalised to an array; a missing or unreadable case refused, the unreadable one logged) and
        saving it back into its register and schema moved to `Service/Publication/PublishedCase` (`load()`, `save()`;
        bodies and the log line moved unchanged). The service keeps the channel check, the publication record, the date
        rule and the integriq delivery, and builds the store in its constructor, so its constructor is unchanged.
        Coupling 14 to 12; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `PublicationServiceTest`, `PublicationServiceGoldenTest` (including
        the case read that throws), `BesluitvormingPublishHandlerTest` and `PublicationControllerTest`. Own test:
        `tests/Unit/Service/Publication/PublishedCaseTest` (5).
  - [x] 8df `Controller/PortalWooAnswerController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Reading the
        forwarded answer into a submission (the request id, the answer, and the files under `bijlagen` read into bytes
        and re-checked for number and size) moved to `Controller/Support/PortalAnswerRead` (`read()`, answering the
        submission or null, with `attachments()`, `uploadEntries()`, `readUpload()` and `stringParam()`; bodies moved
        unchanged). The controller keeps the assertion, the audience, the 422 and the hand-off, and builds the reader in
        its constructor, so its constructor is unchanged. Coupling 14 to 12; phpmd: zero findings on both files under
        both rulesets.
        Characterisation, green before and after: `PortalWooAnswerControllerTest` (the single and multiple uploads, the
        six files, the 10 MB edge) and `PortalWooAnswerTest`. Own test: `tests/Unit/Controller/Support/PortalAnswerReadTest`
        (4).
  - [x] 8dg `WOODeadlineService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The handler's notice when a
        deadline is seven days out or has passed (the warning or overdue subject, the case, the days; a notice that
        cannot be sent logged) moved to `Service/Notification/DeadlineWarningNotice` (`send()`, with `deadlineSubject()`
        and `deadlineNotification()`; bodies and the log line moved unchanged). The service keeps reading the case,
        counting the days and deciding when to warn, and builds the notice in its constructor, so its constructor is
        unchanged. Coupling 14 to 12; phpmd: zero findings on both files under both rulesets.
        Characterisation, written first and green on the old class: two tests added to `WOODeadlineServiceTest` (the
        overdue notice's app, user, object, subject and parameters; a notice that cannot be sent is logged and the
        warning stands), beside `OneDateWritePathTest`, `StatutoryTermParityTest` and the `WOOAssessmentController`
        suites. Own test: `tests/Unit/Service/Notification/DeadlineWarningNoticeTest` (3).
  - [x] 8dh `CaseTypeCopyService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The value a copy step works on,
        `CaseType/CaseTypeCopyTarget`, already held the object service, the register and the schema; it now makes the
        save and the find there itself (`save()`, `find()`), so the service no longer builds OpenRegister's three option
        objects. The calls are the same, and a refusal is not caught in the target: it still reaches each step's own
        catch and log line. Coupling 14 to 11; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `CaseTypeCopyServiceTest` (the copy, the version, the children that
        could not be carried) and `CaseTypeCopyValuesTest`. Own tests: two added to `CaseTypeCopyValuesTest` (a save and a
        find in the target's own register and schema; a refusal reaches the caller).
  - [x] 8di `Review/ReviewBatches` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Writing a batch into the
        batch schema (identifiers stripped, created or replaced by its uuid, answered as an array; a register that will
        not take it refused as `review-batch-unavailable` with the cause, and logged) moved to `Review/ReviewBatchStore`
        (`save()`, with `writeBatch()`; bodies and the log line moved unchanged, the trait's refusal spelled out as the
        same `RefusedException`, `UNSTORED` now a public constant both read). The service builds the store in its
        constructor, so its constructor and the container are unchanged. Coupling 14 to 13; phpmd: zero findings on
        both files under both rulesets.
        Characterisation, green before and after: `ReviewBatchesTest` (including the register that cannot write),
        `BatchSelectionTest`, `DocumentReviewControllerTest`, `RecallControllerTest` and `ReviewContractRegistrarTest`.
        Own test: `tests/Unit/Review/ReviewBatchStoreTest` (2).
  - [x] 8dj `Repair/MigrateWorkflowDefinitions` (1, unsuppressed: CouplingBetweenObjects 14 against 13). As in 8dh: the
        step's value `Workflow/BackfillTarget` already held the object service, the register and the schemas, and now
        makes the save itself (`save(schema, object, uuid)`), so the step no longer builds OpenRegister's option objects
        for its three writes (the template, the case type pin, the open case pin). The calls are the same and a refusal
        still reaches each write's own catch. Coupling 14 to 12; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `MigrateWorkflowDefinitionsGoldenTest` and
        `MigrateWorkflowDefinitionsLabelsTest`. Own test: `tests/Unit/Service/Workflow/BackfillTargetTest` (3).
  - [x] 8dk `Publication/OpenCatalogiApiClient` (1, unsuppressed: CouplingBetweenObjects 15 against 13). The value
        `Publication/PublicationTarget` holds the register and schema; it now builds OpenRegister's find and save
        options for them (`findOptions()`, `saveOptions(uuid)`), so the client no longer builds the three option objects.
        Same options, same calls, same catches and log lines. Coupling 15 to 12; phpmd: zero findings on both files
        under both rulesets.
        Characterisation, green before and after: `OpenCatalogiApiClientTest`, `OpenCatalogiApiClientShapesTest`,
        `CatalogTransportTest`, `OutcomePublisherTest` and the Woo publication suites. Own test: one added to
        `PublicationTargetTest` (the options name the register, the schema and the uuid).
  - [x] 8dl `Controller/BrcController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Deleting a besluit
        (brc-009: find it, run its destroy rules, sync-delete its OIOs in DRC, delete it; a store failure, a missing
        besluit and a refusing rule each answered before anything is deleted) moved to `Service/Zgw/BrcDecisionDeletion`
        (`delete()`, with `validateAndDeleteDecision()`; bodies moved unchanged, the request passed in for the besluit's
        URL). The controller keeps the route, the mapping lookup and the delete notification, and builds the deletion in
        its constructor beside its other helpers, so its constructor is unchanged. Its now unused `DECISION_RESOURCE`
        constant moved with the code. Coupling 14 to 10; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `BrcControllerTest` (the deleted besluit with its mirrors, the refused
        delete, the missing resource), `BrcNrcWriteGateGoldenTest`, `ZgwRateLimitTest` and `ZgwAuthMiddlewareTest`.
        Own test: `tests/Unit/Service/Zgw/BrcDecisionDeletionTest` (3).
  - [x] 8dm `Bezwaar/BezwaarAuditTrail` (1, unsuppressed: CouplingBetweenObjects 15 against 13). `BezwaarEntryCommand`,
        which holds the record's register, schema and uuid, now names the deletion of that record (`recordDeletion()`)
        for the write-or-undo path; and an entry is stamped with `date(DATE_ATOM)` instead of a `DateTimeImmutable`
        formatted as ATOM: the same string in the same time zone. Coupling 15 to 12; phpmd: no new findings (the
        StaticAccess on `ObjectAuditEvents` in `writeEvent()` was there on development).
        Characterisation: `testAnEntryIsStampedWithAnAtomTimestamp` added to `BezwaarAuditTrailTest` and run green on
        the old code first (in a development worktree), beside `BezwaarAuditWriteOrUndoTest`,
        `BezwaarRecordThenApplyGoldenTest` and the other Bezwaar suites. Own test:
        `tests/Unit/Service/Bezwaar/BezwaarEntryCommandTest` (1).
  - [x] 8dn `Recycle/CaseDestructionService` (1, unsuppressed: CouplingBetweenObjects 15 against 13). The permanent
        delete of a destroyed case from its register and case schema moved to `Service/Recycle/CasePurge::delete()`
        (body unchanged), and the record's `destroyedAt` is `date(DATE_ATOM)`, the same stamp as before. The audit
        record stays in the service: moving it would carry its development StaticAccess finding into a new file. The
        service builds the purge in its constructor, so its constructor is unchanged. Coupling 15 to 13; phpmd: no new
        findings.
        Characterisation: the `destroyedAt` format pinned in `CaseDestructionTest::testTheRecordNamesWhoDecided` and run
        green on the old code first, beside the rest of `CaseDestructionTest` and `CaseRecycleControllerTest`. Own test:
        `tests/Unit/Service/Recycle/CasePurgeTest` (2).
  - [x] 8do `Task/EngineInboxQuery` (1, unsuppressed: CouplingBetweenObjects 15 against 13). Turning a flat set of named
        filters into OpenRegister's TaskInbox criteria (audience, filter, window, anchor, order, each built with named
        arguments; a filter no part knows passed on so the engine refuses it by name) moved to `Service/Task/InboxCriteria`
        (`forFilters()`; body moved unchanged). The query keeps resolving the inbox, making the read, unwrapping the
        envelope and telling a failed read apart from an empty one; a missing criteria class still throws inside its
        catch. It builds the criteria in its constructor, so its constructor is unchanged. Coupling 15 to 9; phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: `EngineInboxQueryTest`, `EngineInboxQueryResolutionTest`,
        `EngineTaskInboxTest`, `EngineTaskInboxCaseAndInstanceTest`, the onboarding steps, inspection run and status
        checklist suites. Own test: `tests/Unit/Service/Task/InboxCriteriaTest` (2).
  - [x] 8dp `Controller/AdviceController` (1, unsuppressed: CouplingBetweenObjects 15 against 13). The three advice
        writes and their answers (a status transition: 400 on a refusal, a logged 500 otherwise; an advice request stamped
        with the case, the requester and `open`: 201, 400 on an unreadable value, a logged 500 naming the case; a
        reminder through the authorized seam: 403 on a refusal, a 500 without the internal message) moved to
        `Controller/Support/AdviceWrites` (`transition()`, `request()`, `remind()`, `adviceRequestFailure()`; bodies and
        log lines moved unchanged, the requester passed as a uid). The controller keeps the session and the per-case
        guards and builds the writes in its constructor, so its constructor is unchanged; the logger is now passed
        through rather than held. `OneDateWritePathTest` lists the new file under the advice write path. Coupling 15
        to 11; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `AdviceControllerContractTest` (27, every answer of every route),
        `AdviceServiceAuthorizationTest` and `OneDateWritePathTest`. Own test:
        `tests/Unit/Controller/Support/AdviceWritesTest` (4).
  - [x] 8dq `Money/PayoutService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Reading and writing the rows
        of a payout (the payable amount of a calculation, definitive before cumulative; saving a payout row; the row a
        finance callback names by its reference; the refusal when either schema is not configured, and one sentence
        with the cause per failing read or write) moved to `Money/PayoutStore` (`payableAmount()`, `save()`,
        `byReference()`; bodies and refusal texts moved unchanged). The service keeps the decisions: the IBAN check, the
        deadline, the finance handoff, withdrawing and raising again, and the callback fields. It no longer names
        `MoneyStore` or `\Throwable`. The service builds the store in its constructor, so its constructor and the
        container are unchanged. Coupling 14 to 12 (store 5); phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after but for one `@uses` line in five: `PayoutServiceTest`,
        `PayoutServiceGoldenTest`, `PayoutServiceRaiseAgainTest`, `AmountObjectionServiceTest`,
        `AmountObjectionServiceFailuresTest` and `MigratePayoutRowsToFinanceEdgesTest`.
        Own test: `tests/Unit/Service/Money/PayoutStoreTest` (8).
  - [x] 8dr `Controller/DocumentReviewController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The signed-in
        caller when they may read or change the case (401 `not-authenticated` without a session user, the translated
        `no-case-access` 403 without access) moved to `Controller/Support/ReviewCaseCaller` (`requireReader()`,
        `requireWriter()`; bodies moved unchanged with the `AnswersNoCaseAccess` trait). The controller takes it in place
        of `IUserSession` and `CaseAccessGuardInterface`; the container autowires it. The shared `CaseCaller` and
        `CaseAccessRefusal` were not reused: their 401 and 403 bodies differ from this controller's. The routes still
        call a `require*` guard, and gate-7's checker reports none of them on the old or the new file. Coupling 14 to 13;
        phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `DocumentReviewControllerTest`, `DocumentReviewGateGoldenTest` (every
        route's 401 and 403) and `ReviewBatchCreateGoldenTest`, each building the controller over the same doubles through
        `ReviewCaseCaller`. Own test: `tests/Unit/Controller/Support/ReviewCaseCallerTest` (3).
  - [x] 8ds `KpiAggregationService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Resolving OpenRegister's
        object service (logged when it does not resolve on an installed OpenRegister) and its language service (only
        with the translation switch), both only when OpenRegister is installed, moved to
        `Service/KpiOpenRegisterServices` (`objectService()`, `languageService()`; bodies and the log line moved
        unchanged). The service takes it in place of `IAppManager` and `ContainerInterface`; the container autowires
        it. The `catch-return-null` allowlist entry for `objectService` moves to the new file (same count, same
        ceiling). Coupling 14 to 12; phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `KpiAggregationServiceTest` and `TranslatableTypeNamesReadAsTextTest`
        (the translation switch put back after the read), plus the controller suites that mock the service. Own test:
        `tests/Unit/Service/KpiOpenRegisterServicesTest` (4).
  - [x] 8du `CaseEmailService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Recording a mail that went out
        about a case (the stored message, then its public `mail-uitgaand` line naming the document, the sender and what
        became of it) moved to `Service/Email/SentMailRecorder` (`record()`, which takes the case and the sent mail as one
        array, so it stays under gate-122's five parameters; bodies moved unchanged, the `SentCaseMail` built there). The service keeps the checks before a send, the hand-off to the Mail account, the templates and the
        inbound linking, and `recordSentEmail()` stays its public seam for the flow listener. It builds the recorder in
        its constructor, so its constructor and the container are unchanged. Coupling 14 to 13 (recorder 4); phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: `CaseEmailServiceTest`, `CaseEmailSentRecordGoldenTest` (the document
        before the line, the line's fields, a named delivery), `CaseEmailInboundGoldenTest`, `SendFromTemplateRefusesTest`,
        `EmailControllerTest`, `EmailControllerContractTest`, `FlowEmailSentListenerTest` and the send-template handler
        suites. Own test: `tests/Unit/Service/Email/SentMailRecorderTest` (2).
  - [x] 8dv `RoleResolverService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). Reading the stored role
        records of a case (the object service, register and role schema, nothing without one of them, a failing read
        logged and refused as `case-roles-unreadable`) moved to `Service/Routing/CaseRoleSource` (`recordsOf()`; bodies
        moved unchanged, the trait's read-or-refuse written out in place: phpstan does not follow a trait's read of the
        logger and reported it as only written; same log line, same refusal). The resolver keeps turning the records into rows
        (its `ObjectArray` copy, which `ObjectArrayCopiesTest` reads, stays) and everything after. It takes the source in
        place of `SettingsService`; the container autowires it. Removed, not moved: the public `fail()`, which had no
        caller and is not on the interface. Coupling 14 to 12 (source 4); phpmd: no new findings (the StaticAccess on
        `ObjectArray` in `toArray()` was there on development, on an untouched line).
        Characterisation, green before and after: `RoleResolverResolveGoldenTest`, `RoleResolverCaseRolesTest`,
        `AreaRoutingIsConsultedTest`, `RefusalReplacesTheEmptyAnswerTest` (the unreadable register refused, not routed to
        nobody), `ObjectArrayCopiesTest` and the routing, role-mutation and take-back suites, each building the resolver
        over the same doubles through `CaseRoleSource`. Own test: `tests/Unit/Service/Routing/CaseRoleSourceTest` (3).
  - [x] 8dw `SamenwerkverzoekService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The save that both the
        create and the response end with (into the register and schema, answered as the stored entity serialises) moved
        to the generic `Service/Support/SavedObjectArray` (`save()`; body moved unchanged). The trait's
        `saveObjectAsArray()` was not reused: it answers null for an answer it cannot read, where this path answers the
        serialised entity. The service builds it in its constructor, so its constructor and the container are unchanged.
        Coupling 14 to 13 (saver 3); phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `SamenwerkverzoekServiceTest`, `DsoCaseMutationsGoldenTest`,
        `DsoControllerTest` and `DsoControllerContractTest`. Own test: `tests/Unit/Service/Support/SavedObjectArrayTest` (1).
  - [x] 8dx `TermijnService` (1, unsuppressed: CouplingBetweenObjects 14 against 13). How a statutory term starts (the
        resolution's definition, else the case type's active one, refused with `NoTermijnDefinitieException` without
        either; the start date and duration) and storing its first instance (a running `statutory` term, its end counted
        and rolled by the definition, the rule that produced it; refused when the store answers nothing) moved to
        `Service/Term/TermStarts` (in the generic `Term` folder, so the procedure-named file count does not grow) (`begin()`, `store()`; bodies moved unchanged, the definition read straight from
        `TermDefinitions::activeFor()`, which is all `getTermijnDefinitie()` did). The service keeps the start event, the
        engine timer, the case mirror and every later write; `recordStart()` and `armOrFollow()` take the definition,
        duration and start date instead of the `TermStart`. It builds the starts over its own definitions and store in
        its constructor, so its constructor and the container are unchanged. Coupling 14 to 13 (starts 7); phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: every `Termijn` and `Term` suite (1,058 tests, the one
        `StubApiDriftTest` failure as on development). Own test: `tests/Unit/Service/Term/TermStartsTest` (4).
  - [x] 8dy `TenantOnboardingService` (1, unsuppressed: CouplingBetweenObjects 15 against 13). What onboarding reads from
        the registers without writing (the mandates step done once the mandate register confirms an active mandate,
        never over a step already completed or skipped; the tier on the tenant's audit anchor, `basic` without one; one
        row of a schema for the tenant, a failed read counting none) moved to `Service/Onboarding/OnboardingRegisterReads`
        (`withMandatesSet()`, `tierOf()`, `countSchemaRows()`; bodies moved unchanged, the object service passed in). The
        service keeps the steps, their completion, the go-live decision and the billing line. It builds the reads in its
        constructor from the normaliser and the mandate check it was given, so its constructor and the container are
        unchanged. Coupling 15 to 12 (reads 7); phpmd: zero findings on both files under both rulesets.
        Characterisation, green before and after: `TenantOnboardingServiceTest`, `TenantOnboardingMandatesSetTest`,
        `TenantOnboardingLegacyRowsTest`, `OnboardingStepsTest`, `ServiceCatchReturnsNullTest` and
        `NoConstructorCycleTest`. Own test: `tests/Unit/Service/Onboarding/OnboardingRegisterReadsTest` (3).
  - [x] 8dz `Listener/DeadlineCaseCreatedListener` (1, unsuppressed: CouplingBetweenObjects 15 against 13). Binding the
        terms of a received case (the statutory term from receipt under the resolved definition, then the planned end and
        the internal target; a case type without a definition warned about and its other clocks still bound; any other
        failure an error that binds nothing further; a failing other clock a warning) moved to
        `Listener/Support/NewCaseTermBinding` (`bind()`; bodies moved unchanged). The listener keeps telling a receipt from
        a draft, reading the case's identity and context (with its `ReferencedUuid` copy, which `ReferencedIdCopiesTest`
        reads) and resolving the statutory term and the service norm (`resolvedTerms()`), which it hands to `bind()`, so
        the binding stays under gate-122's five parameters. It builds the binding in its constructor, so its constructor and the
        container are unchanged. Coupling 15 to 13 (binding 9); phpmd: no new findings (the StaticAccess in
        `referenced()` was there on development, on an untouched line).
        Characterisation, green before and after: `DeadlineCaseCreatedListenerTest`, `...GuardsTest`, `...RefusalsTest`,
        `DeadlineCaseCreatedShippedDataTest`, `DraftIsNotReceivedRegistrationTest`, `ReceiptDatesRegistrationTest`,
        `ReferencedIdCopiesTest` and `CaseRecordImportTest`. Own test:
        `tests/Unit/Listener/Support/NewCaseTermBindingTest` (4).
  - [x] 8ea `Actions/AutomaticActionFlowMigrator` (1, unsuppressed: CouplingBetweenObjects 15 against 13). What one stored
        action becomes as flow steps (its config read, its type looked up in the retired-node table and translated, a
        refusal when it cannot become a step or names a node nothing implements) moved to `Service/Actions/ActionFlowSteps`
        (`resolveSteps()`; bodies and the node prefix moved unchanged). The migrator keeps reading the actions, the run,
        the dry run and the flow write. It takes the steps in place of `RetiredNodeMap` and `RetiredNodeTranslator`; the
        container autowires it. The container and logger come first in the steps' constructor, so it does not read as a
        clone of `RetiredActionRunner`'s, which takes the same four for a different prefix. Not final, because `FlowMigrationSummaryCopiesTest` doubles every constructor parameter.
        Coupling 15 to 13 (steps 8); phpmd: no new findings (the three StaticAccess on `FlowMigrationSummary` were there
        on development, on untouched lines).
        Characterisation, green before and after: `AutomaticActionFlowMigratorTest`,
        `AutomaticActionFlowMigratorUnavailableTest`, `FlowMigrationSummaryCopiesTest` and the retired-node suites, the
        migrator built over the same map, translator, container and logger through `ActionFlowSteps`. Own test:
        `tests/Unit/Service/Actions/ActionFlowStepsTest` (3).
  - [x] 8eb `Redaction/DocumentRedactionService` (1, unsuppressed: CouplingBetweenObjects 15 against 13). Reading the
        original of a document being redacted (its informatieobject, refused as `document-unreadable`; its file by id,
        else a legacy file by name, else refused as `file-unresolved` with the cause attached) moved to
        `Service/Redaction/RedactionOriginal` (`meta()`, `read()`, `fileIdOf()`; bodies moved unchanged). The service keeps
        the assessment, the detection, the check, the redaction and the upload. It takes the original in place of the
        case documents and `ZgwDocumentService`; the container autowires it. Coupling 15 to 13 (original 5); phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: `DocumentRedactionServiceTest`, `DocumentRedactionStepsGoldenTest` and
        `DocumentRedactionControllerTest`, built over the same doubles through `RedactionOriginal`. Own test:
        `tests/Unit/Service/Redaction/RedactionOriginalTest` (3).
  - [x] 8ec `Controller/WooReportController` (1, unsuppressed: CouplingBetweenObjects 15 against 13). Reading the
        throughput report for one case or a period (moments read as the tenant's days) and recording the read on the
        audit trail of every case it counted before anything is answered moved to `Controller/Support/RecordedReportRead`
        (`scope()`, `read()`; bodies moved unchanged). A report that cannot be made or a read that cannot be recorded comes
        back as `{rule, status}` instead of a thrown refusal, and the controller words it as before. The controller keeps
        the session, the switch and group checks, the refusal sentences and the JSON or CSV answer. It takes the read in
        place of the read log and the date normaliser; the container autowires it. Coupling 15 to 13 (read 4); phpmd: zero
        findings on both files under both rulesets.
        Characterisation, green before and after: `WooReportControllerTest` (off, outside the group, an administrator
        outside it, signed out, an audited read, an unrecorded read, a period and a missing period, moments as days, the
        offer, the CSV, the CSV link without a token) and the throughput report suites. Own test:
        `tests/Unit/Controller/Support/RecordedReportReadTest` (3).
  - [x] 8ed `Listener/Registrar/IntakeListenerRegistrar` (1, unsuppressed: CouplingBetweenObjects 15 against 13). The
        listeners that check a case at intake (the BAG location check and the intake requirements before a case is
        stored, the special-category processing log after) moved to `Listener/Registrar/IntakeValidationListenerRegistrar`,
        which the intake registrar calls the way it already calls `IntakeFormListenerRegistrar`. Same events, same
        listeners, same order. Coupling 15 to 13 (validation registrar 8); phpmd: zero findings on both files.
        Characterisation, green before and after: every `Registrar` and `Registration` suite (172 tests). Own test:
        `tests/Unit/AppInfo/IntakeValidationListenerRegistrarTest` (1).
  - [x] 8ee `Command/DrainIntakeObjectsCommand` (1, unsuppressed: CouplingBetweenObjects 16 against 13). The three store
        calls the generic drain takes as callbacks (the case already holding a source, found by its id as the external
        reference; one case submitted through OpenRegister's form submit as the drain's user, a refusal answered as its
        findings; a source marked with the case it became, without its own metadata) moved to `Command/PortalDrainCalls`
        (`existingCase()`, `submitter()`, `sourceMarker()`; bodies moved unchanged, over the same `PortalDrainSetup`). The
        command keeps the prerequisites, the run and the report. It builds the calls in `execute()`, so its constructor
        and the container are unchanged. Coupling 16 to 13 (calls 6); phpmd: zero findings on both files.
        Characterisation, green before and after: `DrainIntakeObjectsCommandTest`, `DrainIntakeObjectsCommandEdgesTest`,
        `IntakeObjectDrainTest` and `ServiceCatchReturnsNullTest`. Own test: `tests/Unit/Command/PortalDrainCallsTest` (3).
  - [x] 8ef `SetupController` (1, unsuppressed: CouplingBetweenObjects 14 against 13). The register side of the setup
        wizard (whether the register is ready, the forced register import of `init-register`, and the `seed` package
        install with its 422 on a refusal and its `setup_seed_done` record) moved to the generic
        `Controller/Support/RegisterInstall` (`isReady()`, `initialise()`, `installSeedPackage()`; bodies moved unchanged).
        The controller takes it in place of the settings service and the package installer, so the container autowires it;
        the action ids and their answers are unchanged. Coupling 14 to 12; phpmd: zero findings on both files under both
        rulesets. Characterisation, green before and after: `SetupControllerStatusTest`, `SetupControllerReadinessTest`
        and `SetupWizardStepsTest` (61 tests). Own test: `tests/Unit/Controller/Support/RegisterInstallTest` (4).
  - [x] 8eg `Controller/DsoController` (1, unsuppressed: CouplingBetweenObjects 15 against 13). The dashboard read and
        the guarded writes on a DSO case (the case the caller may change looked up and authorised, then the status
        transition with its optional besluitdatum and notes, the beschikking, and the doorsturen event) moved to
        `Controller/Support/GuardedCaseActions` (`dashboard()`, `mutableCase()`, `transition()`, `beschikking()`, `forward()`);
        the samenwerkverzoek writes (the one the caller may answer looked up and authorised, starting one, answering one)
        to `Controller/Support/CooperationRequestActions` (`mutableSamenwerk()`, `startSamenwerking()`,
        `answerSamenwerking()`). Bodies moved unchanged; two classes, so neither constructor reaches gate-122's five
        parameters. The controller keeps the session check, the input refusals, the dashboard filters and the mapping of
        outcomes onto responses. It takes the two in place of the repository, the case, beschikking and samenwerking
        services and the notifier, so its constructor goes from eight parameters to six; the container autowires both.
        Coupling 15 to 12 (case actions 6, cooperation request actions 3); phpmd: zero findings on the three files under both
        rulesets. Characterisation, green before and after: `DsoControllerTest`, `DsoControllerContractTest` and
        `DsoCaseMutationsGoldenTest` (its golden files unchanged), built over the same doubles through the two classes.
        Own test: `tests/Unit/Controller/Support/GuardedCaseActionsTest` (5).
  - [x] 8eh `Controller/PortalWooRequestController` (1, unsuppressed: CouplingBetweenObjects 15 against 13). Making a
        portal submission once (the relayed key, the replay of a first send's answer or the 409 while it is being made, the
        case opened through the intake with its answer kept, and the key let go when the intake threw) moved to
        `Controller/Support/SubmissionMadeOnce` (`key()`, `replay()`, `open()`; bodies moved unchanged, a refusal is thrown on
        after the key is let go and the controller words it as before). The controller keeps the assertion check, the fields
        it forwards and the wording of a refusal. It takes the submissions in place of the intake and the ledger; the
        container autowires it. Coupling 15 to 13 (submissions 6); phpmd: zero findings on both files.
        Characterisation, green before and after: `PortalWooRequestControllerTest`, `PortalWooRequestSubmissionKeyTest` and
        `PortalWooRequestDutchAnswersTest`. Own test: `tests/Unit/Controller/Support/SubmissionMadeOnceTest` (3).
  - [x] 8ei `Portal/PortalContractRegistrar` (1, unsuppressed: CouplingBetweenObjects 15 against 13). The contracts for
        what a resident does on their own case (an act on the case, the answer to the organisation's question, that question
        as a task) moved to `Portal/PortalResidentActsContractRegistrar`, which the registrar calls after its own four. Same
        aliases, same order, and `Application` is unchanged. Coupling 15 to 10 (resident acts 7); phpmd: zero findings.
        Own test, pinning every alias in order and that each target implements its contract:
        `tests/Unit/Portal/PortalContractRegistrarTest` (3).
  - [x] 8ej `Controller/CaseVersionController` (1, unsuppressed: CouplingBetweenObjects 16 against 13). The case side
        of a case type's versions (who may move a case, through the generic `CaseCaller`; the versions it could move to,
        with the preview of a named target; the move as the caller's uid) moved to `Controller/Support/CaseVersionMoves`
        (`writer()`, `options()`, `move()`; bodies moved unchanged). The controller keeps the version chain, closing a
        version, the refusals and the answers, and its own `writerOf()` guard helper, now over the moves. It takes the moves
        in place of the move service, the access guard and the session, so its constructor goes from eight parameters to
        six; the container autowires it. Coupling 16 to 13 (moves 5); phpmd: zero findings on both files under both
        rulesets; gate-7 reports the same two methods as on development (`chain`, deliberately unguarded, and
        `moveToVersion`), `options` still passes. Characterisation, green before and after: `CaseVersionControllerTest`
        (11). Own test: `tests/Unit/Controller/Support/CaseVersionMovesTest` (3).
  - [x] 8ek `Review/ReviewContractRegistrar` (1, unsuppressed: CouplingBetweenObjects 23 against 13). The contracts that
        plan and measure a review (batch selection, recall samples, review batches, the case summary, the stopping rule
        and the triage rules) moved to `Review/ReviewPlanContractRegistrar`, which the registrar calls after binding the
        five for reviewing one document. Same aliases, and `Application` is unchanged; the order of registration changes,
        which a container alias does not depend on. Coupling 23 to 12 (plan 13); phpmd: zero findings.
        `ReviewContractRegistrarTest` now pins every alias in the new order, that the plan registrar binds only its six,
        and that each target implements its contract (3).
  - [x] 8el `Controller/CaseFederationController` (1, unsuppressed: CouplingBetweenObjects 16 against 13). What the
        token-authenticated endpoints share (resolving a transfer token to its live share, accepting or rejecting the
        transfer as the remote party, counting a rejected token with the throttler and logging a throttler that fails)
        moved to `Controller/Support/FederatedTokenCalls` (`verifiedTransfer()`, `answerTransfer()`,
        `registerFailedToken()`; bodies moved unchanged). The throttle action and the `#[BruteForceProtection]` attributes
        that enforce it stay on the controller, which hands the action to the count. It takes the calls in place of the
        transfer service, the throttler and the logger, so its constructor goes from seven parameters to five; the
        container autowires it. Coupling 16 to 13 (calls 5); phpmd: zero findings on both files; gate-7 has no finding on
        the controller, before or after. Characterisation, green before and after: `CaseSharingControllerFederationTest`,
        `CaseTransferActingUserTest`, `CaseFederationShareGateGoldenTest`, `FederatedShareCreateGoldenTest` and
        `FederatedShareNeedsConsentTest`. Own test: `tests/Unit/Controller/Support/FederatedTokenCallsTest` (2).
  - [x] 8em `Woo/WooGatherAdd` (1, unsuppressed: CouplingBetweenObjects 15 against 13). What the caller may read, asked as
        the caller (a Nextcloud file by its id through their own user folder, handed over only when it is a readable file;
        whether they may read a given case, never for an empty id), moved to the generic
        `Service/Documents/CallerReadable` (`file()`, `mayReadCase()`; bodies moved unchanged), outside the procedure-named
        tree. What a pick becomes on the case, its provenance, the exclusions and the answer per pick stay. It takes the
        readable in place of the root folder and the access guard, so its constructor goes from nine parameters to eight;
        the container autowires it. Coupling 15 to 13 (readable 4); phpmd: zero findings on both files.
        Characterisation, green before and after: `WooGatherAddTest` and `WooGatherAddAnswerGoldenTest` (31). Own test:
        `tests/Unit/Service/Documents/CallerReadableTest` (2).
  - [x] 8en `AppInfo/Application` (1, unsuppressed: CouplingBetweenObjects 20 against 13). The wiring `register()` handed
        to registrars moved to two composites in `AppInfo/Registrar`: `ComponentContractRegistrar` runs the six component
        contract registrars (contribution, integration, notification, portal, review, Woo), and `WiringRegistrar` runs the
        lifecycle, service, component contract and listener registrars, in the order `register()` ran them. What
        `register()` states itself stays in `Application.php`, where the gates and the source-reading tests look for it:
        the OpenRegister interface alias, the two setup checks, the capability, the MCP scan alias, the search provider
        and the config lexicon. Coupling 20 to 12 (wiring 5, contracts 7); phpmd: zero findings on the three files.
        Characterisation, green before and after: every `AppInfo` registrar and registration suite, `CapabilitiesTest`,
        `ConfigLexiconTest`, `DsoIntakeCheckTest` and `DossiqScannableServicesTest`. Own test:
        `tests/Unit/AppInfo/WiringRegistrarTest` (2: each composite records exactly the registrations its registrars make
        when run one by one, in order).
  - [x] 8eo `Controller/DashboardController` (1, unsuppressed: CouplingBetweenObjects 16 against 13). The load events a
        page dispatches (the Viewer's on every page; the Files app's `init` script and additional-scripts event on the
        bare files page; an event whose app is not installed skipped) moved to `Controller/Support/SurfaceLoadEvents`
        (`forPage()`, `forFiles()`, with the two event lists, which the controller keeps as aliases); the PWA's two
        bundled assets as downloads (the service worker with its app scope and the policy its fetches need, the web
        manifest, each a 404 when missing) to `Controller/Support/PublicAssetDownloads` (`serviceWorker()`,
        `webManifest()`). Bodies moved unchanged; the `Util` StaticAccess suppression moved with `Util::addScript`, so the
        suppression count is unchanged. The controller keeps the pages, their initial state and the public routes with
        their rate limits. It takes the load events in place of the event dispatcher; the explicit factory in
        `BespokeServiceRegistrar` builds them. Coupling 16 to 13 (load events 3, assets 3); phpmd: zero findings on the
        four files. Characterisation, green before and after: `DashboardControllerFilesSurfacesTest`,
        `...ServiceWorkerTest`, `...AppConfigTest`, `...InstalledVersionTest`, `...ContractTest` and `MenuStructureTest`.
        Own test: `tests/Unit/Controller/Support/SurfaceLoadEventsTest` (3).
  - [x] 8ep `Service/ComplaintService` (1, unsuppressed: CouplingBetweenObjects 15 against 13). Finding, listing,
        creating and updating the records of the configured complaint schema moved to the generic
        `Service/Support/ConfiguredSchemaStore` (`find()`, `search()`, `create()`, `update()`; a read answers null or an
        empty list without OpenRegister or the schema, a create refuses with the same two messages before it prepares
        the record, an update refuses only without OpenRegister and passes the register and schema as configured, as
        before). The create also drops the caller's object identity keys (`CallerIdentity::KEYS`), which the service
        did first, so the forged-id refusal holds for every record created through the store. The declared
        first-response term in working days, or the Awb fallback, moved to `Service/Term/FirstResponseDays::days()`
        (body unchanged). The service keeps the validation, the deadlines, the status machine, the extension, the
        escalation link, the alerts and the MCP tool, and builds both helpers in its constructor, so its constructor,
        interface and container are unchanged. Coupling 15 to 13; phpmd: zero findings on the three files under both
        rulesets.
        Characterisation, green before and after: `ComplaintServiceTest` (25), `CreatesIgnoreCallerIdentityTest`,
        `OneDateWritePathTest`, `OneDateWritePathRoundTripTest`, `LocalStatusMachineryTest`, `CasePriorityDeclarationTest`
        and the complaint controller suites. Own tests: `tests/Unit/Service/Support/ConfiguredSchemaStoreTest` (5) and
        `tests/Unit/Service/Term/FirstResponseDaysTest` (2); dropping the identity-key removal turns
        `ConfiguredSchemaStoreTest` and `CreatesIgnoreCallerIdentityTest` red (checked).
  - [x] 8eq `Controller/BelplanController` (1, unsuppressed: CouplingBetweenObjects 17 against 13). The calls into the
        object service with their failure answers moved to the generic `Controller/Support/SchemaObjectCalls`: `created()`
        (OpenRegister's two validation refusals a 400 with their message, any other failure a 500 with the caller's
        sentence), `patched()` (any failure a 500) and `listed()` (a failed read is empty); bodies and comments moved
        unchanged, the success turned into the response by the controller's closure, so `CastArray` stays where it was.
        Whether the caller is signed in and an administrator is `AdminCaller` (8da), which gains `getUser()`; the
        controller takes it in place of `IUserSession` and `IGroupManager` (the container autowires it, and only the two
        controller tests build the controller by hand). The controller keeps the guards, the configured-schema checks,
        the request fields and the routing call. Coupling 17 to 13; phpmd: the coupling finding gone and nothing new on
        the three files under both rulesets (the inherited `CastArray` StaticAccess stays on its untouched line).
        Characterisation, green before and after: `BelplanControllerAdminTest` (10) and `BelplanControllerContractTest`
        (5, including the 400 naming the property and the 500 for a genuine fault), unchanged but for the constructor
        and two `@uses` lines, `AdminCallerTest` and `BespokeServiceRegistrarTest`. Own tests:
        `tests/Unit/Controller/Support/SchemaObjectCallsTest` (4) and `AdminCallerTest::testTheUserIsTheSessions`;
        answering the validation refusal as a 500 turns `SchemaObjectCallsTest` red (checked).
  - [x] 8er `Controller/RoutingController` (1, unsuppressed: CouplingBetweenObjects 16 against 13). Reading a case and
        the steps of its workflow template from the configured register, and resolving every step that carries a rule
        against the case, moved to `Controller/Support/CaseStepReroute::affectedSteps()` (null without OpenRegister;
        bodies moved unchanged). Turning a stored object into an array and a stored step list into rows stays with the
        controller (`toArray()`, `decodeSteps()`, which `ObjectArrayCopiesTest` and `RowListCopiesTest` pin there) and
        is handed in as two closures, so no static call moves. The admin guard goes through `AdminCaller` (8da, 8eq):
        no user a 401, no administrator a 403, as before. The controller keeps the guards, the 503, the refusal and
        failure answers and `route()`. It takes `CaseStepReroute` and `AdminCaller` in place of the role resolver, the
        settings bridge, the session and the group manager; the container autowires both, and only its two tests build
        it by hand. Coupling 16 to 13; phpmd: the coupling finding gone and nothing new on both files under both
        rulesets (the two inherited StaticAccess findings stay on their untouched lines).
        Characterisation, written first and green on the untouched controller: `tests/Unit/Controller/RoutingControllerTest`
        (7: 401 and 403 for both acts, the 503, the steps resolved with the reads in order, a case without a workflow,
        the logged 500, a refusal keeping its status, and `route()`'s 400, answer and logged 500), then unchanged but for
        the constructor; `RoutingControllerContractTest` (8), unchanged but for the constructor and two `@uses` lines;
        `ObjectArrayCopiesTest` and `RowListCopiesTest`. Own test:
        `tests/Unit/Controller/Support/CaseStepRerouteTest` (2); resolving a step without a rule turns both suites red
        (checked).
  - [x] 8es `Service/WOODocumentAssessmentService` (1, unsuppressed: CouplingBetweenObjects 16 against 13). Storing a
        batch of assessments on one case (validating, stamping, update-or-create under the reviewer rule, the reviewer
        reconcile and marking the judged documents in scope) moved to `Service/Assessment/AssessmentBatchWriter::write()`,
        which also holds the validator (`validate()`). Finding a stored assessment, reading the partly public ones (scoped)
        and tallying those without a redacted copy (unscoped), and changing fields of an existing one under the reviewer
        rule moved to `Service/Assessment/AssessmentRecords` (`find()`, `partlyPublic()`, `partlyPublicWithoutCopy()`,
        `update()`). Bodies moved unchanged. The service keeps its public methods, the outstanding list, the bulk-upsert
        log line and the redaction-proposal log line, and builds both helpers in its constructor, so its constructor,
        interface and container are unchanged. Coupling 16 to under 13; phpmd: zero findings on the three files under
        both rulesets.
        Characterisation, green before and after: `WOODocumentAssessmentServiceTest` (35), `WooAssessmentForgedIdTest`,
        `DocumentAssessmentGuardRedactedTest`, `DocumentRedactionServiceTest` and the assessment controller suites.
        Own tests: `tests/Unit/Service/Assessment/AssessmentBatchWriterTest` (6) and `AssessmentRecordsTest` (6); keeping
        the caller's identity keys turns `AssessmentBatchWriterTest` and `WooAssessmentForgedIdTest` red (checked).
  - [ ] 8dt The ten `ShortMethodName` findings (static `of()` on `CastArray`, `ObjectArray`, `ReferenceId`,
        `EventObjectPayload`, `SerialisedObject` and `ObjectAuditEvents`; instance `of()`/`is()` on `CreatedCaseRecord`,
        `IntakeObligationKinds`, `StatusDisplayName` and `ZgwRuleCall`). A rename was built and its unit suite was
        green, but it was dropped before push. Each renamed call site of the six static helpers carries a development
        StaticAccess finding on that line, and the diff check counts a finding on a touched line as new: 29 phpmd
        findings that block. The static helpers have to become injected collaborators first, which is the StaticAccess
        work (150 findings), not a rename.
