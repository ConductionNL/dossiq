---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

# ZGW Business Rules Compliance — implementation surface (retrofit)

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
