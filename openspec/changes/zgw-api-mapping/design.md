# Design: zgw-api-mapping

## Architecture

Procest ships one `ZgwMappingProfile` per ZGW resource as a JSON document
in `lib/Settings/zgw_mappings/`, imported into OpenRegister `Mapping` and
`Endpoint` entities at install via `ConfigurationService::importFromApp()`.
The runtime engine (`OpenRegister\MappingService`) is unchanged -- this
change is purely declarative.

## Entity-by-Entity Mapping

| Procest schema (English) | ZGW resource (Dutch) | API | Direction | Notes |
|---|---|---|---|---|
| `case` | Zaak | ZRC | both | UUID-keyed; `caseType` -> URL ref to ZaakType |
| `statusRecord` | Status | ZRC | both | Sub-resource of Zaak; immutable once created |
| `result` | Resultaat | ZRC | both | One per case at terminal status |
| `role` | Rol | ZRC | both | `participant` <-> `betrokkene`, `roleType` URL |
| `caseObject` | ZaakObject | ZRC | both | Generic object link |
| `caseDocument` | ZaakInformatieObject | ZRC | both | Links case to DRC document |
| `caseProperty` | ZaakEigenschap | ZRC | both | Custom-property values |
| `caseType` | ZaakType | ZTC | outbound | `processingDeadline` -> `doorlooptijd` (ISO 8601) |
| `statusType` | StatusType | ZTC | outbound | `order` -> `volgnummer` |
| `resultType` | ResultaatType | ZTC | outbound | `archivalPeriod` -> `archiefactietermijn` |
| `roleType` | RolType | ZTC | outbound | `name` -> `omschrijving` + generic enum |
| `propertyDefinition` | Eigenschap | ZTC | outbound | `propertyType` -> `specificatie.formaat` |
| `documentType` | InformatieObjectType | ZTC | outbound | `confidentiality` -> `vertrouwelijkheidaanduiding` |
| `decisionType` | BesluitType | ZTC | outbound | `caseTypes` -> `zaaktypen` URL list |
| `catalogus` | Catalogus | ZTC | outbound | `domein` + `rsin` map directly |
| `document` | EnkelvoudigInformatieObject | DRC | both | `content` base64 or download URL |
| `usageRights` | GebruiksRechten | DRC | both | Per-document rights window |
| `decision` | Besluit | BRC | both | `decisionType` -> `besluittype` URL ref |
| `decisionDocument` | BesluitInformatieObject | BRC | both | Links besluit to DRC document |
| `kanaal` | Kanaal | NRC | outbound | Derived from procest event registry |
| `abonnement` | Abonnement | NRC | both | `callbackUrl` + `kanalen` filters |

## URL Identifier Scheme

Procest stores objects by UUID v4 in OpenRegister. ZGW requires absolute
URL references. The mapping engine resolves both directions:

- **Outbound**: `{{ _baseUrl }}/api/zgw/{api}/v1/{resource}/{{ uuid }}` --
  rendered via the `MappingExtension` `_baseUrl` runtime variable, which
  resolves to `scheme://host/index.php/apps/openregister/api/zgw`.
- **Inbound**: `{{ field | zgw_extract_uuid }}` -- splits on `/`, returns
  the last path segment. Validates UUID v4 format and rejects URLs whose
  host does not match the configured ZGW base host (anti-SSRF).
- **Cross-API**: a Zaak references a ZaakType via `/catalogi/v1/zaaktypen/{uuid}`,
  not `/zaken/v1/zaaktypen/{uuid}`. Each profile declares its target API
  prefix explicitly.

## Incoming vs Outgoing Responsibilities

- **Incoming (Dutch -> English, on POST/PUT/PATCH)**: parse ZGW body via
  inbound `Mapping`, extract UUIDs from URL refs, reverse-translate enums
  (`vertrouwelijkheidaanduiding` -> `confidentiality`), cast types, validate
  required fields, persist as procest entity.
- **Outgoing (English -> Dutch, on GET and response bodies)**: load procest
  entity, apply outbound `Mapping`, construct URL refs, translate enums
  forward, format dates as ISO 8601, format durations as `P{n}D`, wrap list
  responses in HAL pagination via `ZgwPaginationHelper`.

The split lives in the `Endpoint` entity: GET-only endpoints reference
only `outputMapping`; POST/PUT/PATCH endpoints reference both
`inputMapping` and `outputMapping` (the response is always re-mapped from
the persisted entity, not echoed from the request).

## OAuth2 / JWT Authentication

ZGW clients authenticate via JWT bearer tokens issued by an Autorisaties
API. Procest delegates this to **openconnector**:

- Inbound: `ZgwAuthMiddleware` validates the JWT `client_id`, `iss`,
  `iat`, and required `autorisaties` claim via openconnector's
  `JwtValidationService`. The validated `client_id` is set as the
  organisation context on the OpenRegister request, scoping all queries.
- Outbound (procest calling external ZGW components, e.g., a Documenten
  API): procest requests a JWT via openconnector's `JwtIssuerService` and
  attaches it as `Authorization: Bearer {jwt}` on the outbound HTTP call.
- Token cache: 5-minute TTL via APCu, keyed by `client_id` + secret hash.

Procest does NOT store JWT secrets directly; all credentials live in
openconnector's `Source` entities.

## Out-of-Scope Endpoints

- `/api/zgw/zaken/v1/zaken/{uuid}/audittrail` -- covered by
  `audit-trail-immutable` spec; deferred.
- `/api/zgw/autorisaties/v1/applicaties` -- Nextcloud auth substitutes.
- `/api/zgw/catalogi/v1/selectielijstklassen` -- proxied to VNG
  Selectielijst API by a future `archive` change.
- `/api/zgw/zaken/v1/_zoek` -- POST-based search; V2.
- `/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/_zoek` -- V2.

## File Changes

| File | Change |
|------|--------|
| `lib/Settings/zgw_mappings/zaak.json` | New: inbound + outbound Mapping |
| `lib/Settings/zgw_mappings/status.json` | New |
| `lib/Settings/zgw_mappings/resultaat.json` | New |
| `lib/Settings/zgw_mappings/rol.json` | New |
| `lib/Settings/zgw_mappings/zaakobject.json` | New |
| `lib/Settings/zgw_mappings/zaakinformatieobject.json` | New |
| `lib/Settings/zgw_mappings/zaakeigenschap.json` | New |
| `lib/Settings/zgw_mappings/zaaktype.json` | New |
| `lib/Settings/zgw_mappings/statustype.json` | New |
| `lib/Settings/zgw_mappings/resultaattype.json` | New |
| `lib/Settings/zgw_mappings/roltype.json` | New |
| `lib/Settings/zgw_mappings/eigenschap.json` | New |
| `lib/Settings/zgw_mappings/informatieobjecttype.json` | New |
| `lib/Settings/zgw_mappings/besluittype.json` | New |
| `lib/Settings/zgw_mappings/catalogus.json` | New |
| `lib/Settings/zgw_mappings/enkelvoudiginformatieobject.json` | New |
| `lib/Settings/zgw_mappings/gebruiksrechten.json` | New |
| `lib/Settings/zgw_mappings/besluit.json` | New |
| `lib/Settings/zgw_mappings/besluitinformatieobject.json` | New |
| `lib/Settings/zgw_endpoints.json` | New: Endpoint entities for all 19 resources |
| `lib/Service/ZgwAuthMiddleware.php` | New: JWT validation via openconnector |
| `lib/Service/ZgwMappingProfile.php` | New: value object describing one profile |
| `lib/Service/Zgw*RulesService.php` | Deprecated; logic migrated to mapping JSON |
| `tests/Conformance/ZgwConformanceTest.php` | New: VNG postman collection runner |
