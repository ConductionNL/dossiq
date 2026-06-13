---
status: draft
base_spec: procest-case-management
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

# ZGW Business Rules Compliance -- Delta Spec

## Purpose

@e2e exclude Pure API delta spec covered by Newman test suite; no Playwright UI surface.

Fix ~56 failing VNG Newman test suite assertions across ZRC, ZTC, DRC, and BRC business rules. Optimize ZGW endpoint performance from 2-5s to under 200ms per request.

## Changed Requirements

### ZRC Business Rules

#### ZRC-007: Eindstatus and Zaak Closing
- **GIVEN** a status is created for a zaak **AND** the statustype has the highest volgnummer for the zaaktype
- **WHEN** `isEindstatus` is not explicitly set on the statustype
- **THEN** the system MUST treat the statustype with the highest volgnummer as the eindstatus and set `einddatum` on the zaak

#### ZRC-007b: Gebruiksrecht on Close
- **GIVEN** a zaak is being closed (eindstatus set)
- **WHEN** there are linked informatieobjecten without `indicatieGebruiksrecht`
- **THEN** the system MUST set `indicatieGebruiksrecht` on all linked informatieobjecten

#### ZRC-007q: Gebruiksrecht Validation
- **GIVEN** an eindstatus is being created for a zaak
- **WHEN** any linked informatieobject lacks `indicatieGebruiksrecht`
- **THEN** the system MUST reject the status creation with a validation error

#### ZRC-008c: Heropenen Scope Check
- **GIVEN** a consumer attempts to reopen a closed zaak
- **WHEN** the consumer lacks the `zaken.heropenen` scope
- **THEN** the system MUST return a 403 Forbidden

#### ZRC-010: Communicatiekanaal Validation
- **GIVEN** an invalid communicatiekanaal URL is provided
- **THEN** the error code MUST be `bad-url` (not `invalid-resource`)

#### ZRC-013a: Hoofdzaak Not Found
- **GIVEN** a hoofdzaak reference resolves to a non-existent zaak
- **THEN** the error code MUST be `does-not-exist` (not `no_match`)

#### ZRC-015: ProductenOfDiensten Validation
- **GIVEN** productenOfDiensten URLs are provided on a zaak
- **THEN** each URL MUST be a subset of the zaaktype's allowed productenOfDiensten

#### ZRC-016/018/019/020: Cross-Type Validation
- **GIVEN** a sub-resource (status, resultaat, rol, zaakeigenschap) is created
- **THEN** its type MUST belong to the zaak's zaaktype

#### ZRC-021: Archiefactiedatum Derivation
- **GIVEN** a resultaat is set on a zaak
- **THEN** `archiefactiedatum` MUST be derived from the resultaattype's `brondatumArchiefprocedure`

#### ZRC-002: Identification Uniqueness
- **GIVEN** a zaak with `identificatie` + `bronorganisatie` combination
- **THEN** the combination MUST be unique across all zaken

#### ZRC-005b/023h: Cascade Delete
- **GIVEN** a ZaakInformatieObject or zaak is deleted
- **THEN** the corresponding ObjectInformatieObject MUST also be deleted

#### ZRC-009: Vertrouwelijkheidaanduiding Default
- **GIVEN** a zaak is created without explicit vertrouwelijkheidaanduiding
- **THEN** it MUST be derived from the zaaktype without template leakage

#### ZRC-006: Authorization Filtering
- **GIVEN** a consumer lists zaken
- **THEN** results MUST be filtered by the consumer's authorized zaaktypen and maximum vertrouwelijkheidaanduiding

### Performance Requirements

#### Endpoint Response Time
- **GIVEN** any ZGW API request
- **WHEN** the request is processed
- **THEN** average response time MUST be under 200ms
- **Implementation**: Replace manual cross-register lookups with OpenRegister property inversion and optimized search

## Files Affected
- `ZrcController.php`
- `ZgwZrcRulesService.php`
- `ZgwRulesBase.php`
- `ZgwBusinessRulesService.php`
- `ZgwService.php`
- `ZgwZtcRulesService.php`
- `ZgwDrcRulesService.php`
- `ZgwBrcRulesService.php`

<!-- BEGIN retrofit-2026-05-24-zgw-business-rules-compliance -->

## Requirements

### REQ-001: ZgwRulesBase SHALL provide a shared base class for all per-API rule services

`OCA\Procest\Service\ZgwRulesBase` SHALL hold the cross-cutting helpers used by every per-API rules service: a per-request context (`setContext(ObjectService, mappingConfig)`), reusable cross-register lookups, mapping resolution by URL/UUID, error-shape construction for ZGW 400/409 validation errors, and the helpers for resolving slug references back to OpenRegister objects.

#### Scenario: Per-request context propagation
- **WHEN** a controller invokes a rules service for a write operation
- **THEN** the controller SHALL call `ZgwRulesBase::setContext($objectService, $mappingConfig)` first so subsequent rule checks can resolve cross-register URL references against the same register stack

Notes
- All five per-API rule services extend `ZgwRulesBase`; do not inline cross-register lookups in subclasses.

### REQ-002: ZgwBusinessRulesService SHALL act as the cross-component validator facade

`OCA\Procest\Service\ZgwBusinessRulesService` SHALL provide the single `validate(...)` entry point used by `ZgwService` to fan a write operation out to the correct per-API rule service (BRC, DRC, ZTC, ZRC). The facade SHALL collect per-API errors and return them as a single ZGW-shaped `invalidParams` array.

#### Scenario: Validate a besluit before persistence
- **GIVEN** a controller about to persist a `besluit` POST body
- **WHEN** it calls `ZgwBusinessRulesService::validate($body, $apiGroup='besluiten', $resource='besluiten', $operation='create')`
- **THEN** the facade SHALL dispatch to `ZgwBrcRulesService::rulesBesluitenCreate()` and surface any returned validation errors as the ZGW 400 response

### REQ-003: ZgwBrcRulesService SHALL enforce BRC besluit validation rules

`OCA\Procest\Service\ZgwBrcRulesService` SHALL validate BRC writes for `besluiten` (Create/Update/Patch) and `besluitinformatieobjecten` (Create) against the BRC v1.1.0 standard. Rules SHALL include: required-field presence, value-set conformance (vervalreden, beslissingstype), cross-reference integrity to ZTC besluittype, immutability of identifying fields on Update/Patch, and zaak-state preconditions.

#### Scenario: Reject besluit Create without required beslissingstype
- **WHEN** `rulesBesluitenCreate()` receives a body missing `beslissingstype`
- **THEN** it SHALL return an `invalidParams` entry with `code: required` and `name: beslissingstype`

### REQ-004: ZgwDrcRulesService SHALL enforce DRC document validation rules

`OCA\Procest\Service\ZgwDrcRulesService` SHALL validate DRC writes for `enkelvoudiginformatieobjecten` (Create/Update/Patch/Destroy), `objectinformatieobjecten` (Create) and related resources against the DRC v1.4.3 standard. Rules SHALL include: required-field presence, vertrouwelijkheid enum membership, lock-state checks (write only allowed if locked by the same client), and zaak-relation integrity.

#### Scenario: Reject patch on locked document by other client
- **GIVEN** an existing document locked by client A
- **WHEN** client B calls `rulesEnkelvoudiginformatieobjectenPatch($body, $existingObject)`
- **THEN** the rules service SHALL return an `invalidParams` lock-violation error and the write SHALL be blocked

### REQ-005: ZgwZtcRulesService SHALL enforce ZTC catalogus validation including concept protection

`OCA\Procest\Service\ZgwZtcRulesService` SHALL validate ZTC writes for `zaaktypen`, `besluittypen`, `resultaattypen` and `zaaktype-informatieobjecttypen` per ZTC v1.3.1, including concept protection: only objects with `concept=true` may be mutated; published objects SHALL be immutable. The service SHALL also default `concept=true` on Create when the body omits it and preserve the existing concept flag on Update/Patch unless explicit caller intent is signalled.

#### Scenario: Reject update of published zaaktype
- **GIVEN** an existing `zaaktype` with `concept=false`
- **WHEN** `checkConceptProtection($existingObject)` is called
- **THEN** the service SHALL return an error blocking the write

#### Scenario: Default new zaaktype to concept=true
- **WHEN** `defaultConcept($body, 'zaaktypen')` is called with a body missing the `concept` field
- **THEN** the returned body SHALL have `concept: true`

Notes
- `ZgwZrcRulesService` is partially annotated under enforcement-lhs (1/19 methods). A follow-up REQ for ZRC rules will land once that cluster is reverse-specced separately.

<!-- END retrofit-2026-05-24-zgw-business-rules-compliance -->
