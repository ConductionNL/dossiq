# zgw-openapi-publication

Procest publishes OpenAPI 3.0 documents for its six routed ZGW APIs plus a discovery endpoint, with a conformance test that fails when specs and routes drift apart.

## ADDED Requirements

### Requirement: OpenAPI documents for every routed ZGW API

The repo SHALL contain `docs/openapi/zgw/<api>.yaml` (OpenAPI 3.0) for each of `zaken`, `documenten`, `catalogi`, `besluiten`, `autorisaties`, `notificaties`, describing every routed path and verb under `/api/zgw/<api>/...` with path/query parameters and the JWT bearer security scheme. Payload semantics MAY be documented at summary level with a normative reference to the VNG ZGW 1.x standard.

#### Scenario: Every route documented

- **GIVEN** the ZGW route table in `appinfo/routes.php`
- **WHEN** the conformance test compares routes against the six YAML documents
- **THEN** every `/api/zgw/...` route (path + verb) appears in exactly one document
- **AND** every documented path + verb has a backing route

@e2e exclude Enforced by a PHPUnit conformance test parsing routes.php and the YAML files; no browser surface.

### Requirement: Discovery endpoint

`GET /api/zgw/openapi` SHALL return a JSON index of implemented ZGW APIs with, per API: name, base path, standard version line (1.x), and the URL of its OpenAPI document. `GET /api/zgw/{api}/openapi.yaml` SHALL serve the YAML with content type `application/yaml`; unknown `{api}` values SHALL return 404.

#### Scenario: Index lists six APIs

- **GIVEN** a running procest instance
- **WHEN** a client requests `GET /apps/procest/api/zgw/openapi`
- **THEN** the JSON response lists the six APIs with resolvable spec URLs

@e2e exclude Controller behaviour covered by PHPUnit unit tests (index content, YAML serving, 404); no UI.

#### Scenario: Spec served as YAML

- **WHEN** a client requests `GET /apps/procest/api/zgw/zaken/openapi.yaml`
- **THEN** the response is the zaken OpenAPI document with an `application/yaml` content type

@e2e exclude Same rationale.

#### Scenario: Unknown API 404s

- **WHEN** a client requests `GET /apps/procest/api/zgw/bogus/openapi.yaml`
- **THEN** the response status is 404

@e2e exclude Same rationale.

### Requirement: Public, read-only discovery

The discovery and spec-serving endpoints SHALL be annotated `#[PublicPage]` + `#[NoCSRFRequired]` and SHALL serve only static documentation content — never instance data.

#### Scenario: Anonymous access to specs

- **GIVEN** an unauthenticated client
- **WHEN** it requests the discovery endpoint or a spec document
- **THEN** the request succeeds without login (documentation is public per NLGov API Design Rules)

@e2e exclude Auth posture asserted by unit test on controller attributes; no data exposure possible from static YAML.
