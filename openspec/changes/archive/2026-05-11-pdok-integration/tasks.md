# Tasks: pdok-integration

## Implementation Tasks

### Backend: PDOK Services

- [ ] **T01**: Create `lib/Service/Pdok/PdokLocatieserverService.php` wrapping the PDOK Locatieserver v3_1 endpoints `/suggest`, `/free`, `/lookup`, and `/reverse`. The service MUST be the single ingress for every Locatieserver call in Procest; no other class may instantiate an HTTP client targeting `api.pdok.nl/bzk/locatieserver`. When the `pdok_locatieserver_source` IAppConfig key is non-empty, outbound HTTP MUST be dispatched through that OpenConnector source. The service MUST emit a structured log line per call with method, cache hit/miss, and elapsed ms.

- [ ] **T02**: Create `lib/Service/Pdok/PdokBagService.php` exposing `getNummeraanduiding(id)`, `getVerblijfsobject(id)`, and `getPand(id)` against the PDOK BAG WFS v2_0. Responses MUST be normalised to a stable Procest-internal shape (snake → camel, `bouwjaar` always integer, `oppervlakte` always integer m2, `gebruiksdoel` always array). When `pdok_bag_source` is non-empty, route through OpenConnector. Cache hits MUST bypass the rate guard.

- [ ] **T03**: Seed the four default `MapLayer` rows defined in `design.md` §Data Model via a new `lib/Repair/SeedPdokBasemaps.php` repair step. The "BRT Achtergrondkaart" row MUST have `isDefault = true`; the "NL Design System" row MUST have `isDefault = true` ONLY when the nldesign theme is active. The repair step MUST be idempotent — re-running it MUST NOT create duplicate rows nor overwrite admin-edited titles, attributions, or order.

### Backend: Admin Settings & Controller

- [ ] **T04**: Build `src/views/settings/tabs/PdokSettingsTab.vue` and the matching `lib/Controller/PdokSettingsController.php` endpoints. The tab MUST surface every key from `design.md` §PDOK config: three endpoint URLs, three OpenConnector source slugs, two cache TTLs, one rate ceiling, and the two outage banner copies (nl + en). Saving an invalid URL (no scheme, no host) MUST be rejected client-side AND server-side. A "Test verbinding" button per service MUST call `/api/pdok/health` for that service.

- [ ] **T05**: Refactor `lib/Service/LocationService.php` (from the case-location change) to depend on `PdokLocatieserverService` via constructor injection instead of its private `PdokLocatieserverClient`. The private client class MUST be deleted in this change. The existing REQ-CL-3 scenarios MUST keep passing — verified by the case-location V01–V04 tasks running unchanged against the new service.

### Backend: Caching, Rate Limiting, Outage

- [ ] **T06**: Create `lib/Service/Pdok/PdokCache.php` (APCu wrapper) and wire it as a decorator around every method on the three services. TTL MUST come from IAppConfig (`pdok_cache_lookup_ttl_seconds`, `pdok_cache_suggest_ttl_seconds`). Cache keys MUST include the full request signature including filter (`fq`) parameters so that filtered suggests do not collide with unfiltered ones. Cache MUST be purgeable from the admin tab with a single button.

### Frontend: Consumer Wiring

- [ ] **T07**: Replace direct `fetch('https://api.pdok.nl/...')` calls in any existing Vue component with `src/services/pdokApi.js`, which targets `/api/pdok/*`. The map component MUST read its basemap list from `GET /api/pdok/basemaps` instead of carrying hard-coded URLs. The location autocomplete (case-location `LocationEditDialog.vue`) MUST switch from its private suggest call to `pdokApi.suggest(q)`.

- [ ] **T08**: Add an outage banner to `src/views/cases/components/CaseMap.vue` and `src/views/dashboard/CaseMapWidget.vue` driven by `GET /api/pdok/health`. When `health = degraded` the banner MUST be visible with the configured nl/en copy; when `health = ok` the banner MUST be hidden. The banner MUST NOT block map interaction (zoom, pan, marker clicks).

## Verification Tasks

- [ ] **V01**: With `pdok_locatieserver_source` set to a valid OpenConnector source, calling `GET /api/pdok/suggest?q=Keizersgracht` MUST result in zero direct HTTP requests to `api.pdok.nl` from the Procest container (verified via outbound HTTP audit) and a non-empty result list in the response.
- [ ] **V02**: Calling `GET /api/pdok/bag/nummeraanduiding/0363200000406567` twice within 24 hours MUST result in exactly one upstream BAG WFS request; the second call MUST be served from APCu with a `cache: hit` indicator in the response headers.
- [ ] **V03**: Simulating three consecutive Locatieserver 5xx responses within 60 s MUST flip `GET /api/pdok/health` to `degraded` for at least 5 min; during that window `GET /api/pdok/suggest` MUST return an empty array and `LocationService::create` MUST accept a payload with `source = free` that would normally have been required to be `source = bag`.
- [ ] **V04**: Running `SeedPdokBasemaps::run()` twice in a row MUST result in exactly four `MapLayer` rows with `slug` prefixed `pdok-` and the admin edits made between the two runs MUST be preserved (title, attribution, and order fields untouched on the second pass).
