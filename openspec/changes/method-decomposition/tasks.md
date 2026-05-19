# Tasks: Method Decomposition — Procest (#463)

## V1 — Priority 1 files

### Task 1: ZrcController handlers (REQ-DECOMP-01)

- [x] 1.1 Create `lib/Controller/ZrcController/ZaakAuthorizationHandler.php` with `filterZakenByAuthorisation()`, `checkZaakReadAccess()`, and `permissionDeniedResponse()` extracted from ZrcController
- [x] 1.2 Create `lib/Controller/ZrcController/ZaakValidationHandler.php` with `preValidateZaakBody()` and `preValidateProductenOfDiensten()` extracted from ZrcController
- [x] 1.3 Create `lib/Controller/ZrcController/ZaakDeleteHandler.php` with `destroyZaak()` extracted from ZrcController
- [x] 1.4 Create `lib/Controller/ZrcController/EindstatusHandler.php` with `checkIndicatieGebruiksrechtBeforeClose()`, `handleEindstatusEffect()`, `deriveArchiefactiedatum()`, `resolveArchiveBaseDate()` extracted from ZrcController
- [x] 1.5 Update `ZrcController` constructor to inject all four handlers; delegate from the existing private methods
- [ ] 1.6 Remove class-level CouplingBetweenObjects, ExcessiveClassLength, ExcessiveClassComplexity, TooManyMethods suppressions from ZrcController (or verify they are no longer needed)
- [x] 1.7 Write `tests/Unit/Controller/ZrcController/ZaakAuthorizationHandlerTest.php`
- [x] 1.8 Write `tests/Unit/Controller/ZrcController/ZaakValidationHandlerTest.php`
- [x] 1.9 Write `tests/Unit/Controller/ZrcController/ZaakDeleteHandlerTest.php`
- [x] 1.10 Write `tests/Unit/Controller/ZrcController/EindstatusHandlerTest.php`

### Task 2: ZgwService decomposition (REQ-DECOMP-02)

- [x] 2.1 Create `lib/Service/JwtValidationService.php` extracting `validateJwtToken()`, `validateJwtSignature()`, `consumerHasScope()`, `getConsumerAuthorisaties()` from ZgwService
- [x] 2.2 Create `lib/Service/ZgwSubResourceResolver.php` extracting `resolveZaakClosed()`, `resolveZaakClosedFromBody()`, `resolveParentZaaktypeDraft()`, `resolveParentZaaktypeDraftFromBody()` from ZgwService
- [x] 2.3 Update `ZgwService` to inject and delegate to the two new services; keep public methods as thin wrappers
- [x] 2.4 Write `tests/Unit/Service/JwtValidationServiceTest.php`
- [x] 2.5 Write `tests/Unit/Service/ZgwSubResourceResolverTest.php`

### Task 3: ZgwZrcRulesService decomposition (REQ-DECOMP-03)

- [ ] 3.1 Create `lib/Service/StatusTransitionValidator.php` extracting `validateStatusTransition()`, `handleZaakStatusUpdate()` logic
- [x] 3.2 Update `ZgwZrcRulesService` to decompose `validateZaakFields()` into private sub-methods (validateCommunicatiekanaal, validateRelevanteAndereZaken, validateOpschorting, validateVerlenging, validateBetalingsindicatieWithExisting, validateArchiefstatus); removed 3 PHPMD suppressions
- [ ] 3.3 Write `tests/Unit/Service/StatusTransitionValidatorTest.php`

### Task 4: ZgwZtcRulesService decomposition (REQ-DECOMP-04)

- [ ] 4.1 Create `lib/Service/ZaaktypeValidator.php` extracting zaaktype create validation methods
- [x] 4.2 Create `lib/Service/ZgwReferenceResolver.php` extracting reference resolution methods (shared across rules services)
- [x] 4.3 Update `ZgwZtcRulesService` to inject ZgwReferenceResolver and delegate resolveTypeReferences/resolveGerelateerdeZaaktypen
- [ ] 4.4 Write `tests/Unit/Service/ZaaktypeValidatorTest.php`
- [x] 4.5 Write `tests/Unit/Service/ZgwReferenceResolverTest.php`

### Task 5: ZtcController handlers (REQ-DECOMP-05)

- [x] 5.1 Create `lib/Controller/ZtcController/CrossReferenceEnricher.php` (enrichCrossReferences, enrichBesluittype, enrichZaaktype, resolveIotByOmschrijving)
- [x] 5.2 Create `lib/Controller/ZtcController/CatalogiFilterHandler.php` (filterByDatumGeldigheid, filterValidUrls, isUrlValid)
- [x] 5.3 Update `ZtcController` to inject handlers and delegate private methods
- [x] 5.4 Write `tests/Unit/Controller/ZtcController/CrossReferenceEnricherTest.php`
- [x] 5.5 Write `tests/Unit/Controller/ZtcController/CatalogiFilterHandlerTest.php`

### Task 6: BrcController + ZgwBrcRulesService (REQ-DECOMP-06)

- [x] 6.1 Create `lib/Controller/BrcController/BesluitDestroyHandler.php` and `BesluitInformatieObjectHandler.php`
- [x] 6.2 Update `BrcController` to delegate to handlers
- [x] 6.3 Write `tests/Unit/Controller/BrcController/BesluitDestroyHandlerTest.php` and `BesluitInformatieObjectHandlerTest.php`

### Task 7: DrcController + ZgwDrcRulesService (REQ-DECOMP-07)

- [x] 7.1 Create `lib/Controller/DrcController/ChunkUploadHandler.php`
- [x] 7.2 Update `DrcController` to delegate `uploadChunk` to `ChunkUploadHandler`
- [x] 7.3 Write `tests/Unit/Controller/DrcController/ChunkUploadHandlerTest.php`

### Task 8: ZgwBusinessRulesService + ZgwRulesBase (REQ-DECOMP-08)

- [x] 8.1 Create `lib/Service/FieldValidator.php` extracting date/URL field validation
- [ ] 8.2 Update `ZgwBusinessRulesService` and `ZgwRulesBase` to use `FieldValidator`
- [x] 8.3 Write `tests/Unit/Service/FieldValidatorTest.php`

## V2 — Priority 2+3 files

### Task 9: AcController (REQ-DECOMP-09)

- [ ] 9.1 Create `lib/Controller/AcController/AutorisatieHandler.php`
- [ ] 9.2 Update `AcController` to delegate to handler
- [ ] 9.3 Write `tests/Unit/Controller/AcController/AutorisatieHandlerTest.php`

### Task 10: LoadDefaultZgwMappings (REQ-DECOMP-10)

- [ ] 10.1 Extract mapping arrays to JSON files in `lib/Settings/zgw_mappings/`
- [ ] 10.2 Update `LoadDefaultZgwMappings` to load from JSON files
- [ ] 10.3 Verify class length drops below 1000 lines

### Task 11: Single-suppression files (REQ-DECOMP-11)

- [ ] 11.1 Reduce `ZgwMappingService` class length (extract transformation logic)
- [ ] 11.2 Apply lazy-loading in `ZgwDocumentService`, `NotificatieService`, `ZgwAuthMiddleware`

## Quality gates

- [ ] Q1 `composer check:strict` passes with 0 errors
- [ ] Q2 `vendor/bin/phpunit -c phpunit.xml --no-coverage` passes
- [ ] Q3 Hydra gates pass (`/hydra-gates --scope-to-diff`)
- [ ] Q4 PHPMD reports 0 violations on changed files
