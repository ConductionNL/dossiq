# Design — Retrofit zgw-autorisaties-api

> **Retrofit change.** Tasks describe retroactive annotation of existing code, not new implementation work. No behavior changes ship in this change.

## Context
`AcController` serves the VNG ZGW Autorisaties (AC) API at `/api/zgw/autorisaties/v1/applicaties`. It manages *applicaties* (registered API consumers and their granted scopes) backed by OpenRegister's `ConsumerMapper` via `ZgwService::getConsumerMapper()`. Unlike the five ZGW data APIs (`zgw-api-mapping`), it does not use the Twig mapping engine — it maps applicatie payloads directly onto `Consumer` entities and their `authorizationConfiguration`.

## Mapping model (observed)
| ZGW applicatie field | Consumer storage |
|---|---|
| `clientIds[0]` | `name` |
| `clientIds[1..]` | `authorizationConfiguration.clientIds` |
| `label` | `description` |
| `heeftAlleAutorisaties` | `authorizationConfiguration.superuser` |
| `autorisaties` | `authorizationConfiguration.scopes` |
| `secret` (optional) | `authorizationConfiguration.publicKey` |
| (fixed) | `authorizationType = "jwt-zgw"`, `algorithm = "HS256"` |

## Business rules (observed)
- **ac-001** (`validateClientIdUniqueness`) — no requested clientId may already belong to another applicatie; `clientId-exists` on collision.
- **ac-002** (`validateAutorisatieConsistency`) — `heeftAlleAutorisaties=true` ⇒ empty `autorisaties`; `false` ⇒ non-empty (when key present). Codes `ambiguous-authorizations-specified` / `missing-authorizations`.
- **ac-003** (`validateAutorisatieScopes`) — component+scope substring implies required reference fields (zrc/zaken → zaaktype + maxVertrouwelijkheidaanduiding; drc/documenten → informatieobjecttype + maxVertrouwelijkheidaanduiding; brc/besluiten → besluittype).

## Observed gaps documented (not fixed here)
1. **Authorization gap** — endpoints authenticate the JWT but never check scope (`hasScope` exists in `ZgwService` but is uncalled). Any valid-JWT holder can manage any applicatie. This is the authorization-grant surface, so it is a privilege-escalation / IDOR concern (OWASP A01:2021, ADR-005 Rule 3). Object resolution is by `{uuid}` only, no ownership guard.
2. **Write-path validation gap** — `update`/`patch` skip `validateApplicatieBody()`, so ac-001/002/003 are enforced on POST only. The `$excludeUuid` parameter on the validators is never passed by any caller.
3. **PATCH is a full replace** — `patch` delegates to `update`, which is a whole-body field copy, not a true partial merge.

These belong in a future authorization-hardening change, tracked separately — this retrofit only specifies what the code does today.

## Decision
Mint a new capability `zgw-autorisaties-api` rather than extend `zgw-api-mapping`: AC is a distinct VNG component, uses a different storage backend (ConsumerMapper, not objects), and shares no mapping infrastructure with the data APIs.
