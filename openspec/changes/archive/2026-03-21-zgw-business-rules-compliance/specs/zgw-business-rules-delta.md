---
status: draft
base_spec: procest-case-management
---

# ZGW Business Rules Compliance -- Delta Spec

## Purpose
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
