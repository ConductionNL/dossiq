---
retrofit: true
---

# ZGW Autorisaties (AC) API

**Owned by**: Procest (ZGW API layer for case management)

## Purpose
Expose the VNG ZGW **Autorisaties (AC) API** — the sixth ZGW component standard, distinct from the five data APIs (ZRC/ZTC/DRC/BRC/NRC) covered by `zgw-api-mapping`. The AC API manages *applicaties*: the registered API consumers and the scopes (autorisaties) granted to each. Procest backs applicaties with OpenRegister's `ConsumerMapper` rather than OpenRegister object storage, translating between the ZGW `applicatie` representation and the underlying consumer entity's `authorizationConfiguration`. This capability defines the observed CRUD contract for `/api/zgw/autorisaties/v1/applicaties` and the three VNG AC business rules enforced on write (ac-001 clientId uniqueness, ac-002 heeftAlleAutorisaties consistency, ac-003 scope-based field requirements).

## Context
`AcController` (`lib/Controller/AcController.php`) serves the AC API. It does **not** use the Twig mapping engine that the data APIs rely on; instead it maps a ZGW `applicatie` body to/from a `Consumer` entity:
- The first `clientId` becomes the consumer `name`; any additional `clientIds` are stored in `authorizationConfiguration.clientIds`.
- `heeftAlleAutorisaties` maps to `authorizationConfiguration.superuser`; `autorisaties` to `authorizationConfiguration.scopes`.
- `label` maps to the consumer `description`; an optional `secret` maps to `authorizationConfiguration.publicKey`; `authorizationType` is fixed to `jwt-zgw` and `algorithm` to `HS256`.

All six endpoints (`index`, `create`, `show`, `update`, `patch`, `destroy`) are annotated `@PublicPage @NoAdminRequired @NoCSRFRequired @CORS` and gate on `ZgwService::validateJwtAuth()`. `patch` delegates to `update`; `show('consumer')` delegates to `index`. When `ConsumerMapper` is unavailable the controller returns `503 Service Unavailable`.

This is the authorization-management surface of the ZGW stack — it is where applicaties and their scopes are created and revoked. See the **Notes** under each requirement and the capability-level security notes at the end for observed authorization-enforcement gaps.

## Requirements

### REQ-001: The system SHALL expose CRUD endpoints for ZGW applicaties backed by ConsumerMapper
The AC API SHALL serve `GET /api/zgw/autorisaties/v1/applicaties` (list, paginated envelope `{count, next, previous, results}`), `POST` (create), `GET /{uuid}` (retrieve), `PUT /{uuid}` and `PATCH /{uuid}` (update), and `DELETE /{uuid}` (delete). Each applicatie is persisted as an OpenRegister `Consumer` via `ConsumerMapper`, with bidirectional translation between the ZGW `applicatie` shape (`url`, `uuid`, `clientIds`, `label`, `heeftAlleAutorisaties`, `autorisaties`) and the consumer's `name` / `description` / `authorizationConfiguration`. The list endpoint supports filtering by `clientId` or `clientIds` query parameter, searching the consumer `name` first and then `authorizationConfiguration.clientIds` for extra identifiers.

#### Scenario: List applicaties returns a ZGW pagination envelope
- **GIVEN** a valid JWT and at least one stored consumer
- **WHEN** `GET /api/zgw/autorisaties/v1/applicaties` is called
- **THEN** the response SHALL be `{count, next: null, previous: null, results: [...]}`
- **AND** each result SHALL carry `url`, `uuid`, `clientIds`, `label`, `heeftAlleAutorisaties`, and `autorisaties`
- **AND** `clientIds` SHALL be the consumer `name` followed by any `authorizationConfiguration.clientIds`, never empty

#### Scenario: Create maps an applicatie body to a consumer
- **GIVEN** a valid JWT and a body with `clientIds: ["client-a", "client-b"]`, `label`, `heeftAlleAutorisaties`, `autorisaties`
- **WHEN** `POST /api/zgw/autorisaties/v1/applicaties` is called and validation passes
- **THEN** a consumer SHALL be created with `name = "client-a"`, `authorizationConfiguration.clientIds = ["client-b"]`, `authorizationType = "jwt-zgw"`, `algorithm = "HS256"`
- **AND** the response SHALL be `201 Created` with the mapped applicatie

#### Scenario: Filter by clientId falls back to authConfig extras
- **GIVEN** a consumer whose extra clientId lives only in `authorizationConfiguration.clientIds`
- **WHEN** `GET .../applicaties?clientId=<extra-id>` is called and the name search returns zero
- **THEN** the controller SHALL scan all consumers and include any whose `authorizationConfiguration.clientIds` contains the requested id

**Notes**: `show('consumer')` is a special-case alias that delegates to `index()` (re-running the clientId filter). `patch` is a thin delegate to `update` — PATCH is therefore a full replace, not a true partial merge of `authorizationConfiguration`; observed behavior, flagged for future tightening. `update` copies body fields onto the consumer via dynamic `set<Field>` setters guarded by `method_exists`, so unknown fields are silently ignored.

### REQ-002: The system SHALL reject applicaties whose clientId is already used by another applicatie (ac-001)
On create and update, the system SHALL ensure that none of the requested `clientIds` are already in use by a different applicatie. The check compares each requested id against every existing consumer's full clientId set (primary `name` + `authorizationConfiguration.clientIds`), excluding the applicatie being updated by its `uuid`. A collision SHALL return `400 Bad Request` with `invalidParams[].code = "clientId-exists"`.

#### Scenario: Duplicate clientId is rejected
- **GIVEN** an existing applicatie with clientId `"client-x"`
- **WHEN** `POST .../applicaties` is called with `clientIds: ["client-x"]`
- **THEN** the response SHALL be `400` with an `invalidParams` entry `{name: "clientIds", code: "clientId-exists"}`

#### Scenario: Updating an applicatie does not collide with itself
- **GIVEN** an applicatie owning clientId `"client-y"`
- **WHEN** it is updated with the same `clientIds: ["client-y"]` and its own `uuid` is excluded
- **THEN** the uniqueness check SHALL skip the self record and not raise a collision

**Notes**: The uniqueness check runs `ConsumerMapper::findAll()` (full scan) per write — observed, not optimized. **Caller gap (observed)**: `validateApplicatieBody()` is invoked from `create()` but NOT from `update()`/`patch()`; the `$excludeUuid` parameter exists on the validation helpers but no caller passes it. Therefore ac-001/ac-002/ac-003 are enforced on POST only, never on PUT/PATCH. See capability security notes.

### REQ-003: The system SHALL enforce consistency between heeftAlleAutorisaties and autorisaties (ac-002)
When `heeftAlleAutorisaties` is `true`, the `autorisaties` array SHALL be empty; when `false`, `autorisaties` SHALL be non-empty (if the key is present). Violations SHALL return `400 Bad Request` with `invalidParams[].name = "nonFieldErrors"` and code `ambiguous-authorizations-specified` (true + non-empty) or `missing-authorizations` (false + empty). The boolean SHALL be normalized from `true`/`"true"`/`"1"`/`1` and `false`/`"false"`/`"0"`/`0`.

#### Scenario: heeftAlleAutorisaties true with autorisaties is ambiguous
- **GIVEN** a create body with `heeftAlleAutorisaties: true` and a non-empty `autorisaties`
- **WHEN** validation runs
- **THEN** the response SHALL be `400` with code `ambiguous-authorizations-specified`

#### Scenario: heeftAlleAutorisaties false with empty autorisaties is incomplete
- **GIVEN** a create body with `heeftAlleAutorisaties: false` and an explicitly-present empty `autorisaties`
- **WHEN** validation runs
- **THEN** the response SHALL be `400` with code `missing-authorizations`

**Notes**: The `missing-authorizations` branch only fires when the `autorisaties` key is explicitly present in the body (`array_key_exists`); an entirely omitted `autorisaties` with `heeftAlleAutorisaties=false` is not flagged. Observed behavior.

### REQ-004: The system SHALL require scope-appropriate fields on each autorisatie entry (ac-003)
For each entry in `autorisaties`, when the component/scope combination implies a domain, the system SHALL require the matching reference fields: `zrc` with a scope containing `"zaken"` requires `zaaktype` and `maxVertrouwelijkheidaanduiding`; `drc` with a scope containing `"documenten"` requires `informatieobjecttype` and `maxVertrouwelijkheidaanduiding`; `brc` with a scope containing `"besluiten"` requires `besluittype`. Each missing field SHALL append an `invalidParams` entry `{name: "autorisaties.{index}.{field}", code: "required"}`; any violation SHALL return `400 Bad Request`.

#### Scenario: ZRC zaken-scoped autorisatie missing zaaktype is rejected
- **GIVEN** an autorisatie `{component: "zrc", scopes: ["zaken.lezen"]}` without `zaaktype`
- **WHEN** validation runs
- **THEN** the response SHALL be `400` with an `invalidParams` entry for `autorisaties.0.zaaktype` code `required`
- **AND** a missing `maxVertrouwelijkheidaanduiding` SHALL produce a second `required` entry

#### Scenario: BRC besluiten-scoped autorisatie missing besluittype is rejected
- **GIVEN** an autorisatie `{component: "brc", scopes: ["besluiten.aanmaken"]}` without `besluittype`
- **WHEN** validation runs
- **THEN** the response SHALL be `400` with an `invalidParams` entry for `autorisaties.0.besluittype` code `required`

**Notes**: Scope matching is a substring test (`str_contains`) on each scope string for the keyword `zaken`/`documenten`/`besluiten`, not an exact scope match. The BRC branch is implemented but flagged in-code as "not tested". `ztc`/`nrc` components have no field requirements. Observed behavior.

### REQ-005: The system SHALL authenticate every AC endpoint via ZGW JWT and degrade safely when the consumer backend is unavailable
Every AC endpoint SHALL call `ZgwService::validateJwtAuth()` before performing any work, returning `401 NotAuthenticated` when the `Authorization` header is absent and `403` when `AuthorizationService::authorizeJwt()` rejects the token. When `ConsumerMapper` is not available, endpoints SHALL return `503 Service Unavailable` with `{detail: "Consumer service not available"}`. Internal errors SHALL be caught, logged via the ZGW logger, and surfaced as `500` (list) or `400` (write) without leaking stack traces.

#### Scenario: Missing Authorization header is rejected
- **GIVEN** a request with no `Authorization` header
- **WHEN** any AC endpoint is invoked
- **THEN** the response SHALL be `401` with `code: "not_authenticated"`

#### Scenario: ConsumerMapper unavailable degrades to 503
- **GIVEN** a valid JWT but no `ConsumerMapper` injected
- **WHEN** any AC endpoint (other than the `show('consumer')` alias) is invoked
- **THEN** the response SHALL be `503` with `detail: "Consumer service not available"`

**Notes — capability-level security observations (observed, NOT fixed in this retrofit)**:
- **Authorization vs authentication gap (potential IDOR / privilege escalation)**: `validateJwtAuth()` only verifies that the JWT is *valid* — it performs no scope check. `ZgwService` exposes a `hasScope()` helper, but `AcController` never calls it. Consequently **any** holder of a valid ZGW JWT can list, create, update, or delete *any* applicatie (the authorization grants themselves), regardless of their own scopes. This is the AC management surface, so unrestricted write access is a privilege-escalation vector (OWASP A01:2021). `show`/`update`/`destroy` resolve the target purely by `{uuid}` with no per-object ownership guard — classic IDOR shape (cf. ADR-005 Rule 3). Flagged for a follow-up authorization design decision; not changed here per retrofit guardrails.
- **Write-path validation gap (see REQ-002 notes)**: `update`/`patch` skip `validateApplicatieBody()` entirely, so a PUT/PATCH can introduce a duplicate clientId or an inconsistent `heeftAlleAutorisaties`/`autorisaties` pair that POST would reject.
- **No CSRF on state-changing endpoints**: all writes carry `@NoCSRFRequired` (consistent with a JWT-authenticated machine API, but noted for completeness).
