---
status: done
base_spec: dossiq-case-management
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
- **AND** `archiefnominatie` MUST be taken from the resultaattype's archival action
- **AND** the derivation MUST be the SAME for a zaak closed over this API and a case closed in
  the app: one implementation (`Service\Archival\ArchivalNominationDeriver`), called by both.
  Two implementations that agree today is the defect, not the fix
- **AND** an `afleidingswijze` of `afgehandeld` MUST resolve to the zaak's einddatum. The
  English `handled` is accepted alongside it and is produced by nothing
- **AND** a resultaattype archival action of `bewaren` MUST be recorded as `blijvend_bewaren`,
  which is the value `archiefnominatie` declares and means the same thing

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

`OCA\Dossiq\Service\ZgwRulesBase` SHALL hold the cross-cutting helpers used by every per-API rules service: a per-request context (`setContext(ObjectService, mappingConfig)`), reusable cross-register lookups, mapping resolution by URL/UUID, error-shape construction for ZGW 400/409 validation errors, and the helpers for resolving slug references back to OpenRegister objects.

#### Scenario: Per-request context propagation
- **WHEN** a controller invokes a rules service for a write operation
- **THEN** the controller SHALL call `ZgwRulesBase::setContext($objectService, $mappingConfig)` first so subsequent rule checks can resolve cross-register URL references against the same register stack

Notes
- All five per-API rule services extend `ZgwRulesBase`; do not inline cross-register lookups in subclasses.

### REQ-002: ZgwBusinessRulesService SHALL act as the cross-component validator facade

`OCA\Dossiq\Service\ZgwBusinessRulesService` SHALL provide the single `validate(...)` entry point used by `ZgwService` to fan a write operation out to the correct per-API rule service (BRC, DRC, ZTC, ZRC). The facade SHALL collect per-API errors and return them as a single ZGW-shaped `invalidParams` array.

#### Scenario: Validate a besluit before persistence
- **GIVEN** a controller about to persist a `besluit` POST body
- **WHEN** it calls `ZgwBusinessRulesService::validate($body, $apiGroup='besluiten', $resource='besluiten', $operation='create')`
- **THEN** the facade SHALL dispatch to `ZgwBrcRulesService::rulesBesluitenCreate()` and surface any returned validation errors as the ZGW 400 response

### REQ-003: ZgwBrcRulesService SHALL enforce BRC besluit validation rules

`OCA\Dossiq\Service\ZgwBrcRulesService` SHALL validate BRC writes for `besluiten` (Create/Update/Patch) and `besluitinformatieobjecten` (Create) against the BRC v1.1.0 standard. Rules SHALL include: required-field presence, value-set conformance (vervalreden, beslissingstype), cross-reference integrity to ZTC besluittype, immutability of identifying fields on Update/Patch, and zaak-state preconditions.

#### Scenario: Reject besluit Create without required beslissingstype
- **WHEN** `rulesBesluitenCreate()` receives a body missing `beslissingstype`
- **THEN** it SHALL return an `invalidParams` entry with `code: required` and `name: beslissingstype`

### REQ-004: ZgwDrcRulesService SHALL enforce DRC document validation rules

`OCA\Dossiq\Service\ZgwDrcRulesService` SHALL validate DRC writes for `enkelvoudiginformatieobjecten` (Create/Update/Patch/Destroy), `objectinformatieobjecten` (Create) and related resources against the DRC v1.4.3 standard. Rules SHALL include: required-field presence, vertrouwelijkheid enum membership, lock-state checks (write only allowed if locked by the same client), and zaak-relation integrity.

#### Scenario: Reject patch on locked document by other client
- **GIVEN** an existing document locked by client A
- **WHEN** client B calls `rulesEnkelvoudiginformatieobjectenPatch($body, $existingObject)`
- **THEN** the rules service SHALL return an `invalidParams` lock-violation error and the write SHALL be blocked

### REQ-005: ZgwZtcRulesService SHALL enforce ZTC catalogus validation including concept protection

`OCA\Dossiq\Service\ZgwZtcRulesService` SHALL validate ZTC writes for `zaaktypen`, `besluittypen`, `resultaattypen` and `zaaktype-informatieobjecttypen` per ZTC v1.3.1, including concept protection: only objects with `concept=true` may be mutated; published objects SHALL be immutable. The service SHALL also default `concept=true` on Create when the body omits it and preserve the existing concept flag on Update/Patch unless explicit caller intent is signalled.

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

### Requirement: The ZGW publish endpoint runs the publish guard (REQ-ZBR-024)

The ZGW Catalogi publish endpoints for zaaktypen, besluittypen and informatieobjecttypen SHALL require the scope `catalogi.schrijven`, like every other ZTC write. A caller without it SHALL get 403 with code `permission_denied`, and nothing SHALL be read or saved.

Publishing a draft zaaktype SHALL run the same CT-02b guard as an update that sets `isDraft` to false, read the ZGW way: when the zaaktype has status types, one of them SHALL be final, by its own flag or, as ZGW derives `isEindstatus`, as the one with the highest volgnummer; and the zaaktype SHALL have a valid-from date. A zaaktype without status types MAY be published over the ZGW API, as the standard and its conformance collections allow; the admin publish still requires one (CT-02c). A zaaktype that fails it SHALL be refused with 422, code `publish_validation_failed`, and a `detail` that lists what is missing. A guard that could not read the store SHALL refuse with 503, code `check-not-completed`. A refused publish SHALL NOT save the type.

#### Scenario: A caller without the write scope publishes
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishControllerContractTest "all three publish routes require the catalogi write scope". -->
- **GIVEN** a ZGW client whose scopes do not include `catalogi.schrijven`
- **WHEN** it publishes a zaaktype, a besluittype or an informatieobjecttype
- **THEN** the answer is 403 with code `permission_denied`
- **AND** the type stays a draft

#### Scenario: A zaaktype without a final status is published
<!-- @e2e exclude ZGW API route, no UI. Proven by ZgwBusinessRulesServicePublishEndpointTest and ZtcPublishControllerContractTest "a zaaktype the publish guard refuses is not saved". -->
- **GIVEN** a draft zaaktype with status types, none flagged final and none with a volgnummer
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 422 with code `publish_validation_failed`
- **AND** the type stays a draft

#### Scenario: A complete zaaktype is published
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishControllerContractTest "a zaaktype the publish guard passes is published". -->
- **GIVEN** a draft zaaktype with status types, one of them final, and a valid-from date
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 201 and the type is published

#### Scenario: A ZGW client publishes a zaaktype whose status types carry only volgnummers
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishAndDeleteChecksTest "a volgnummer makes the highest status type final" and by the ZGW business rules Newman collection. -->
- **GIVEN** a draft zaaktype with a valid-from date and status types with volgnummers 1 and 2, none flagged `isEindstatus`
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 201 and the type is published

#### Scenario: A ZGW client publishes a zaaktype without status types
<!-- @e2e exclude ZGW API route, no UI. Proven by ZtcPublishAndDeleteChecksTest "a zaaktype without status types may be published" and by ztc-009 in the ZGW business rules Newman collection. -->
- **GIVEN** a draft zaaktype with a valid-from date and no status types
- **WHEN** a client with `catalogi.schrijven` publishes it
- **THEN** the answer is 201 and the type is published

### Requirement: The gateway asks the catalogi write scope for a publish

The ZGW authorization middleware SHALL require `catalogi.schrijven` for a POST to `/catalogi/v1/{zaaktypen|besluittypen|informatieobjecttypen}/{uuid}/publish`, the same scope the publish routes check. Every other catalogi request keeps the scope its method maps to. Before this, the middleware asked `catalogi.aanmaken`, which the Catalogi API does not define, so a client holding `catalogi.schrijven` was refused before the publish guard ran (sandbox live pass, 25 Nov 2026).

#### Scenario: a client with the write scope publishes

@e2e exclude {scope gate, asserted in tests/Unit/Middleware/ZgwAuthMiddlewarePublishScopeTest.php; run live in the 2.1 pass}

- **GIVEN** a consumer whose ztc grant is `catalogi.lezen` and `catalogi.schrijven`
- **WHEN** it posts `/catalogi/v1/zaaktypen/{uuid}/publish`
- **THEN** the middleware SHALL let the request through to the publish guard
- **AND** a consumer with only `catalogi.lezen` SHALL be refused with 403
