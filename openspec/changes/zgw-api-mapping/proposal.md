# Proposal: zgw-api-mapping

## Summary

Formalize procest as a ZGW (Zaakgericht Werken) component by replacing the
ad-hoc `Zgw*RulesService` stack with a declarative mapping layer that
translates procest's English-named OpenRegister entities into the five VNG
ZGW API contracts (ZRC, ZTC, DRC, BRC, NRC) and back. The existing
`ZgwZrcRulesService`, `ZgwZtcRulesService`, `ZgwDrcRulesService`,
`ZgwBrcRulesService`, `ZgwBusinessRulesService`, and `ZgwMappingService`
encode mapping logic as PHP business rules; this change replaces them with
a `ZgwMappingProfile` per resource, wired to the OpenRegister `Mapping`
+ `Endpoint` engine described in `openspec/specs/zgw-api-mapping/spec.md`.

## Motivation

Procest already runs as a ZGW backend in production but its mapping logic
is scattered across ~7,000 lines of `lib/Service/Zgw*.php` with no formal
spec linking procest entities to ZGW resources. The canonical spec
declares HOW mapping works (Twig engine, Endpoint wiring, pagination,
errors); this change declares WHAT procest maps -- entity-by-entity,
direction-by-direction. It locks in the URL identifier scheme, the
incoming-vs-outgoing split, the OAuth2/JWT integration with openconnector,
and the out-of-scope endpoints (audittrail, Autorisaties API, Selectielijst).

## Affected Projects

- [x] Project: `procest` -- declares ZGW mapping profiles for all 19+ ZGW resources
- [ ] Project: `openregister` -- consumes mappings via existing engine (no changes)
- [ ] Project: `openconnector` -- provides OAuth2/JWT for outbound ZGW calls (referenced only)

## Scope

### In Scope (V1)

- **REQ-ZGW-1**: Resource mapping table (procest entity to ZGW resource)
- **REQ-ZGW-2**: URL identifier scheme (procest UUID to ZGW URL)
- **REQ-ZGW-3**: Outbound mapping (English to Dutch) per entity
- **REQ-ZGW-4**: Inbound mapping (Dutch to English) per entity
- **REQ-ZGW-5**: Sub-resource mapping (status, rol, resultaat, zaakeigenschap)
- **REQ-ZGW-6**: ZTC catalogus mapping (caseType, statusType, resultType, roleType, decisionType, documentType)
- **REQ-ZGW-7**: DRC document mapping (document, usageRights)
- **REQ-ZGW-8**: BRC besluit mapping (decision, decisionDocument)
- **REQ-ZGW-9**: OAuth2/JWT authentication via openconnector
- **REQ-ZGW-10**: Conformance tests against VNG reference implementation

### Out of Scope

- Autorisaties API (AC) -- Nextcloud auth handles it
- Audittrail resource -- separate `audit-trail-immutable` spec
- Selectielijst API integration -- separate `archive` change
- NRC subscription persistence -- handled by `notificatie-engine`
- `_zoek` POST-based search endpoints -- V2

## Approach

1. Author a `ZgwMappingProfile` value object per ZGW resource holding the
   inbound and outbound `Mapping` reference, the procest schema slug, and
   the ZGW endpoint path template.
2. Migrate per-entity logic from `Zgw*RulesService` into declarative
   mapping JSON files under `lib/Settings/zgw_mappings/`, loaded by
   `ConfigurationService` into OpenRegister `Mapping` entities at install.
3. Add a `ZgwConformanceTest` suite exercising each resource against the
   VNG postman collection in CI.
