## 1. OpenAPI documents

- [x] 1.1 Enumerate ALL `/api/zgw/...` routes from `appinfo/routes.php` (63 routes across zrc/drc/ztc/brc/ac/nrc controllers plus any zgw-mapping ones under `/api/zgw/` — derive the exact set from the file, do not trust this count). Group them per API base path (`zaken`, `documenten`, `catalogi`, `besluiten`, `autorisaties`, `notificaties`).
- [x] 1.2 Write `docs/openapi/zgw/<api>.yaml` for each API: OpenAPI 3.0.3; `info` block naming procest, the API, and stating: paths generated from the procest route surface, payload semantics per the VNG ZGW 1.x standard (link to vng-realisatie.github.io/gemma-zaken); `servers: [{url: /apps/procest}]`; `components.securitySchemes` with JWT bearer (`ZGW-Token`); every path+verb from 1.1 with: `operationId` (= controller#method), path parameters (from `{...}` segments, type string), summary line derived from the controller method docblock where available, generic `200/201/204` + `400/401/403/404` responses (`application/json`). For `{resource}` wildcard routes, document the parameter with an `enum` of the resources the controller actually accepts — read the controller's allowed-resource list (e.g. ZrcController/DrcController resource validation) and use exactly that.
- [x] 1.3 Validate each YAML parses (e.g. `python3 -c "import yaml,glob; [yaml.safe_load(open(f)) for f in glob.glob('docs/openapi/zgw/*.yaml')]"`).

## 2. Discovery controller

- [x] 2.1 Create `lib/Controller/ZgwOpenApiController.php` (follow SPDX/docblock/@spec conventions of a recent controller, e.g. lib/Controller/MetricsController.php): `index()` returns JSON {apis: [{id, name, basePath, standard: 'VNG ZGW 1.x', specUrl}]} for the six APIs; `spec(string $api)` streams `docs/openapi/zgw/<api>.yaml` with content type `application/yaml`, 404 JSONResponse for unknown ids (allow-list the six ids — no path traversal). Both methods `#[PublicPage]` + `#[NoCSRFRequired]`. Tag methods `@spec openspec/changes/zgw-openapi-publication/specs/zgw-openapi-publication/spec.md`.
- [x] 2.2 Register routes in `appinfo/routes.php` in the ZGW section: `['name' => 'zgwOpenApi#index', 'url' => '/api/zgw/openapi', 'verb' => 'GET']` and `['name' => 'zgwOpenApi#spec', 'url' => '/api/zgw/{api}/openapi.yaml', 'verb' => 'GET']`. Confirm no collision with existing `/api/zgw/{...}` patterns (the literal `openapi` segment must not be swallowed by parameterized routes — check route ordering).

## 3. Conformance + unit tests

- [x] 3.1 Add `tests/Unit/Controller/ZgwOpenApiConformanceTest.php`: parse `appinfo/routes.php` (regex over the ZGW block or include-and-inspect, following whatever pattern existing tests use to read routes — check tests/Unit for precedent), collect all `/api/zgw/...` path+verb pairs (excluding the two new discovery routes), load the six YAML docs, and assert set equality in both directions. Normalize `{param}` names when comparing.
- [x] 3.2 Add `tests/Unit/Controller/ZgwOpenApiControllerTest.php`: index lists six APIs with spec URLs; spec() returns YAML content-type for a known id; unknown id → 404; controller methods carry PublicPage + NoCSRFRequired attributes (reflection).
- [x] 3.3 Run the unit suite the CI way: check how PHPUnit runs standalone (phpunit-unit.xml) and run `vendor/bin/phpunit -c phpunit-unit.xml --filter ZgwOpenApi` from the worktree; then run the FULL unit config to catch regressions. Compare failures against a pre-existing-failure baseline (run the full suite once on the unchanged tree first if needed).

## 4. Verify

- [x] 4.1 `openspec validate zgw-openapi-publication` passes.
- [x] 4.2 PHP lint: `composer lint` or `php -l` on the new controller; PHPCS on new files if `composer check` scripts exist (note any pre-existing violations separately).

## Acceptance Criteria

- Six YAML docs cover the full routed ZGW surface; conformance test enforces both directions.
- Discovery endpoints public, allow-listed, 404 on unknown ids.
- New PHPUnit tests pass; no new failures vs baseline in the full unit run.

## Quality Checklist

- SPDX + @spec tags on the new controller per repo convention.
- No new composer dependencies (use symfony/yaml only if already in vendor; otherwise parse YAML in tests via a lightweight existing dependency — check composer.lock first).
- Route auth attributes match semantics (public docs only).
- No path traversal in spec(): strict allow-list.
