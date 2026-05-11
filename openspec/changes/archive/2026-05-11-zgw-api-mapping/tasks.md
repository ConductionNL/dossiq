# Tasks: zgw-api-mapping

## Implementation Tasks

- [ ] **T01**: Define `ZgwMappingProfile` value object (`lib/Service/ZgwMappingProfile.php`) holding profile name, procest schema slug, ZGW API prefix, endpoint path template, inbound Mapping reference, outbound Mapping reference, required fields list, and conformance test tag
- [ ] **T02**: Author per-entity outbound mapping JSON files for ZRC resources (zaak, status, resultaat, rol, zaakobject, zaakinformatieobject, zaakeigenschap) in `lib/Settings/zgw_mappings/`, each defining English-to-Dutch property translation, enum maps, URL ref templates, and ISO 8601 date formats
- [ ] **T03**: Author per-entity inbound mapping JSON files for ZRC resources, each using `zgw_extract_uuid`, `zgw_enum_reverse`, and type casts to translate Dutch ZGW request bodies into procest schema objects
- [ ] **T04**: Author ZTC catalog mapping JSON files (zaaktype, statustype, resultaattype, roltype, eigenschap, informatieobjecttype, besluittype, catalogus) -- outbound only for v1, since procest is the source of truth for case definitions
- [ ] **T05**: Author DRC document mapping JSON files (enkelvoudiginformatieobject, gebruiksrechten) including base64 content handling, MIME type mapping, and `bestandsomvang` byte-size derivation
- [ ] **T06**: Author BRC besluit mapping JSON files (besluit, besluitinformatieobject) including `uiterlijkeReactiedatum` derivation from `besluittype.reactietermijn` and `vervalreden` enum translation
- [ ] **T07**: Author `lib/Settings/zgw_endpoints.json` declaring one Endpoint entity per (resource x HTTP method) combination -- approximately 60 endpoints covering GET-list, GET-detail, POST, PUT, PATCH, DELETE per resource where supported
- [ ] **T08**: Wire `ConfigurationService::importFromApp()` to load all mapping and endpoint JSON files into OpenRegister at install/upgrade, with idempotent re-import on version bump
- [ ] **T09**: Implement `ZgwAuthMiddleware` validating JWT bearer tokens via openconnector's `JwtValidationService`, extracting `client_id` as organisation context, and rejecting tokens with HTTP 401 plus VNG-format error body
- [ ] **T10**: Migrate request signing for outbound ZGW calls into a `ZgwHttpClient` wrapper that requests JWTs from openconnector's `JwtIssuerService` and caches them in APCu with 5-minute TTL
- [ ] **T11**: Author `tests/Conformance/ZgwConformanceTest.php` running the VNG postman collection (ZRC, ZTC, DRC, BRC) against a fixture-loaded procest, asserting response schemas match VNG OpenAPI documents
- [ ] **T12**: Deprecate `ZgwZrcRulesService`, `ZgwZtcRulesService`, `ZgwDrcRulesService`, `ZgwBrcRulesService`, and `ZgwBusinessRulesService` -- add `@deprecated` docblocks pointing to the new mapping JSON, retain for one release for backward compatibility

## Verification Tasks

- [ ] **V01**: Each of the 19 ZGW resources in the mapping table has both an inbound (if applicable) and outbound JSON file under `lib/Settings/zgw_mappings/`
- [ ] **V02**: `zgw_endpoints.json` registers HTTP methods matching the spec table; PATCH endpoints have `passThrough: true`
- [ ] **V03**: VNG conformance suite passes for ZRC v1.5.1, ZTC v1.3.1, DRC v1.4.3, BRC v1.1.0
- [ ] **V04**: JWT middleware rejects expired, malformed, and wrong-issuer tokens with VNG-formatted error responses
- [ ] **V05**: URL refs in outbound responses are absolute and use the configured `_baseUrl`; URL refs in inbound bodies are reduced to UUIDs and rejected if the host does not match
- [ ] **V06**: Out-of-scope endpoints (audittrail, autorisaties, selectielijstklassen, `_zoek`) return HTTP 501 with VNG-format error body
