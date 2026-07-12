---
kind: feature
---

# Publish machine-readable OpenAPI specs for the ZGW API surface

## Why

Procest fully implements and routes six ZGW APIs (63 routes: zaken 16, documenten 13, catalogi 11, notificaties 9, besluiten 8, autorisaties 6) — but publishes no machine-readable spec for them: `docs/openapi/` holds only `leverancier-zaakportaal.yaml` and `tenant-saas.yaml`. Machine-readable API documentation is table stakes for Dutch government procurement (NLGov API Design Rules / Forum Standaardisatie; the 2026-02 feature counsel ranked OpenAPI a MUST; 2026-07 tender research confirms it recurs in PvE's). Third parties currently cannot discover or integrate against procest's ZGW surface without reading PHP.

## What changes

- `docs/openapi/zgw/{zaken,documenten,catalogi,besluiten,autorisaties,notificaties}.yaml` — six OpenAPI 3.0 documents describing procest's **actually routed** ZGW endpoints: paths, verbs, path/query parameters, JWT bearer security scheme, and summary-level responses. `info.description` states these document the procest route surface and defer full payload semantics to the VNG ZGW standard documentation (procest tracks the 1.x line).
- New `lib/Controller/ZgwOpenApiController.php` + routes:
  - `GET /api/zgw/openapi` — JSON index: implemented APIs, versions, and spec URLs.
  - `GET /api/zgw/{api}/openapi.yaml` — serves the YAML document (public, no CSRF; discovery endpoints carry no data).
- **Conformance gate (the anti-drift mechanism):** a PHPUnit test that parses `appinfo/routes.php` and asserts every `/api/zgw/...` route appears as a path+verb in the corresponding YAML, and every YAML path+verb has a backing route. Spec drift fails CI.
- Unit tests for the controller (index content, YAML serving, unknown-api 404).

## Impact

Additive only: new controller, new routes, new static docs, new tests. No behaviour change to the ZGW APIs themselves. The specs are served without authentication (they describe the API, contain no instance data) — consistent with NLGov API Design Rules requiring public API documentation.

## Capabilities

### New Capabilities
- `zgw-openapi-publication` — procest's ZGW API surface is discoverable and machine-readable via published OpenAPI 3.0 documents and a discovery endpoint, kept honest by a route↔spec conformance test.
