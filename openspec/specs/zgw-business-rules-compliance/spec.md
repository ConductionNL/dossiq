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

### Requirement: A rule that cannot run its check refuses the request (REQ-ZBR-020)

A ZGW business rule that reads the store to decide SHALL tell "the lookup ran and found nothing" from "the lookup could not run". The first is an answer and the rule decides on it. The second is not, and the rule SHALL refuse the request.

The refusal SHALL be HTTP 503 with a `detail` that names the rule and says the check could not be completed, and the `code` `check-not-completed`. It SHALL carry no `invalidParams`: nothing in the request is wrong. Nothing SHALL be stored or deleted.

Every such refusal SHALL be logged at error with the rule and the object it was checking.

This holds for drc-003 (a relation is new), drc-004 (the ZaakInformatieObject or BesluitInformatieObject exists), drc-007 (no ObjectInformatieObject points at the document) and the relation check before a document delete (drc-008a).

A rule SHALL NOT answer a finding it did not make. A duplicate search that failed is not a duplicate.

#### Scenario: The counterpart lookup of drc-004 fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by DrcObjectLinkRulesTest "a found counterpart passes and a failed lookup refuses" and ZgwDrcRulesServiceTest. -->
- **GIVEN** an objectinformatieobject for a case and a document
- **WHEN** the search for the ZaakInformatieObject throws
- **THEN** the create is refused with 503 and code `check-not-completed`
- **AND** the detail names drc-004
- **AND** the log holds the rule, the case and the document

#### Scenario: The counterpart lookup of drc-004 finds nothing
<!-- @e2e exclude Unchanged behaviour, proven by DrcObjectLinkRulesTest "a missing counterpart is inconsistent". -->
- **GIVEN** the same objectinformatieobject
- **WHEN** the search runs and finds no ZaakInformatieObject
- **THEN** the create is refused with 400 and code `inconsistent-relation`

#### Scenario: The relation search of a document delete fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwDrcRulesServiceTest and DrcEioLifecycleTest "a document whose relations cannot be read is not deleted". -->
- **GIVEN** a document
- **WHEN** a search for the relations that point at it throws
- **THEN** the delete is refused with 503 and code `check-not-completed`
- **AND** the document, its gebruiksrechten and its files stay

#### Scenario: The duplicate search of drc-003 fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by DrcDocumentRelationsTest "a failed duplicate search refuses as not verified". -->
- **GIVEN** an objectinformatieobject nobody stored before
- **WHEN** the duplicate search throws
- **THEN** the create is refused with 503 and code `check-not-completed`
- **AND** the answer does not say the combination exists

### Requirement: A case type is not deleted when its cases cannot be read (REQ-ZBR-021)

The delete check of a case type SHALL block the delete when it cannot read the cases that use the type, or the final statuses that say which of them are closed. The ZGW delete SHALL answer 503 with the code `destroy_check_not_completed` and a detail that says the check could not be completed. A confirmation flag SHALL NOT override it.

A check that ran and found no cases SHALL let the delete through, as before. Active cases stay a 409 `destroy_blocked_active_cases`, and closed cases stay a 409 `destroy_requires_confirmation` until confirmed.

The refusal SHALL be logged at error with the check and the case type.

#### Scenario: The cases cannot be read
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZtcPublishAndDeleteChecksTest "a check that cannot run blocks the delete" and ZgwBusinessRulesServiceDestroyGuardTest. -->
- **GIVEN** a case type
- **WHEN** the search for its cases throws
- **THEN** the delete is refused with 503 and code `destroy_check_not_completed`
- **AND** the case type stays

#### Scenario: Nobody uses the case type
<!-- @e2e exclude Unchanged behaviour, proven by ZtcPublishAndDeleteChecksTest "an unused case type may be deleted". -->
- **GIVEN** a case type
- **WHEN** the search runs and finds no cases
- **THEN** the delete goes through

### Requirement: A reference or uniqueness read that fails refuses the write (REQ-ZBR-022)

The store reads every ZGW rule shares (`Zgw/ZgwRuleLookups`) SHALL tell "the read ran and found nothing" from "the read could not run". A read that could not run SHALL throw, and the entry point of the rules SHALL refuse the request with the answer of REQ-ZBR-020: HTTP 503, code `check-not-completed`, no `invalidParams`, logged at error with the rule and what it was reading.

This holds for the identification uniqueness check (a duplicate nobody could search for is not a free identification) and for reference resolution (a zaaktype, informatieobjecttype or besluittype that could not be read is not a type that does not exist).

An object the store does not hold, or does not show the caller, SHALL still read as none, as before. A lookup with nothing to read in, because the request has no store or the setting that names its schema is empty, SHALL still be skipped: an instance without a decision register cannot hold a decision either.

#### Scenario: The uniqueness search fails
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwRuleLookupsTest "a pair is free only when the search ran", ZgwRulesBaseUniquenessTest and ZgwBusinessRulesServiceLookupFailureTest. -->
- **GIVEN** a zaak with an identificatie and a bronorganisatie
- **WHEN** the search for another zaak with that identificatie throws
- **THEN** the create is refused with 503 and code `check-not-completed`
- **AND** the answer does not say the identificatie is taken

#### Scenario: A referenced type cannot be read
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwRuleLookupsTest "an object by uuid and schema key" and ZgwBusinessRulesServiceLookupFailureTest "a rule whose read failed refuses the write". -->
- **GIVEN** a write that refers to a zaaktype
- **WHEN** reading that zaaktype throws for another reason than "not found" or "not allowed"
- **THEN** the write is refused with 503 and code `check-not-completed`
- **AND** the log names the rule and the zaaktype

#### Scenario: The referenced type does not exist
<!-- @e2e exclude Unchanged behaviour, proven by ZgwRuleLookupsTest "an object by uuid and schema key". -->
- **GIVEN** a write that refers to a zaaktype the store does not hold
- **WHEN** the read answers "not found"
- **THEN** the rule decides as before, on a type that is not there

### Requirement: A ZGW read, update or delete says why it failed (REQ-ZBR-023)

A ZGW show, update or delete of a stored object SHALL sort a failure of the store into three answers, in one place for every resource:

- **not found** (404, `detail` "Not found"): the store says the object is not there, by a DoesNotExistException or by answering no object;
- **not allowed** (403, code `permission_denied`): the store refuses the caller, by OpenRegister's NotAuthorizedException or any failure carrying code 403;
- **not completed** (503, code `store-not-reached`): any other failure. Nothing SHALL be assumed about the object, and the failure SHALL be logged at error with the operation and the object.

A refused permission SHALL NOT answer 503. A store that was down SHALL NOT answer 404.

A delete that happened SHALL answer 204 even when its notification fails afterwards; that failure SHALL be logged at error.

The writes the Besluiten API makes in another register (the zaakbesluit of brc-006, the objectinformatieobject of brc-005a, brc-005b and brc-009) stay best effort, but a failure SHALL be logged at error with the rule, the resource and the objects involved.

#### Scenario: The store is down when a besluit is deleted
<!-- @e2e exclude A store outage cannot be staged on the e2e instance. Proven by ZgwServiceStoreFailureTest and BrcControllerTest "a missing resource cannot be deleted". -->
- **GIVEN** a stored besluit
- **WHEN** deleting it fails because the store cannot be reached
- **THEN** the delete answers 503 with code `store-not-reached`
- **AND** no notification goes out

#### Scenario: The caller may not delete the object
<!-- @e2e exclude Proven by ZgwServiceStoreFailureTest "a refused delete answers forbidden" and BrcDocumentLinkHandlerTest. -->
- **GIVEN** a stored object the caller may not delete
- **WHEN** OpenRegister refuses the delete
- **THEN** the delete answers 403 with code `permission_denied`

#### Scenario: The object is not there
<!-- @e2e exclude Unchanged behaviour, proven by ZgwServiceStoreFailureTest "a delete answers not found only for an absent object". -->
- **GIVEN** an id the store does not hold
- **WHEN** it is deleted
- **THEN** the delete answers 404 "Not found"
