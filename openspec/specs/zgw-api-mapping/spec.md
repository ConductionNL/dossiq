---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

# ZGW API Mapping

**Owned by**: Dossiq (ZGW API layer for case management)

## Purpose

@e2e exclude Pure REST API spec covered by Newman/PHPUnit; no Playwright UI surface.

Expose Dossiq's ZGW (Zaakgericht Werken) compliant API endpoints, translating case management data stored in English-language OpenRegister schemas through bidirectional property and value mapping powered by the Twig-based mapping engine. This is Dossiq's primary integration layer for Dutch government interoperability. The mapping engine -- implemented in OpenRegister as `MappingService`, `MappingExtension`, `MappingRuntime`, and the `Mapping` entity -- provides the core transformation layer; this spec defines how Dossiq wires that engine to ZGW-specific API routes, pagination, URL references, query parameter translation, error responses, and per-API compliance for all five VNG ZGW API standards (ZRC, ZTC, DRC, BRC, NRC). Dossiq owns the ZGW Mapping and Endpoint configurations, while OpenRegister owns the generic mapping infrastructure. See also Dossiq's existing ZGW controllers for reference.

## Context
Dossiq stores case management data in OpenRegister using English property names (e.g., `case`, `status`, `deadline`). Dutch municipalities require ZGW-compliant APIs with Dutch property names and values (e.g., `zaak`, `status`, `uiterlijkeEinddatumAfdoening`). Rather than maintaining dual schemas, the mapping engine translates on-the-fly -- outbound (English to Dutch) for API responses, and inbound (Dutch to English) for incoming POST/PUT/PATCH requests.

The mapping engine (Twig-based property mapping, value casting, dot-notation) already lives in OpenRegister as a core capability:
- `lib/Service/MappingService.php` -- `executeMapping()`, dot-notation via `Adbar\Dot`, SHA-256 keyed template cache, distributed cache (APCu/Redis) for Mapping entity lookups with 300s TTL
- `lib/Twig/MappingExtension.php` -- registers filters (`zgw_enum`, `zgw_enum_reverse`, `zgw_extract_uuid`, `b64enc`, `b64dec`, `json_decode`) and functions (`executeMapping`, `generateUuid`)
- `lib/Twig/MappingRuntime.php` -- runtime implementations including `zgwEnum()` for outbound enum translation, `zgwEnumReverse()` for inbound enum translation, and `zgwExtractUuid()` for URL-to-UUID extraction
- `lib/Db/Mapping.php` -- entity with fields: uuid, reference, version, name, description, mapping (json), unset (json), cast (json), passThrough (bool), configurations (json), slug, organisation
- `lib/Db/MappingMapper.php` -- find by ID/UUID/slug, multi-tenancy via `MultiTenancyTrait`, RBAC-gated CRUD, cache invalidation on write
- `lib/Db/Endpoint.php` -- configurable API endpoints with `inputMapping` and `outputMapping` references to Mapping entities, plus method, targetType, targetId, conditions, and rules

The ZGW standard defines 5 APIs:
- **ZRC - Zaken API** (Cases) -- v1.5.1
- **ZTC - Catalogi API** (Case type catalog) -- v1.3.1
- **DRC - Documenten API** (Documents) -- v1.4.3
- **BRC - Besluiten API** (Decisions) -- v1.1.0
- **NRC - Notificaties API** (Notifications) -- v1.0.0

All API endpoints are served by OpenRegister. Dossiq stores the mapping configuration and ZGW-specific metadata. The Endpoint entity's `inputMapping`/`outputMapping` fields provide the bridge between ZGW routes and Mapping entities.
## Requirements
### Requirement: Mapping engine MUST support ZGW-specific Twig filters and functions
The existing `MappingExtension` and `MappingRuntime` classes MUST provide all Twig filters and functions needed for ZGW field transformation, including enum translation (outbound and inbound), URL-to-UUID extraction, and URL construction.

#### Scenario: Outbound enum translation with zgw_enum filter
- **GIVEN** a Mapping template contains `{{ confidentiality | zgw_enum('confidentiality', valueMappings) }}`
- **AND** the value mappings include `{"confidentiality": {"public": "openbaar", "restricted": "beperkt_openbaar", "confidential": "vertrouwelijk"}}`
- **WHEN** `MappingRuntime::zgwEnum()` is called with value `"public"`, fieldName `"confidentiality"`, and the value mappings array
- **THEN** the filter MUST return `"openbaar"`
- **AND** if the value has no mapping entry, it MUST return the original value unchanged

#### Scenario: Inbound reverse enum translation with zgw_enum_reverse filter
- **GIVEN** a reverse Mapping template contains `{{ vertrouwelijkheidaanduiding | zgw_enum_reverse('confidentiality', valueMappings) }}`
- **WHEN** `MappingRuntime::zgwEnumReverse()` is called with value `"openbaar"`
- **THEN** the filter MUST flip the mapping array via `array_flip()` and return `"public"`
- **AND** if the Dutch value has no reverse mapping, it MUST return the original value unchanged

#### Scenario: UUID extraction from ZGW URL reference
- **GIVEN** a Mapping template contains `{{ zaaktype | zgw_extract_uuid }}`
- **AND** the input value is `"https://example.com/api/zgw/catalogi/v1/zaaktypen/abc-def-123"`
- **WHEN** `MappingRuntime::zgwExtractUuid()` is called
- **THEN** it MUST split on `/`, trim trailing slashes, and return `"abc-def-123"`
- **AND** if the input is `null` or empty string, it MUST return `""`

#### Scenario: Sub-mapping execution within a ZGW template
- **GIVEN** a ZGW outbound mapping needs to transform nested objects (e.g., embedded status within a zaak)
- **WHEN** the template calls `{{ executeMapping(statusMapping, statusData) }}`
- **THEN** `MappingRuntime::executeMapping()` MUST resolve the mapping by ID/UUID/slug/array via `MappingMapper::find()` and delegate to `MappingService::executeMapping()`
- **AND** the sub-mapping result MUST be embedded in the parent output

#### Scenario: UUID generation for ZGW-required fields
- **GIVEN** a ZGW mapping template needs to generate a new UUID (e.g., for a `url` field on creation)
- **WHEN** the template calls `{{ generateUuid() }}`
- **THEN** `MappingRuntime::generateUuid()` MUST return a `Symfony\Component\Uid\UuidV4` instance

### Requirement: ZGW API routes MUST be exposed via OpenRegister's Endpoint system
OpenRegister MUST register Endpoint entities for all ZGW API resources, using the Endpoint entity's `inputMapping` and `outputMapping` fields to wire inbound/outbound Mapping entities to each route.

#### Scenario: List zaken (ZRC - Zaken API)
- **GIVEN** an Endpoint entity exists with `endpoint: "/api/zgw/zaken/v1/zaken"`, `method: "GET"`, `targetType: "schema"`, `targetId` pointing to the `case` schema, and `outputMapping` referencing the zaak outbound Mapping
- **WHEN** a client calls `GET /index.php/apps/openregister/api/zgw/zaken/v1/zaken/`
- **THEN** OpenRegister MUST query the `case` schema in the configured register
- **AND** apply the outbound Mapping via `MappingService::executeMapping()` to each result object
- **AND** return ZGW-compliant JSON with Dutch property names

#### Scenario: Create zaak (ZRC - Zaken API)
- **GIVEN** the Endpoint for `POST /api/zgw/zaken/v1/zaken/` has `inputMapping` referencing the zaak inbound Mapping
- **WHEN** a client POSTs a ZGW-compliant body with Dutch property names
- **THEN** OpenRegister MUST apply the inbound Mapping (Dutch to English) before creating the object
- **AND** MUST apply the outbound Mapping to the created object in the response
- **AND** MUST return HTTP 201 with the mapped object including the generated `url` field

#### Scenario: Retrieve single zaak by UUID
- **GIVEN** the Endpoint for `GET /api/zgw/zaken/v1/zaken/{{uuid}}` has `outputMapping` configured
- **WHEN** a client calls `GET /api/zgw/zaken/v1/zaken/abc-123`
- **THEN** OpenRegister MUST find the object by UUID in the `case` schema
- **AND** apply the outbound Mapping and return the ZGW-formatted object

#### Scenario: Update zaak (partial update)
- **GIVEN** the Endpoint for `PATCH /api/zgw/zaken/v1/zaken/{{uuid}}` has both `inputMapping` and `outputMapping`
- **WHEN** a client sends a PATCH with partial Dutch properties
- **THEN** OpenRegister MUST apply the inbound Mapping only to the provided fields (passThrough=true for partial updates)
- **AND** merge with existing object data and persist
- **AND** return the fully mapped outbound object

#### Scenario: ZGW URL pattern consistency across all APIs
- **GIVEN** the ZGW standard defines paths like `/zaken/v1/zaken/{uuid}`, `/catalogi/v1/zaaktypen/{uuid}`, `/besluiten/v1/besluiten/{uuid}`
- **WHEN** OpenRegister registers Endpoint entities for ZGW
- **THEN** all routes MUST follow the pattern: `/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`
- **AND** the `endpointRegex` field MUST be auto-generated to match these paths

### Requirement: ZRC (Zaken API) resources MUST be fully mappable
The Zaken API MUST support all primary resources: Zaak, Status, Resultaat, Rol, ZaakObject, ZaakInformatieObject, ZaakEigenschap.

#### Scenario: Outbound zaak mapping (English to Dutch)
- **GIVEN** a case object in OpenRegister:
  ```json
  {
    "uuid": "abc-123",
    "caseType": "uuid-of-casetype",
    "status": "uuid-of-status",
    "deadline": "2026-06-01",
    "confidentiality": "public",
    "description": "Building permit request"
  }
  ```
- **WHEN** the outbound Mapping is applied with template:
  ```json
  {
    "mapping": {
      "url": "{{ _baseUrl }}/zaken/v1/zaken/{{ uuid }}",
      "uuid": "uuid",
      "zaaktype": "{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}",
      "status": "{{ _baseUrl }}/zaken/v1/statussen/{{ status }}",
      "uiterlijkeEinddatumAfdoening": "deadline",
      "vertrouwelijkheidaanduiding": "{{ confidentiality | zgw_enum('confidentiality', valueMappings) }}",
      "omschrijving": "description",
      "startdatum": "{{ dateCreated | date('Y-m-d') }}",
      "registratiedatum": "{{ dateCreated | date('Y-m-d') }}"
    }
  }
  ```
- **THEN** the response MUST contain:
  ```json
  {
    "url": "https://example.com/api/zgw/zaken/v1/zaken/abc-123",
    "uuid": "abc-123",
    "zaaktype": "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-of-casetype",
    "status": "https://example.com/api/zgw/zaken/v1/statussen/uuid-of-status",
    "uiterlijkeEinddatumAfdoening": "2026-06-01",
    "vertrouwelijkheidaanduiding": "openbaar",
    "omschrijving": "Building permit request",
    "startdatum": "2026-03-06",
    "registratiedatum": "2026-03-06"
  }
  ```

#### Scenario: Inbound zaak mapping (Dutch to English)
- **GIVEN** a ZGW-compliant POST body:
  ```json
  {
    "zaaktype": "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-of-casetype",
    "omschrijving": "New building permit",
    "vertrouwelijkheidaanduiding": "openbaar"
  }
  ```
- **WHEN** the inbound Mapping is applied with `zgw_extract_uuid` and `zgw_enum_reverse` filters
- **THEN** the object created in OpenRegister MUST have English properties:
  ```json
  {
    "caseType": "uuid-of-casetype",
    "description": "New building permit",
    "confidentiality": "public"
  }
  ```

#### Scenario: Status resource mapping
- **GIVEN** the ZRC defines Status as a sub-resource of Zaak with fields `url`, `uuid`, `zaak`, `statustype`, `datumStatusGezet`, `statustoelichting`
- **WHEN** a status is created via `POST /api/zgw/zaken/v1/statussen/`
- **THEN** the inbound Mapping MUST extract the zaak UUID from the `zaak` URL reference
- **AND** the outbound Mapping MUST construct the `zaak` and `statustype` as full URL references

#### Scenario: Rol resource mapping
- **GIVEN** the ZRC defines Rol with fields `url`, `uuid`, `zaak`, `betrokkene`, `betrokkeneType`, `roltype`, `omschrijving`, `omschrijvingGeneriek`, `roltoelichting`
- **WHEN** a rol is mapped outbound from the `role` schema (see cross-reference: roles-decisions spec in Dossiq)
- **THEN** the Mapping MUST translate `participant` to `betrokkene`, `roleType` to `roltype` URL reference, and `case` to `zaak` URL reference
- **AND** the `omschrijvingGeneriek` enum values MUST use `zgw_enum` (e.g., `initiator` to `initiator`, `handler` to `behandelaar`, `advisor` to `adviseur`)

### Requirement: ZTC (Catalogi API) resources MUST be fully mappable
The Catalogi API MUST support resources: ZaakType, StatusType, ResultaatType, RolType, Eigenschap, InformatieObjectType, BesluitType, and Catalogus.

#### Scenario: ZaakType outbound mapping
- **GIVEN** a caseType object with English properties: `name`, `description`, `category`, `processingDeadlineDays`, `publicationIndicator`, `confidentialityClassification`
- **WHEN** the outbound Mapping is applied
- **THEN** the response MUST include ZGW ZaakType fields: `omschrijving`, `doel`, `aanleiding`, `doorlooptijd` (ISO 8601 duration), `publicatieIndicatie`, `vertrouwelijkheidaanduiding`
- **AND** `doorlooptijd` MUST be formatted as `P{days}D` from the integer `processingDeadlineDays`

#### Scenario: BesluitType outbound mapping
- **GIVEN** the ZTC BesluitType resource requires fields: `url`, `catalogus`, `omschrijving`, `omschrijvingGeneriek`, `besluitcategorie`, `reactietermijn`, `publicatieIndicatie`, `zaaktypen`
- **WHEN** a decisionType from the Dossiq `decisionType` schema is mapped outbound (see cross-reference: besluiten-management)
- **THEN** `zaaktypen` MUST be an array of full URL references to related ZaakTypen

#### Scenario: Catalogus as container resource
- **GIVEN** the ZTC Catalogus groups all type resources under a single municipality catalog
- **WHEN** `GET /api/zgw/catalogi/v1/catalogussen/` is called
- **THEN** OpenRegister MUST return a virtual Catalogus object constructed from the register metadata (name, organisation RSIN)
- **AND** the Catalogus UUID MUST be deterministic (derived from the register UUID)

#### Scenario: Eigenschap resource mapping
- **GIVEN** the ZTC Eigenschap defines custom properties on a ZaakType
- **WHEN** property definitions from the `propertyDefinition` schema are mapped outbound
- **THEN** the Mapping MUST translate `name` to `naam`, `description` to `toelichting`, and include the `specificatie` object with `groep`, `formaat`, `lengte`, `kardinaliteit`, `waardenverzameling`

### Requirement: DRC (Documenten API) resources MUST be mappable
The Documenten API MUST support resources: EnkelvoudigInformatieObject, GebruiksRechten, ObjectInformatieObject.

#### Scenario: EnkelvoudigInformatieObject outbound mapping
- **GIVEN** a document object in OpenRegister linked to a Nextcloud file (see cross-reference: document-zaakdossier)
- **WHEN** the outbound Mapping is applied
- **THEN** the response MUST include ZGW DRC fields: `url`, `identificatie`, `bronorganisatie`, `creatiedatum`, `titel`, `vertrouwelijkheidaanduiding`, `auteur`, `status` (enum: `in_bewerking`, `ter_vaststelling`, `definitief`, `gearchiveerd`), `formaat` (MIME type), `taal` (ISO 639-2/B), `bestandsnaam`, `bestandsomvang`, `link`, `inhoud` (base64 or download URL)
- **AND** the `informatieobjecttype` field MUST be a URL reference to the ZTC InformatieObjectType

#### Scenario: Document content retrieval
- **GIVEN** a ZGW client requests `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}`
- **WHEN** the response is built
- **THEN** the `inhoud` field MUST contain either a base64-encoded file content or a download URL depending on the `Accept` header
- **AND** a `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` endpoint MUST serve the raw file bytes

#### Scenario: Document upload via DRC
- **GIVEN** a client POSTs to `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/` with `inhoud` (base64) and metadata
- **WHEN** the inbound Mapping processes the request
- **THEN** the base64 content MUST be decoded and stored as a Nextcloud file
- **AND** the metadata MUST be mapped to English properties and stored as a register object

### Requirement: BRC (Besluiten API) resources MUST be mappable
The Besluiten API MUST support resources: Besluit, BesluitInformatieObject.

#### Scenario: Besluit outbound mapping
- **GIVEN** a decision object in the `decision` schema (see cross-reference: besluiten-management) with English properties: `decisionType`, `case`, `date`, `explanation`, `effectiveDate`, `expiryDate`, `publicationDate`, `sendDate`, `appealDeadline`
- **WHEN** the outbound Mapping is applied
- **THEN** the response MUST include ZGW BRC fields: `url`, `identificatie`, `verantwoordelijkeOrganisatie`, `besluittype` (URL reference), `zaak` (URL reference), `datum`, `toelichting`, `ingangsdatum`, `vervaldatum`, `vervalreden` (enum), `publicatiedatum`, `verzenddatum`, `uiterlijkeReactiedatum`
- **AND** `vervalreden` enum MUST map: `expired` to `tijdelijk`, `withdrawn` to `ingetrokken_overheid`, `overruled` to `ingetrokken_belanghebbende`

#### Scenario: BesluitInformatieObject linking
- **GIVEN** a decision has linked documents
- **WHEN** `GET /api/zgw/besluiten/v1/besluiten/{uuid}/informatieobjecten` is called
- **THEN** the response MUST return an array of BesluitInformatieObject resources linking decisions to DRC documents via URL references

#### Scenario: Create besluit with automatic field derivation
- **GIVEN** a client POSTs a Besluit with `besluittype` URL and `datum`
- **WHEN** the inbound Mapping processes the request
- **THEN** `uiterlijkeReactiedatum` MUST be calculated from `datum` + the BesluitType's `reactietermijn` (ISO 8601 duration)
- **AND** `verantwoordelijkeOrganisatie` MUST default to the register's configured RSIN

### Requirement: NRC (Notificaties API) compatibility MUST be supported
OpenRegister MUST support outbound ZGW-compatible notifications via its existing webhook/CloudEvents infrastructure, translating OpenRegister events into NRC-compliant notification payloads.

#### Scenario: Object change triggers NRC-formatted notification
- **GIVEN** a webhook is configured with a Mapping that transforms OpenRegister events into NRC format (see cross-reference: webhook-payload-mapping)
- **WHEN** a zaak object is updated
- **THEN** the webhook payload MUST be transformable into NRC format:
  ```json
  {
    "kanaal": "zaken",
    "hoofdObject": "https://example.com/api/zgw/zaken/v1/zaken/{uuid}",
    "resource": "zaak",
    "resourceUrl": "https://example.com/api/zgw/zaken/v1/zaken/{uuid}",
    "actie": "update",
    "aanmaakdatum": "2026-03-19T10:00:00Z",
    "kenmerken": {"bronorganisatie": "123456789", "zaaktype": "https://..."}
  }
  ```

#### Scenario: NRC kanaal registration endpoint
- **GIVEN** a ZGW client wants to subscribe to notifications for the `zaken` channel
- **WHEN** `POST /api/zgw/notificaties/v1/kanalen` is called
- **THEN** OpenRegister MUST accept the registration and map it to a webhook subscription
- **AND** the kanaal MUST match OpenRegister register/schema combinations

#### Scenario: NRC abonnement (subscription) management
- **GIVEN** a ZGW client creates an abonnement via `POST /api/zgw/notificaties/v1/abonnementen`
- **WHEN** the subscription is created with `callbackUrl`, `auth`, and `kanalen` filters
- **THEN** OpenRegister MUST create a Webhook entity with the provided callback URL
- **AND** map the ZGW `kanalen` filter to OpenRegister event filters (register/schema scoping)

### Requirement: Bidirectional mapping MUST support both inbound and outbound transformations
Every ZGW resource MUST have paired Mapping entities: one for outbound (English to Dutch, used in GET responses and POST/PUT response bodies) and one for inbound (Dutch to English, used to parse incoming POST/PUT/PATCH request bodies).

#### Scenario: Outbound mapping uses Mapping entity's mapping field
- **GIVEN** an outbound Mapping entity with `mapping: {"omschrijving": "description", "zaaktype": "{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}"}`
- **WHEN** `MappingService::executeMapping()` processes a case object
- **THEN** each key in the `mapping` JSON is the output field name, and each value is either a direct dot-notation path into the input or a Twig template string
- **AND** if `passThrough` is false, only explicitly mapped fields appear in the output

#### Scenario: Inbound mapping reverses the direction
- **GIVEN** an inbound Mapping entity with `mapping: {"description": "omschrijving", "caseType": "{{ zaaktype | zgw_extract_uuid }}"}`
- **WHEN** `MappingService::executeMapping()` processes a ZGW request body
- **THEN** Dutch field names in the input are mapped to English field names in the output
- **AND** URL references are reduced to UUIDs via `zgw_extract_uuid`

#### Scenario: Cast operations ensure type correctness
- **GIVEN** an inbound Mapping has `cast: {"processingDeadlineDays": "integer", "publicationIndicator": "boolean"}`
- **WHEN** the ZGW request provides `"doorlooptijd": "P30D"` (string) and `"publicatieIndicatie": "true"` (string)
- **THEN** `MappingService::handleCast()` MUST convert the values to `30` (int) and `true` (bool) respectively
- **AND** the existing cast types (`string`, `bool`, `int`, `float`, `array`, `date`, `json`, `jsonToArray`, `nullStringToNull`) MUST all be available

#### Scenario: Unset removes internal fields from ZGW output
- **GIVEN** an outbound Mapping has `unset: ["_internal", "dateModified", "owner"]`
- **WHEN** the mapping is executed
- **THEN** `MappingService::executeMapping()` MUST call `Dot::delete()` for each key in the unset array
- **AND** the ZGW response MUST NOT contain those fields

### Requirement: Mapping configuration MUST be storable per schema via Endpoint entities
Each ZGW API resource MUST be wired to OpenRegister through an Endpoint entity that references the appropriate inbound and outbound Mapping entities.

#### Scenario: Endpoint entity wires ZGW route to schema and mappings
- **GIVEN** an Endpoint entity:
  ```json
  {
    "name": "ZGW Zaken - List/Create",
    "endpoint": "/api/zgw/zaken/v1/zaken",
    "method": "GET",
    "targetType": "schema",
    "targetId": "case-schema-uuid",
    "inputMapping": "zaak-inbound-mapping-id",
    "outputMapping": "zaak-outbound-mapping-id"
  }
  ```
- **WHEN** a request matches this endpoint's path and method
- **THEN** OpenRegister MUST resolve `targetId` to the schema, execute the query, and apply `outputMapping`

#### Scenario: Multiple endpoints per ZGW resource
- **GIVEN** a ZGW resource like Zaak needs GET (list), GET (detail), POST (create), PUT (update), PATCH (partial update), DELETE
- **WHEN** Endpoint entities are configured
- **THEN** each HTTP method MUST have its own Endpoint entity with appropriate `inputMapping`/`outputMapping` combinations
- **AND** PATCH endpoints MUST use Mapping entities with `passThrough: true` to preserve unmapped fields

#### Scenario: Endpoint conditions for filtering
- **GIVEN** an Endpoint entity has `conditions: {"register": "procest", "schema": "case"}`
- **WHEN** the endpoint is matched
- **THEN** queries MUST be scoped to only the specified register and schema
- **AND** objects from other registers/schemas MUST NOT be returned

### Requirement: VNG compliance validation MUST be enforced on ZGW responses
Outbound ZGW responses MUST comply with VNG API standards including required fields, correct data types, URL reference format, and response codes.

#### Scenario: Required fields validation on outbound response
- **GIVEN** the ZRC Zaak resource requires fields: `url`, `uuid`, `zaaktype`, `startdatum`, `bronorganisatie`, `verantwoordelijkeOrganisatie`
- **WHEN** the outbound Mapping produces a response missing `bronorganisatie`
- **THEN** the system MUST inject a default `bronorganisatie` from the register's organisation RSIN configuration
- **AND** if a required field cannot be populated, the system MUST log a warning with the missing field name

#### Scenario: ZGW error response format compliance
- **GIVEN** the VNG API standard defines error responses with fields: `type`, `code`, `title`, `status`, `detail`, `instance`
- **WHEN** an error occurs (validation failure, object not found, mapping error)
- **THEN** the ZGW endpoint MUST return errors in the VNG format:
  ```json
  {
    "type": "https://example.com/ref/fouten/ValidationError",
    "code": "invalid",
    "title": "Invalid input.",
    "status": 400,
    "detail": "zaaktype - Dit veld is vereist.",
    "instance": "urn:uuid:{request-uuid}"
  }
  ```
- **AND** validation errors MUST return HTTP 400 with field-level `invalidParams` array

#### Scenario: URL references MUST use consistent base URL
- **GIVEN** all ZGW URL references must point to the same server
- **WHEN** the outbound Mapping constructs URLs using `{{ _baseUrl }}`
- **THEN** `_baseUrl` MUST be derived from the incoming request's scheme + host + Nextcloud base path + `/apps/openregister/api/zgw`
- **AND** all URL references in a single response MUST use the same base URL

### Requirement: ZGW URL references MUST be bidirectionally translatable
ZGW requires that related resources are referenced by full URLs, not UUIDs. The mapping engine MUST handle both directions.

#### Scenario: Construct URL reference in outbound mapping
- **GIVEN** a case object with `caseType: "uuid-123"`
- **WHEN** the outbound Mapping template `{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}` is rendered
- **THEN** `zaaktype` MUST become `"https://{host}/index.php/apps/openregister/api/zgw/catalogi/v1/zaaktypen/uuid-123"`

#### Scenario: Parse URL reference in inbound mapping
- **GIVEN** a POST body with `zaaktype: "https://example.com/api/zgw/catalogi/v1/zaaktypen/uuid-123"`
- **WHEN** the inbound Mapping applies `{{ zaaktype | zgw_extract_uuid }}`
- **THEN** the system MUST extract `"uuid-123"` and store it as `caseType`
- **AND** `zgw_extract_uuid` MUST handle URLs with or without trailing slashes

#### Scenario: Cross-API URL references
- **GIVEN** a Zaak references a StatusType which belongs to the Catalogi API (different API prefix)
- **WHEN** the outbound Mapping constructs the URL
- **THEN** the URL MUST use the correct API prefix: `/api/zgw/catalogi/v1/statustypen/{uuid}` (not `/api/zgw/zaken/v1/...`)

### Requirement: ZGW pagination MUST follow HAL-style format
ZGW APIs use a specific pagination format that differs from OpenRegister's default pagination. The mapping engine MUST transform OpenRegister's pagination response into the ZGW HAL-style format.

#### Scenario: Paginated zaak list response
- **GIVEN** 50 cases in the register and default page size of 20
- **WHEN** `GET /api/zgw/zaken/v1/zaken/?page=2` is called
- **THEN** the response MUST follow ZGW pagination format:
  ```json
  {
    "count": 50,
    "next": "https://example.com/api/zgw/zaken/v1/zaken/?page=3",
    "previous": "https://example.com/api/zgw/zaken/v1/zaken/?page=1",
    "results": [...]
  }
  ```
- **AND** `count` MUST be the total number of objects matching the query (not the page size)
- **AND** `next` MUST be `null` on the last page, `previous` MUST be `null` on the first page

#### Scenario: Custom page size via query parameter
- **GIVEN** the ZGW API supports `pageSize` query parameter
- **WHEN** `GET /api/zgw/zaken/v1/zaken/?page=1&pageSize=50` is called
- **THEN** the response MUST return up to 50 results per page
- **AND** the `next`/`previous` URLs MUST include the `pageSize` parameter

#### Scenario: Empty result set
- **GIVEN** no objects match the query filters
- **WHEN** the paginated response is built
- **THEN** `count` MUST be `0`, `results` MUST be `[]`, `next` MUST be `null`, `previous` MUST be `null`

### Requirement: ZGW query parameter mapping MUST translate filter names and extract UUIDs from URL values
ZGW filter parameters use Dutch names and URL references as values; these MUST be translated to OpenRegister's English filter names with UUID values.

#### Scenario: Filter zaken by zaaktype URL
- **GIVEN** a ZGW client calls `GET /api/zgw/zaken/v1/zaken/?zaaktype=https://example.com/.../zaaktypen/uuid-123`
- **WHEN** the query parameter mapping resolves `zaaktype` to `caseType`
- **THEN** OpenRegister MUST extract `uuid-123` from the URL value and filter by `caseType=uuid-123`

#### Scenario: Filter by date range with ZGW-style operators
- **GIVEN** a ZGW client calls `GET /api/zgw/zaken/v1/zaken/?startdatum__gte=2026-01-01&startdatum__lte=2026-12-31`
- **WHEN** the query parameter mapping resolves `startdatum` to `dateCreated`
- **THEN** OpenRegister MUST filter by `dateCreated >= 2026-01-01` AND `dateCreated <= 2026-12-31`
- **AND** the `__gte`, `__lte`, `__gt`, `__lt`, `__in`, `__icontains` suffixes MUST be supported

#### Scenario: Filter by bronorganisatie
- **GIVEN** the ZGW standard requires filtering by `bronorganisatie` (RSIN of the source organisation)
- **WHEN** `GET /api/zgw/zaken/v1/zaken/?bronorganisatie=123456789` is called
- **THEN** the query parameter MUST map to the register's organisation filter

#### Scenario: Multiple filter parameters combined
- **GIVEN** a request with `?zaaktype=...&status=...&startdatum__gte=2026-01-01`
- **WHEN** all parameters are mapped
- **THEN** OpenRegister MUST apply all filters as AND conditions

### Requirement: ZGW resource mapping table MUST define the complete translation between ZGW and OpenRegister schemas
Every ZGW resource type MUST have a defined mapping to a Dossiq/OpenRegister schema.

#### Scenario: Complete resource mapping table
- **GIVEN** the following mapping table:

| ZGW Resource | ZGW API (Standard) | Dossiq Schema | OpenRegister Schema | Endpoint Pattern |
|---|---|---|---|---|
| Zaak | ZRC (Zaken) | case | case | `/api/zgw/zaken/v1/zaken/{uuid?}` |
| Status | ZRC (Zaken) | (inline on case) | status on case | `/api/zgw/zaken/v1/statussen/{uuid?}` |
| Resultaat | ZRC (Zaken) | result | result | `/api/zgw/zaken/v1/resultaten/{uuid?}` |
| Rol | ZRC (Zaken) | role | role | `/api/zgw/zaken/v1/rollen/{uuid?}` |
| ZaakObject | ZRC (Zaken) | caseObject | caseObject | `/api/zgw/zaken/v1/zaakobjecten/{uuid?}` |
| ZaakInformatieObject | ZRC (Zaken) | caseDocument | caseDocument | `/api/zgw/zaken/v1/zaakinformatieobjecten/{uuid?}` |
| ZaakEigenschap | ZRC (Zaken) | caseProperty | caseProperty | `/api/zgw/zaken/v1/zaakeigenschappen/{zaak_uuid}/{uuid?}` |
| ZaakType | ZTC (Catalogi) | caseType | caseType | `/api/zgw/catalogi/v1/zaaktypen/{uuid?}` |
| StatusType | ZTC (Catalogi) | statusType | statusType | `/api/zgw/catalogi/v1/statustypen/{uuid?}` |
| ResultaatType | ZTC (Catalogi) | resultType | resultType | `/api/zgw/catalogi/v1/resultaattypen/{uuid?}` |
| RolType | ZTC (Catalogi) | roleType | roleType | `/api/zgw/catalogi/v1/roltypen/{uuid?}` |
| Eigenschap | ZTC (Catalogi) | propertyDefinition | propertyDefinition | `/api/zgw/catalogi/v1/eigenschappen/{uuid?}` |
| InformatieObjectType | ZTC (Catalogi) | documentType | documentType | `/api/zgw/catalogi/v1/informatieobjecttypen/{uuid?}` |
| BesluitType | ZTC (Catalogi) | decisionType | decisionType | `/api/zgw/catalogi/v1/besluittypen/{uuid?}` |
| Catalogus | ZTC (Catalogi) | (virtual) | (derived from register) | `/api/zgw/catalogi/v1/catalogussen/{uuid?}` |
| Besluit | BRC (Besluiten) | decision | decision | `/api/zgw/besluiten/v1/besluiten/{uuid?}` |
| BesluitInformatieObject | BRC (Besluiten) | decisionDocument | decisionDocument | `/api/zgw/besluiten/v1/besluitinformatieobjecten/{uuid?}` |
| EnkelvoudigInformatieObject | DRC (Documenten) | document | document | `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid?}` |
| GebruiksRechten | DRC (Documenten) | usageRights | usageRights | `/api/zgw/documenten/v1/gebruiksrechten/{uuid?}` |

- **WHEN** ZGW endpoints are registered
- **THEN** each resource MUST have at minimum a GET (list), GET (detail), and POST endpoint
- **AND** ZRC and BRC resources MUST also support PUT, PATCH, and DELETE

### Requirement: Mapping versioning MUST be supported
Mapping entities MUST support semantic versioning to track changes and enable rollback.

#### Scenario: Auto-increment version on mapping update
- **GIVEN** a Mapping entity with `version: "1.0.3"`
- **WHEN** the mapping is updated via `MappingMapper::updateFromArray()`
- **THEN** if no explicit version is provided, the patch version MUST auto-increment to `"1.0.4"`
- **AND** the `updated` timestamp MUST be set to the current time

#### Scenario: Explicit version bump for breaking changes
- **GIVEN** an admin changes a ZGW mapping's field structure (adding/removing mapped fields)
- **WHEN** they provide `version: "2.0.0"` in the update request
- **THEN** the Mapping MUST store the explicit version
- **AND** the mapping `reference` field MAY be used to link to a changelog or VNG API version

#### Scenario: Multiple mapping versions for API version negotiation
- **GIVEN** the ZGW standard releases a new version (e.g., Zaken API v2.0.0)
- **WHEN** OpenRegister needs to support both v1 and v2 simultaneously
- **THEN** separate Mapping entities MUST exist for each version
- **AND** Endpoint entities MUST route `/api/zgw/zaken/v1/...` and `/api/zgw/zaken/v2/...` to different Mapping entities

### Requirement: Mapping testing and preview MUST be available
Administrators MUST be able to test ZGW mappings with sample data before deploying them.

#### Scenario: Test mapping via the existing test endpoint
- **GIVEN** the `MappingsController::test()` endpoint accepts `inputObject` and `mapping` parameters
- **WHEN** an admin POSTs to `/api/mappings/test` with a sample case object and a ZGW outbound mapping configuration
- **THEN** the endpoint MUST return `{"resultObject": {...}, "success": true}` with the mapped output
- **AND** if the mapping fails (e.g., undefined variable in Twig template), it MUST return HTTP 400 with `{"error": "Mapping error", "message": "..."}`

#### Scenario: Preview inbound mapping with sample ZGW payload
- **GIVEN** an admin provides a sample ZGW POST body and an inbound mapping configuration
- **WHEN** the test endpoint processes the request
- **THEN** the result MUST show how the Dutch properties would be translated to English
- **AND** URL references MUST be shown as extracted UUIDs

#### Scenario: Dry-run mapping against live data
- **GIVEN** an admin wants to verify a mapping against actual register objects
- **WHEN** they provide a mapping ID and an object UUID to the test endpoint
- **THEN** the system MUST fetch the real object, apply the mapping, and return the result without modifying any data

### Requirement: Mapping performance MUST leverage caching for compiled templates
The mapping engine MUST cache compiled Twig templates and Mapping entity lookups to minimize overhead on high-throughput ZGW API calls.

#### Scenario: In-memory template cache prevents recompilation
- **GIVEN** a ZGW list endpoint returns 100 zaak objects, each requiring the same outbound Mapping
- **WHEN** `MappingService::executeMapping()` processes each object
- **THEN** the Twig template MUST be compiled only once (cached by SHA-256 hash in `$templateCache`)
- **AND** subsequent objects MUST use `getCachedTemplate()` to retrieve the pre-compiled `TemplateWrapper`

#### Scenario: Distributed cache for Mapping entity lookups
- **GIVEN** a ZGW endpoint references a Mapping by ID
- **WHEN** `MappingService::getMapping()` is called
- **THEN** the distributed cache (APCu/Redis via `ICacheFactory::createDistributed()`) MUST be checked first
- **AND** on cache miss, the Mapping MUST be fetched from the database and stored in cache with TTL of 300 seconds
- **AND** on write operations (`createFromArray`, `updateFromArray`, `delete`), `MappingMapper::invalidateCache()` MUST clear entries keyed by ID, UUID, and slug

#### Scenario: Dot-notation encoding preserves keys with periods
- **GIVEN** ZGW field names or values may contain periods (e.g., version numbers)
- **WHEN** `MappingService::encodeArrayKeys()` processes the input before mapping
- **THEN** periods in keys MUST be encoded as `&#46;` to prevent `Adbar\Dot` from interpreting them as path separators
- **AND** after mapping, `encodeArrayKeys()` MUST decode `&#46;` back to `.` in the output

### Requirement: Error handling MUST provide clear diagnostics for failed mappings
When a mapping fails (missing fields, Twig errors, type mismatches), the system MUST provide actionable error information.

#### Scenario: Missing input field in Twig template
- **GIVEN** a Mapping template references `{{ nonExistentField }}` but the input object does not contain that field
- **WHEN** `MappingService::executeMapping()` renders the template via `getCachedTemplate()->render()`
- **THEN** the Twig `strict_variables` behavior MUST determine whether this is an error or empty string
- **AND** the exception MUST include the mapping name, the key being mapped, and the template expression

#### Scenario: Mapping error includes diagnostic context
- **GIVEN** a Mapping named "ZGW Zaak Outbound v1" fails on key `zaaktype` with template `{{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }}`
- **WHEN** the exception is thrown
- **THEN** the error message MUST follow the format: `"Error for mapping: ZGW Zaak Outbound v1, key: zaaktype, value: {{ _baseUrl }}/catalogi/v1/zaaktypen/{{ caseType }} and message: {twig error}"`

#### Scenario: Graceful degradation on partial mapping failure
- **GIVEN** a ZGW list response is being built and mapping fails for one object out of 50
- **WHEN** the list endpoint processes the batch
- **THEN** the system SHOULD log the error, skip the failed object, and include a `_warnings` array in the response metadata (outside the `results` array)
- **AND** the response MUST still return HTTP 200 with the successfully mapped objects

### Requirement: HAL/JSON format compliance MUST be enforced
ZGW API responses MUST comply with the HAL (Hypertext Application Language) JSON format conventions used by VNG standards.

#### Scenario: Response Content-Type header
- **GIVEN** a ZGW API endpoint returns a response
- **WHEN** the response is sent
- **THEN** the `Content-Type` header MUST be `application/json` (ZGW does not use `application/hal+json`)
- **AND** the response body MUST be valid JSON

#### Scenario: Coordinate Reference System headers
- **GIVEN** the ZGW Zaken API requires CRS headers for geospatial data
- **WHEN** a request includes `Accept-Crs: EPSG:4326` or a response contains geometry fields
- **THEN** the response MUST include `Content-Crs: EPSG:4326` header
- **AND** if the requested CRS is not supported, the response MUST return HTTP 406 Not Acceptable

#### Scenario: ZGW API version header
- **GIVEN** the ZGW standard defines an `API-version` response header
- **WHEN** any ZGW endpoint returns a response
- **THEN** the response MUST include `API-version: 1.x.x` matching the supported ZGW API version for that specific API (e.g., `1.5.1` for ZRC, `1.3.1` for ZTC)

### Requirement: Default ZGW mappings MUST be shipped with Dossiq
Dossiq MUST provide default Mapping and Endpoint entities for all ZGW resources so that ZGW APIs work out of the box after installation.

#### Scenario: Fresh Dossiq installation
- **GIVEN** Dossiq is installed and its schemas are initialized via ConfigurationService
- **WHEN** the default configuration JSON is loaded
- **THEN** all ZGW resources in the mapping table MUST have pre-configured Mapping entities (both inbound and outbound)
- **AND** all ZGW Endpoint entities MUST be created with correct `inputMapping`/`outputMapping` references
- **AND** the ZGW API endpoints MUST be immediately functional without manual configuration

#### Scenario: Admin customizes default mapping
- **GIVEN** a municipality uses a custom `case` schema with additional fields not in the default mapping
- **WHEN** the admin edits the ZGW Zaak outbound Mapping via the OpenRegister Mappings API
- **THEN** the custom mapping MUST take effect immediately (cache invalidation via `MappingMapper::invalidateCache()`)
- **AND** the default mapping MUST be restorable by re-importing from the Dossiq configuration JSON

#### Scenario: Mapping administration in OpenRegister
- **GIVEN** all Mapping entities are stored in OpenRegister's `openregister_mappings` table
- **WHEN** an admin navigates to the OpenRegister admin panel
- **THEN** they MUST be able to view, edit, test, and delete ZGW Mapping entities
- **AND** each Mapping MUST show its name, slug, version, associated configurations, and linked Endpoints

### Requirement: Generic mapping capability MUST be reusable for non-ZGW API profiles
The ZGW mapping layer MUST be built on OpenRegister's generic Mapping + Endpoint infrastructure, making it reusable for other API standards.

#### Scenario: FHIR API profile using same infrastructure
- **GIVEN** a healthcare project needs to expose FHIR-compliant endpoints on top of English OpenRegister data
- **WHEN** FHIR Mapping and Endpoint entities are configured
- **THEN** the same `MappingService::executeMapping()`, `MappingExtension`, and `MappingRuntime` MUST handle the transformation
- **AND** FHIR-specific Twig filters could be added to `MappingExtension` alongside the `zgw_*` filters

#### Scenario: StUF-ZKN mapping profile
- **GIVEN** some municipalities still require StUF-ZKN XML format (see cross-reference: stuf-support in Dossiq)
- **WHEN** StUF-specific Mapping entities are configured with XML output templates
- **THEN** the Twig-based mapping engine MUST be capable of producing XML output via Twig's template rendering
- **AND** Endpoint entities MUST support `Content-Type: application/xml` responses

#### Scenario: ZGW is one API profile among many
- **GIVEN** the mapping infrastructure supports multiple API profiles
- **WHEN** listing all configured API profiles
- **THEN** ZGW endpoints MUST be identifiable by their `/api/zgw/` path prefix
- **AND** other profiles MUST use different prefixes (e.g., `/api/fhir/`, `/api/stuf/`)

<!-- BEGIN retrofit-2026-05-24-zgw-api-mapping -->

### REQ-001: Dossiq SHALL expose ZGW Zaakregistratiecomponent (ZRC) endpoints via ZrcController

`OCA\Dossiq\Controller\ZrcController` SHALL serve the ZGW Zaken API resources (zaken, statussen, resultaten, rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten) using the shared `ZgwService` for inbound/outbound translation against English-language OpenRegister schemas. The controller SHALL handle list/show/create/update/patch/destroy plus the nested `zaakeigenschappen*` and `zaakbesluiten` sub-resources, expose `/zoek` search, and serve audit-trail lookup endpoints.

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

### REQ-002: Dossiq SHALL expose ZTC, DRC, BRC and NRC endpoints via dedicated controllers

`OCA\Dossiq\Controller\ZtcController` (Catalogi API), `DrcController` (Documenten API), `BrcController` (Besluiten API) and `NrcController` (Notificaties API) SHALL each serve the canonical ZGW resources for their API per the VNG ZGW standard versions tracked in this spec (ZTC v1.3.1, DRC v1.4.3, BRC v1.1.0, NRC v1.0.0). Each controller SHALL reuse `ZgwService` for shared mapping execution, pagination, and ZGW response shaping while implementing API-specific resource paths.

#### Scenario: List catalogi via ZTC
- **WHEN** a client calls `GET /api/zgw/catalogi/v1/catalogussen/`
- **THEN** `ZtcController::index('catalogussen')` SHALL return ZGW-compliant catalog objects via the catalogi outbound Mapping

#### Scenario: Send notificatie via NRC
- **WHEN** an event triggers a notificatie dispatch through `NotificatieService`
- **THEN** the configured NRC subscriber URLs SHALL be POSTed the ZGW-shaped notification payload

Notes
- DRC has 32 methods (largest controller); a future refinement may split per-resource controllers.

### REQ-003: ZgwService SHALL provide a shared ZGW mapping/runtime surface to all five controllers

`OCA\Dossiq\Service\ZgwService` SHALL centralise the cross-controller ZGW pipeline: mapping configuration loading, outbound and inbound mapping construction, query-parameter translation, pagination, ZGW error shape construction, and access to the helper services (`ZgwMappingService`, `ZgwDocumentService`, `ZgwPaginationHelper`, `NotificatieService`, `ZgwBusinessRulesService`). Controllers SHALL NOT inline mapping engine calls — every translation SHALL go through `ZgwService::loadMappingConfig()`, `createOutboundMapping()`, `createInboundMapping()`, or one of the wrapper helpers.

#### Scenario: Translate ZGW query parameter names to schema property names
- **GIVEN** an incoming request with query parameter `zaaktype=https://example.com/api/zgw/catalogi/v1/zaaktypen/abc-def`
- **WHEN** `ZgwService::translateQueryParams()` is called with the loaded mapping configuration
- **THEN** the parameter SHALL be renamed to the English schema property and the UUID SHALL be extracted from the URL

#### Scenario: Build an outbound mapping object
- **WHEN** `ZgwService::createOutboundMapping(array $mappingConfig)` is called
- **THEN** the returned mapping object SHALL be ready for `MappingService::executeMapping()` consumption against an OpenRegister `case` (or sibling) object

### REQ-004: ZGW endpoints SHALL be gated by bearer-token authentication with vertrouwelijkheid filtering

`OCA\Dossiq\Middleware\ZgwAuthMiddleware` SHALL run before every ZGW controller method, validate the inbound `Authorization: Bearer …` JWT, resolve the client's autorisaties (scope + max-vertrouwelijkheidaanduiding), and reject the request with the ZGW `403` error shape when the client's authorizations do not cover the requested resource. Confidentiality filtering SHALL use the ordered `VERTROUWELIJKHEID_LEVELS` table (openbaar=1 … zeer_geheim=8) such that a client whose maximum is `intern` (level 3) cannot read records flagged `zaakvertrouwelijk` (level 4) or higher.

#### Scenario: Bearer token missing or invalid
- **WHEN** a request reaches a ZGW controller without a valid `Authorization: Bearer` JWT
- **THEN** `ZgwAuthMiddleware::beforeController()` SHALL throw a `ZgwAuthException` and `afterException()` SHALL render the ZGW-shaped 401 JSON response

#### Scenario: Client requests vertrouwelijker resource than allowed
- **WHEN** `ZgwAuthMiddleware::isConfidentialityAllowed('vertrouwelijk', 'intern')` is evaluated
- **THEN** it SHALL return `false` and the surrounding filter SHALL exclude the record from the response

### REQ-005: Dossiq SHALL ship default ZGW mappings via a repair step on app install

`OCA\Dossiq\Repair\LoadDefaultZgwMappings` SHALL run as a Nextcloud repair step on app install/upgrade and create one default Mapping record per ZGW resource (ZRC zaak, status, resultaat, rol, zaakeigenschap, zaakinformatieobject, zaakobject, klantcontact; ZTC catalogus, zaaktype, statustype, resultaattype, roltype, eigenschap, besluittype, informatieobjecttype; DRC enkelvoudiginformatieobject, gebruiksrechten, objectinformatieobject; BRC besluit, besluitinformatieobject; NRC kanaal, abonnement) using `LoadDefaultZgwMappings::create…Mapping()` private methods. Existing mappings (matched by slug/reference) SHALL be left untouched — the repair step SHALL be idempotent.

#### Scenario: Fresh install seeds the default mapping set
- **WHEN** dossiq is installed for the first time and `LoadDefaultZgwMappings::run()` executes
- **THEN** the ZGW Mapping entities for every default resource SHALL exist in the configured register
- **AND** the seeder SHALL be safe to re-run after upgrade — pre-existing custom mappings with the same slug SHALL NOT be overwritten

Notes
- The seeder defines 36 private `create…Mapping()` methods, one per resource. Adding a new default mapping is a code-level operation; future work may move this to a JSON manifest under `openspec/`.

<!-- END retrofit-2026-05-24-zgw-api-mapping -->

### Requirement: ZRC Zaak resource MUST map relevanteAndereZaken bidirectionally

The ZGW mapping layer SHALL translate `case.relatedCases` to the ZRC Zaak field `relevanteAndereZaken` as an array of `{url, aardRelatie}` objects (outbound), and SHALL accept `relevanteAndereZaken` on inbound zaak create/update by resolving each `url` to a local case UUID and routing the result through the case-relation guards, per the existing URL-reference translation and error-diagnostic requirements of this capability.

@e2e exclude ZGW API-contract requirement — proven by the Newman collection tests/newman/relevante-andere-zaken.postman_collection.json (outbound array shape, inbound resolve+guard, unresolvable-URL rejection, empty-array); no Playwright UI surface (ZGW is a machine-to-machine API).

#### Scenario: Outbound zaak includes relevanteAndereZaken

- **GIVEN** a case whose `relatedCases` contains `{caseId: <uuid-B>, aardRelatie: onderwerp}`
- **WHEN** a ZGW consumer retrieves the zaak via `GET /api/zgw/zaken/v1/zaken/{uuid}`
- **THEN** the response MUST contain `relevanteAndereZaken: [{url: <absolute zaak URL for uuid-B>, aardRelatie: "onderwerp"}]`
- **AND** the dossiq-local `toelichting` MUST NOT appear in the ZGW shape

#### Scenario: Inbound relevanteAndereZaken is resolved and guarded

- **GIVEN** an authenticated ZGW client PATCHes a zaak with `relevanteAndereZaken: [{url: <zaak URL of case B>, aardRelatie: "vervolg"}]`
- **WHEN** the mapping layer processes the request
- **THEN** the URL MUST be resolved to case B's local UUID and the relation stored on both cases per the bidirectional-consistency requirement of `related-case-linking`

#### Scenario: Unresolvable relation URL is rejected with diagnostics

- **WHEN** an inbound zaak write references a `relevanteAndereZaken` URL that does not resolve to a local case
- **THEN** the request MUST be rejected with the capability's standard ZGW validation error shape identifying the offending URL

#### Scenario: Empty relations map to an empty array

- **GIVEN** a case with no peer relations
- **WHEN** the zaak is retrieved via the ZRC endpoint
- **THEN** `relevanteAndereZaken` MUST be present as `[]` (VNG schema compliance), not omitted or null

## Non-Requirements
- Full ZGW compliance certification -- this is a compatibility layer, not a VNG reference implementation
- Autorisaties API (AC) -- authorization and scopes are handled by Nextcloud's auth system and OpenRegister's RBAC
- ZGW-to-ZGW synchronization with external OpenZaak instances -- separate concern for data-sync-harvesting spec
- Twig sandbox/security policies -- the mapping engine trusts mapping templates authored by administrators
- Audit trail API resource (`/api/zgw/zaken/v1/audittrail/{uuid}`) -- covered by separate audit-trail-immutable spec

## Dependencies
- **OpenRegister MappingService** (`lib/Service/MappingService.php`) -- Twig-based mapping engine with `executeMapping()`, template caching, distributed entity caching
- **OpenRegister MappingExtension** (`lib/Twig/MappingExtension.php`) -- registers `zgw_enum`, `zgw_enum_reverse`, `zgw_extract_uuid` filters and `executeMapping`, `generateUuid` functions
- **OpenRegister MappingRuntime** (`lib/Twig/MappingRuntime.php`) -- runtime implementations for all ZGW-specific Twig filters
- **OpenRegister Mapping entity** (`lib/Db/Mapping.php`) -- entity with mapping, unset, cast, passThrough, version, slug fields
- **OpenRegister MappingMapper** (`lib/Db/MappingMapper.php`) -- CRUD with multi-tenancy, RBAC, cache invalidation
- **OpenRegister Endpoint entity** (`lib/Db/Endpoint.php`) -- `inputMapping`/`outputMapping` references, endpoint path matching with regex
- **OpenRegister MappingsController** (`lib/Controller/MappingsController.php`) -- CRUD API plus `test()` endpoint for mapping preview
- **Dossiq schemas** -- 12+ ZGW-mapped schemas (case, caseType, statusType, resultType, roleType, role, result, decision, decisionType, documentType, propertyDefinition, etc.)
- **Dossiq configuration JSON** -- default Mapping and Endpoint definitions shipped with Dossiq

## Cross-References
- **besluiten-management** (`openregister/openspec/specs/besluiten-management/spec.md`) -- BRC Besluiten API data model, decision types, appeal period tracking
- **document-zaakdossier** (`openregister/openspec/specs/document-zaakdossier/spec.md`) -- DRC Documenten API integration, file storage in Nextcloud Files
- **webhook-payload-mapping** (`openregister/openspec/specs/webhook-payload-mapping/spec.md`) -- NRC-compatible notifications via webhook Mapping transformation
- **roles-decisions** (`dossiq/openspec/specs/roles-decisions/spec.md`) -- ZRC Rol and Resultaat data models, generic role enum mapping
- **notificatie-engine** (`openregister/openspec/specs/notificatie-engine/spec.md`) -- VNG Notificaties API compliance layer
- **openapi-generation** (`openregister/openspec/specs/openapi-generation/spec.md`) -- auto-generated OpenAPI specs for ZGW endpoints

## Current Implementation Status

**Partially implemented.** The mapping engine with ZGW-specific filters is in OpenRegister, but ZGW API routes and Endpoint wiring are not:

**Implemented (mapping engine in OpenRegister):**
- `lib/Service/MappingService.php` -- Twig-based mapping engine with `executeMapping()`, dot-notation via `Adbar\Dot`, SHA-256 keyed template cache, distributed entity cache (APCu/Redis, 300s TTL), type casting (string, bool, int, float, array, date, url, base64, json, jsonToArray, utf8, nullStringToNull, coordinateStringToArray, keyCantBeValue, unsetIfValue, setNullIfValue, countValue, moneyStringToInt, intToMoneyString)
- `lib/Twig/MappingExtension.php` -- Twig extension registering 6 filters (`b64enc`, `b64dec`, `json_decode`, `zgw_enum`, `zgw_enum_reverse`, `zgw_extract_uuid`) and 2 functions (`executeMapping`, `generateUuid`)
- `lib/Twig/MappingRuntime.php` -- Runtime implementations: `zgwEnum()` for outbound value translation, `zgwEnumReverse()` via `array_flip()` for inbound, `zgwExtractUuid()` for URL-to-UUID, plus `executeMapping()` supporting Mapping objects, arrays, ID/UUID/slug/URL references, and `generateUuid()` via Symfony UuidV4
- `lib/Twig/MappingRuntimeLoader.php` -- Lazy loader implementing `RuntimeLoaderInterface`
- `lib/Db/Mapping.php` -- Entity with 14 fields, JSON serialization, slug auto-generation with ICU transliteration fallback, `hydrate()` method
- `lib/Db/MappingMapper.php` -- Multi-tenancy (`MultiTenancyTrait`), RBAC-gated CRUD, find by ID/UUID/slug, find by reference, find by configuration, distributed cache invalidation on create/update/delete, auto-increment patch version
- `lib/Controller/MappingsController.php` -- REST API with `index()`, `show()`, `create()`, `update()`, `destroy()`, and `test()` endpoints
- `lib/Db/Endpoint.php` -- Entity with `inputMapping`, `outputMapping`, `endpointRegex`, `conditions`, `rules`, `targetType`, `targetId`
- `lib/Service/Object/SaveObject/ComputedFieldHandler.php` -- allows `zgw_enum`, `zgw_enum_reverse`, `zgw_extract_uuid` in computed field expressions

**Not implemented:**
- ZGW API route registration (Endpoint entities for `/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`)
- ZGW pagination format (HAL-style `count`, `next`, `previous`, `results` wrapper)
- ZGW query parameter mapping (Dutch filter names to English, URL value extraction)
- ZGW error response format (VNG-standard `type`, `code`, `title`, `status`, `detail`, `instance`)
- ZGW response headers (`API-version`, `Content-Crs`)
- Default ZGW Mapping entities shipped with Dossiq configuration JSON
- Default ZGW Endpoint entities shipped with Dossiq configuration JSON
- Virtual Catalogus resource derived from register metadata
- NRC (Notificaties API) kanaal and abonnement endpoints
- DRC document content download endpoint and base64 content handling

## Standards & References
- VNG ZGW API Standards (https://vng-realisatie.github.io/gemma-zaken/)
  - ZRC - Zaken API v1.5.1 (https://zaken-api.vng.cloud/api/v1/schema/)
  - ZTC - Catalogi API v1.3.1 (https://catalogi-api.vng.cloud/api/v1/schema/)
  - BRC - Besluiten API v1.1.0 (https://besluiten-api.vng.cloud/api/v1/schema/)
  - DRC - Documenten API v1.4.3 (https://documenten-api.vng.cloud/api/v1/schema/)
  - NRC - Notificaties API v1.0.0 (https://notificaties-api.vng.cloud/api/v1/schema/)
- GEMMA 2.0 reference architecture (VNG)
- NL GOV API Design Rules (https://publicatie.centrumvoorstandaarden.nl/api/adr/)
- HAL (Hypertext Application Language) -- JSON pagination format used by ZGW
- Twig Template Engine (https://twig.symfony.com/)
- Adbar Dot Notation (https://github.com/adbario/php-dot-notation) -- nested array access in MappingService
- ISO 8601 Duration format (e.g., P30D, P42D) -- used for doorlooptijd, reactietermijn
- ISO 639-2/B language codes -- used for DRC `taal` field
- EPSG:4326 Coordinate Reference System -- geospatial data in ZRC

## Specificity Assessment
- **Specific enough to implement?** Yes -- the mapping table, Endpoint wiring pattern, Twig filter implementations, caching strategy, and property mapping examples are concrete and actionable.
- **Missing/ambiguous:**
  - No specification for ZGW version negotiation (what if client requests v2 but only v1 is mapped?)
  - No specification for ZGW audit trail format (audittrail resource in Zaken API -- deferred to audit-trail-immutable spec)
  - No specification for ZGW expand/include query parameters (embedding related resources)
  - No specification for authentication on ZGW endpoints (JWT tokens per ZGW standard vs Nextcloud auth -- decision needed)
  - No specification for ZGW `_zoek` POST-based search endpoints
- **Open questions:**
  - Should ZGW endpoints require ZGW-standard JWT authentication or use Nextcloud's existing auth?
  - How should the Autorisaties API be handled (spec says out of scope but some clients may expect a minimal implementation)?
  - Should ZGW compliance be validated against VNG API test platform (api-test.nl)?
  - How does the virtual Catalogus entity interact with multi-tenant deployments (one Catalogus per organisation)?

## Nextcloud Integration Analysis

**Status**: Partially implemented. The mapping engine with ZGW-specific Twig filters exists in OpenRegister. ZGW API routes, pagination, and query parameter mapping are not yet built.

**Nextcloud Core Interfaces**:
- `IRegistration` / `routes.php`: Register a dedicated ZGW route group (`/api/zgw/{zgwApi}/v1/{resource}/{uuid?}`) as a separate controller prefix in `appinfo/routes.php`, keeping ZGW endpoints isolated from the standard OpenRegister REST API.
- `ICapability`: Expose ZGW endpoint availability and supported API versions via `ICapability` so that external ZGW clients can discover which APIs are active through Nextcloud's capabilities endpoint (`/ocs/v2.php/cloud/capabilities`).
- `IRequest`: Use Nextcloud's request object for content negotiation and ZGW-specific headers (e.g., `Accept-Crs`, `Content-Crs` for coordinate reference systems required by some ZGW APIs).
- `ICacheFactory`: Already used by `MappingService` and `MappingMapper` for distributed caching of Mapping entities.

**Implementation Approach**:
- Create a `ZgwController` (or per-API controllers: `ZgwZakenController`, `ZgwCatalogiController`, etc.) registered as a separate route group in `routes.php`. Each controller delegates to `MappingService` for property translation between English schema properties and Dutch ZGW field names.
- Leverage existing Endpoint entities with `inputMapping`/`outputMapping` references to wire each ZGW route to the correct Mapping entities. The Endpoint's `targetType`/`targetId` fields identify which register+schema to query.
- The existing `MappingExtension` already registers `zgw_enum`, `zgw_enum_reverse`, and `zgw_extract_uuid` filters -- no new Twig extension needed.
- Implement a `ZgwPaginationHelper` that reformats OpenRegister's standard pagination into ZGW HAL-style format (`count`, `next`, `previous`, `results`).
- ZGW query parameters (e.g., `zaaktype` URL references) are parsed in controller-level logic using `zgwExtractUuid()` to extract UUIDs from full URLs before passing to `ObjectService` filters.

**Dependencies on Existing OpenRegister Features**:
- `MappingService` (Twig-based mapping engine) -- already implemented, core dependency.
- `MappingMapper` / `Mapping` entity -- stores mapping definitions, already implemented with RBAC and cache invalidation.
- `Endpoint` entity -- configurable API endpoints with input/output mapping references, already implemented.
- `ObjectService` -- standard CRUD and filtering for register objects.
- `SchemaService` / `RegisterService` -- schema and register lookups for route-to-data resolution.
- Dossiq app -- stores ZGW mapping configuration and default mappings for the ZGW resource types.
