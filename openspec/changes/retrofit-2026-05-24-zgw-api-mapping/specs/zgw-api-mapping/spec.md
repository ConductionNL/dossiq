---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

# ZGW API Mapping — Procest-side surface (retrofit)

## Requirements

### REQ-001: Procest SHALL expose ZGW Zaakregistratiecomponent (ZRC) endpoints via ZrcController

`OCA\Procest\Controller\ZrcController` SHALL serve the ZGW Zaken API resources (zaken, statussen, resultaten, rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten) using the shared `ZgwService` for inbound/outbound translation against English-language OpenRegister schemas. The controller SHALL handle list/show/create/update/patch/destroy plus the nested `zaakeigenschappen*` and `zaakbesluiten` sub-resources, expose `/zoek` search, and serve audit-trail lookup endpoints.

#### Scenario: List zaken with outbound mapping
- **WHEN** a client calls `GET /api/zgw/zaken/v1/zaken/`
- **THEN** `ZrcController::index('zaken')` SHALL load the outbound Mapping for `zaken`, query the configured register/schema, and return ZGW-compliant JSON with Dutch property names
- **AND** the response SHALL be wrapped in the HAL-style pagination envelope from `ZgwPaginationHelper`

#### Scenario: Zaak with eindstatus side-effect
- **GIVEN** a zaak status transition resolves to a status type marked as eindstatus
- **WHEN** `ZrcController::create('statussen')` (or patch/update on statussen) is invoked
- **THEN** the corresponding zaak object SHALL be flagged closed and any eindstatus side effects SHALL be applied as a single atomic operation

Notes
- ZRC currently delegates shared CRUD to `ZgwService`; ZRC-specific logic (closed-zaak resolution, vertrouwelijkheid filtering, OIO sync) lives inline in this controller and is partially exercised by `zrc-005`/`zrc-006` integration-test fixtures.

### REQ-002: Procest SHALL expose ZTC, DRC, BRC and NRC endpoints via dedicated controllers

`OCA\Procest\Controller\ZtcController` (Catalogi API), `DrcController` (Documenten API), `BrcController` (Besluiten API) and `NrcController` (Notificaties API) SHALL each serve the canonical ZGW resources for their API per the VNG ZGW standard versions tracked in this spec (ZTC v1.3.1, DRC v1.4.3, BRC v1.1.0, NRC v1.0.0). Each controller SHALL reuse `ZgwService` for shared mapping execution, pagination, and ZGW response shaping while implementing API-specific resource paths.

#### Scenario: List catalogi via ZTC
- **WHEN** a client calls `GET /api/zgw/catalogi/v1/catalogussen/`
- **THEN** `ZtcController::index('catalogussen')` SHALL return ZGW-compliant catalog objects via the catalogi outbound Mapping

#### Scenario: Send notificatie via NRC
- **WHEN** an event triggers a notificatie dispatch through `NotificatieService`
- **THEN** the configured NRC subscriber URLs SHALL be POSTed the ZGW-shaped notification payload

Notes
- DRC has 32 methods (largest controller); a future refinement may split per-resource controllers.

### REQ-003: ZgwService SHALL provide a shared ZGW mapping/runtime surface to all five controllers

`OCA\Procest\Service\ZgwService` SHALL centralise the cross-controller ZGW pipeline: mapping configuration loading, outbound and inbound mapping construction, query-parameter translation, pagination, ZGW error shape construction, and access to the helper services (`ZgwMappingService`, `ZgwDocumentService`, `ZgwPaginationHelper`, `NotificatieService`, `ZgwBusinessRulesService`). Controllers SHALL NOT inline mapping engine calls — every translation SHALL go through `ZgwService::loadMappingConfig()`, `createOutboundMapping()`, `createInboundMapping()`, or one of the wrapper helpers.

#### Scenario: Translate ZGW query parameter names to schema property names
- **GIVEN** an incoming request with query parameter `zaaktype=https://example.com/api/zgw/catalogi/v1/zaaktypen/abc-def`
- **WHEN** `ZgwService::translateQueryParams()` is called with the loaded mapping configuration
- **THEN** the parameter SHALL be renamed to the English schema property and the UUID SHALL be extracted from the URL

#### Scenario: Build an outbound mapping object
- **WHEN** `ZgwService::createOutboundMapping(array $mappingConfig)` is called
- **THEN** the returned mapping object SHALL be ready for `MappingService::executeMapping()` consumption against an OpenRegister `case` (or sibling) object

### REQ-004: ZGW endpoints SHALL be gated by bearer-token authentication with vertrouwelijkheid filtering

`OCA\Procest\Middleware\ZgwAuthMiddleware` SHALL run before every ZGW controller method, validate the inbound `Authorization: Bearer …` JWT, resolve the client's autorisaties (scope + max-vertrouwelijkheidaanduiding), and reject the request with the ZGW `403` error shape when the client's authorizations do not cover the requested resource. Confidentiality filtering SHALL use the ordered `VERTROUWELIJKHEID_LEVELS` table (openbaar=1 … zeer_geheim=8) such that a client whose maximum is `intern` (level 3) cannot read records flagged `zaakvertrouwelijk` (level 4) or higher.

#### Scenario: Bearer token missing or invalid
- **WHEN** a request reaches a ZGW controller without a valid `Authorization: Bearer` JWT
- **THEN** `ZgwAuthMiddleware::beforeController()` SHALL throw a `ZgwAuthException` and `afterException()` SHALL render the ZGW-shaped 401 JSON response

#### Scenario: Client requests vertrouwelijker resource than allowed
- **WHEN** `ZgwAuthMiddleware::isConfidentialityAllowed('vertrouwelijk', 'intern')` is evaluated
- **THEN** it SHALL return `false` and the surrounding filter SHALL exclude the record from the response

### REQ-005: Procest SHALL ship default ZGW mappings via a repair step on app install

`OCA\Procest\Repair\LoadDefaultZgwMappings` SHALL run as a Nextcloud repair step on app install/upgrade and create one default Mapping record per ZGW resource (ZRC zaak, status, resultaat, rol, zaakeigenschap, zaakinformatieobject, zaakobject, klantcontact; ZTC catalogus, zaaktype, statustype, resultaattype, roltype, eigenschap, besluittype, informatieobjecttype; DRC enkelvoudiginformatieobject, gebruiksrechten, objectinformatieobject; BRC besluit, besluitinformatieobject; NRC kanaal, abonnement) using `LoadDefaultZgwMappings::create…Mapping()` private methods. Existing mappings (matched by slug/reference) SHALL be left untouched — the repair step SHALL be idempotent.

#### Scenario: Fresh install seeds the default mapping set
- **WHEN** procest is installed for the first time and `LoadDefaultZgwMappings::run()` executes
- **THEN** the ZGW Mapping entities for every default resource SHALL exist in the configured register
- **AND** the seeder SHALL be safe to re-run after upgrade — pre-existing custom mappings with the same slug SHALL NOT be overwritten

Notes
- The seeder defines 36 private `create…Mapping()` methods, one per resource. Adding a new default mapping is a code-level operation; future work may move this to a JSON manifest under `openspec/`.
