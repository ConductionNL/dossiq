# Delta: zgw-api-mapping

This change adds procest-specific declarative mapping profiles on top of
the existing `zgw-api-mapping` canonical spec. REQ-ZGW-1..10 below
complement (not replace) the canonical requirements, which define HOW
mapping works; these requirements define WHAT procest maps.

## Requirements

### Requirement: REQ-ZGW-1 — Procest MUST declare a ZGW mapping profile for every ZGW resource it exposes

Procest MUST ship a `ZgwMappingProfile` per ZGW resource, declaring the
procest schema slug, the ZGW API prefix, the endpoint path template, and
the inbound/outbound `Mapping` references.

#### Scenario: Profile registry covers all in-scope ZGW resources

- **GIVEN** the procest install has imported its ZGW configuration
- **WHEN** `ZgwMappingProfileRegistry::all()` is called
- **THEN** the registry MUST return one profile per ZRC resource (Zaak,
  Status, Resultaat, Rol, ZaakObject, ZaakInformatieObject, ZaakEigenschap)
- **AND** one profile per ZTC resource (ZaakType, StatusType, ResultaatType,
  RolType, Eigenschap, InformatieObjectType, BesluitType, Catalogus)
- **AND** one profile per DRC resource (EnkelvoudigInformatieObject,
  GebruiksRechten)
- **AND** one profile per BRC resource (Besluit, BesluitInformatieObject)

#### Scenario: Profile declares both directions when the resource is writable

- **GIVEN** the ZRC Zaak resource accepts POST, PUT, PATCH
- **WHEN** `ZgwMappingProfile::for('zaak')` is inspected
- **THEN** the profile MUST declare a non-null `inputMappingRef`
- **AND** a non-null `outputMappingRef`

#### Scenario: Read-only resources omit inbound mapping

- **GIVEN** the ZTC Catalogus resource is exposed only via GET (read-only)
- **WHEN** `ZgwMappingProfile::for('catalogus')` is inspected
- **THEN** `inputMappingRef` MUST be `null`
- **AND** `outputMappingRef` MUST be non-null

### Requirement: REQ-ZGW-2 — Procest MUST translate UUIDs to ZGW URLs bidirectionally

Every reference between procest entities MUST be exposed as an absolute
URL in ZGW responses, and every URL reference in ZGW requests MUST be
reduced to a UUID before persistence.

#### Scenario: Outbound URL construction uses _baseUrl

- **GIVEN** a case with `caseType: "abc-123"`
- **WHEN** the outbound Zaak mapping is applied
- **THEN** the `zaaktype` field MUST be
  `{{ _baseUrl }}/catalogi/v1/zaaktypen/abc-123`
- **AND** `_baseUrl` MUST resolve to the request scheme + host +
  `/index.php/apps/openregister/api/zgw`

#### Scenario: Inbound URL extraction rejects foreign hosts

- **GIVEN** a POST body with
  `zaaktype: "https://evil.example.com/catalogi/v1/zaaktypen/abc-123"`
- **WHEN** the inbound Zaak mapping is applied
- **THEN** `zgw_extract_uuid` MUST reject the URL because its host does
  not match the configured ZGW base host
- **AND** the request MUST return HTTP 400 with VNG error code
  `invalid-url-reference`

#### Scenario: Cross-API URL references use the correct API prefix

- **GIVEN** a Zaak references its StatusType (a ZTC resource)
- **WHEN** the outbound Zaak mapping renders the status URL
- **THEN** the URL MUST use `/catalogi/v1/statustypen/{uuid}` (ZTC prefix)
- **AND** MUST NOT use `/zaken/v1/statustypen/{uuid}`

### Requirement: REQ-ZGW-3 — Procest MUST map the `case` schema to the ZGW Zaak resource

The procest `case` schema MUST translate to the ZRC Zaak resource fields,
including required `bronorganisatie`, `verantwoordelijkeOrganisatie`,
`zaaktype`, `startdatum`, and `registratiedatum`.

#### Scenario: Outbound case-to-zaak mapping

- **GIVEN** a case `{uuid: "z-1", title: "Bouw", caseType: "ct-1", deadline: "2026-06-01", confidentiality: "public", sourceOrganisation: "123456789"}`
- **WHEN** the outbound mapping is applied
- **THEN** the response MUST contain `omschrijving: "Bouw"`,
  `uiterlijkeEinddatumAfdoening: "2026-06-01"`,
  `vertrouwelijkheidaanduiding: "openbaar"`,
  `bronorganisatie: "123456789"`, and an absolute `zaaktype` URL

#### Scenario: Inbound zaak-to-case mapping

- **GIVEN** a POST body
  `{zaaktype: "https://.../catalogi/v1/zaaktypen/ct-1", omschrijving: "Vergunning", vertrouwelijkheidaanduiding: "openbaar"}`
- **WHEN** the inbound mapping is applied
- **THEN** the persisted case MUST have `caseType: "ct-1"`,
  `title: "Vergunning"`, `confidentiality: "public"`

### Requirement: REQ-ZGW-4 — Procest MUST map case sub-resources (Status, Resultaat, Rol, ZaakEigenschap)

The procest sub-resources (`statusRecord`, `result`, `role`, `caseProperty`)
MUST translate to their ZRC counterparts (Status, Resultaat, Rol,
ZaakEigenschap), with the parent zaak referenced by URL.

#### Scenario: Status creation extracts zaak UUID from URL

- **GIVEN** a POST to `/api/zgw/zaken/v1/statussen/` with body
  `{zaak: "https://.../zaken/v1/zaken/z-1", statustype: "https://.../catalogi/v1/statustypen/st-1", datumStatusGezet: "2026-03-19T10:00:00Z"}`
- **WHEN** the inbound mapping is applied
- **THEN** the persisted statusRecord MUST have `case: "z-1"` and
  `statusType: "st-1"`

#### Scenario: Rol generic enum translation

- **GIVEN** a role with `roleType.name: "handler"`
- **WHEN** the outbound Rol mapping is applied
- **THEN** `omschrijvingGeneriek` MUST be `"behandelaar"` via
  `zgw_enum('rolomschrijvingGeneriek', ...)`

### Requirement: REQ-ZGW-5 — Procest MUST map case type schemas to ZTC resources

The procest `caseType`, `statusType`, `resultType`, `roleType`,
`propertyDefinition`, `documentType`, `decisionType`, and `catalogus`
schemas MUST translate to their ZTC counterparts. Inbound mapping is
out-of-scope for v1 (procest is the source of truth for case definitions).

#### Scenario: CaseType processingDeadline becomes doorlooptijd ISO 8601 duration

- **GIVEN** a caseType with `processingDeadline: "P30D"`
- **WHEN** the outbound ZaakType mapping is applied
- **THEN** `doorlooptijd` MUST be `"P30D"` (passed through unchanged)

#### Scenario: Virtual Catalogus derived from register

- **GIVEN** the procest register has `name: "VTH"`, `organisation.rsin: "123456789"`, `domein: "VTH"`
- **WHEN** `GET /api/zgw/catalogi/v1/catalogussen/` is called
- **THEN** the response MUST include a Catalogus with deterministic UUID
  derived from the register UUID
- **AND** the Catalogus MUST contain `domein: "VTH"`,
  `rsin: "123456789"`, and arrays of contained zaaktypen, besluittypen,
  informatieobjecttypen URLs

### Requirement: REQ-ZGW-6 — Procest MUST map the `document` schema to EnkelvoudigInformatieObject

The procest `document` schema MUST translate to the DRC
EnkelvoudigInformatieObject, including base64 content handling and
Nextcloud Files backing storage.

#### Scenario: Outbound document mapping with base64 content

- **GIVEN** a document with a Nextcloud file backing it
- **WHEN** `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}` is called
- **THEN** the response MUST include `inhoud` (base64-encoded file bytes),
  `formaat` (MIME type from file metadata), `bestandsnaam` (original name),
  `bestandsomvang` (file size in bytes)

#### Scenario: Inbound document upload decodes base64 to Nextcloud file

- **GIVEN** a POST with `inhoud: "{base64}"`, `bestandsnaam: "tekening.pdf"`, `formaat: "application/pdf"`
- **WHEN** the inbound mapping processes the body
- **THEN** the base64 content MUST be decoded and stored as a Nextcloud
  file via `IRootFolder`
- **AND** the document register object MUST link to the resulting file ID

### Requirement: REQ-ZGW-7 — Procest MUST map the `decision` schema to the BRC Besluit resource

The procest `decision` schema MUST translate to the BRC Besluit resource,
with linked documents exposed via BesluitInformatieObject.

#### Scenario: Outbound decision-to-besluit mapping

- **GIVEN** a decision with `decisionType: "dt-1"`, `case: "z-1"`, `decisionDate: "2026-03-19"`, `effectiveDate: "2026-04-01"`
- **WHEN** the outbound mapping is applied
- **THEN** the response MUST contain absolute URL refs for `besluittype`
  and `zaak`, and `datum: "2026-03-19"`, `ingangsdatum: "2026-04-01"`

#### Scenario: Inbound besluit derives uiterlijkeReactiedatum

- **GIVEN** a POST with `besluittype` URL referencing a BesluitType with
  `reactietermijn: "P42D"` and `datum: "2026-03-19"`
- **WHEN** the inbound mapping processes the body
- **THEN** `uiterlijkeReactiedatum` MUST be computed as `2026-03-19 + 42 days`
- **AND** stored on the decision object as `appealDeadline`

### Requirement: REQ-ZGW-8 — Procest MUST authenticate ZGW requests via openconnector JWT

All ZGW endpoints MUST require a valid JWT bearer token validated through
openconnector's `JwtValidationService`. Outbound ZGW calls MUST attach a
JWT issued by openconnector's `JwtIssuerService`.

#### Scenario: Missing JWT returns VNG-format 401

- **GIVEN** a request to `/api/zgw/zaken/v1/zaken/` without an
  `Authorization` header
- **WHEN** `ZgwAuthMiddleware` processes the request
- **THEN** the response MUST be HTTP 401 with body
  `{type: ".../authentication-error", code: "authentication-required", title: "Niet geauthenticeerd.", status: 401, detail: "..."}`

#### Scenario: Validated JWT sets organisation context

- **GIVEN** a valid JWT with `client_id: "vergunning-app"` validated by
  openconnector
- **WHEN** the request enters the ZGW controller
- **THEN** the OpenRegister request context MUST have `organisation` set
  to the openconnector-mapped organisation for `vergunning-app`
- **AND** all queries MUST be scoped to that organisation

#### Scenario: Outbound JWT caching

- **GIVEN** procest needs to call an external Documenten API 10 times in
  under 5 minutes
- **WHEN** `ZgwHttpClient::request()` is called for each
- **THEN** the JWT MUST be requested once from openconnector and cached
  in APCu for 5 minutes
- **AND** all 10 calls MUST reuse the same token

### Requirement: REQ-ZGW-9 — Out-of-scope ZGW endpoints MUST return HTTP 501

Endpoints not implemented by procest MUST return HTTP 501 with a
VNG-format error body and a stable error code, so that ZGW clients can
distinguish "not implemented" from "server error".

#### Scenario: Audittrail endpoint returns 501

- **GIVEN** the audittrail resource is deferred to
  `audit-trail-immutable`
- **WHEN** `GET /api/zgw/zaken/v1/zaken/{uuid}/audittrail` is called
- **THEN** the response MUST be HTTP 501 with body
  `{type: ".../not-implemented", code: "audittrail-not-implemented", title: "Niet geïmplementeerd.", status: 501, ...}`

#### Scenario: Autorisaties API returns 501

- **GIVEN** authorization is handled by Nextcloud, not via the
  Autorisaties API
- **WHEN** any `/api/zgw/autorisaties/v1/*` endpoint is called
- **THEN** the response MUST be HTTP 501 with code
  `autorisaties-not-implemented`

#### Scenario: _zoek search endpoints return 501

- **GIVEN** POST-based search is deferred to V2
- **WHEN** `POST /api/zgw/zaken/v1/_zoek` is called
- **THEN** the response MUST be HTTP 501 with code `zoek-not-implemented`

### Requirement: REQ-ZGW-10 — Procest MUST run VNG conformance tests in CI

A `ZgwConformanceTest` suite MUST exercise each declared ZGW resource
against the VNG reference postman collection and OpenAPI documents, and
MUST run in CI on every push to `development`.

#### Scenario: VNG postman collection runs against fixture data

- **GIVEN** procest is installed with seed data including a catalogus,
  zaaktypen, and zaken
- **WHEN** the conformance test runs the VNG ZRC v1.5.1 postman
  collection
- **THEN** every assertion MUST pass
- **AND** every response MUST validate against the published VNG OpenAPI
  schema for that resource

#### Scenario: Conformance test covers all 19 in-scope resources

- **GIVEN** the conformance test is executed
- **WHEN** the test discovery phase enumerates profiles
- **THEN** every profile in `ZgwMappingProfileRegistry::all()` MUST have
  at least one corresponding conformance test case
- **AND** absence of a test case MUST fail the suite

#### Scenario: Conformance failures block PR merge

- **GIVEN** a PR modifies a ZGW mapping JSON file
- **WHEN** CI runs the conformance suite
- **THEN** any failed assertion MUST mark the CI job as failed
- **AND** the GitHub branch protection rule MUST block merge until the
  job passes
