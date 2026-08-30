# Tasks: case-location

## Implementation Tasks

### Schema & Configuration

- [ ] **T01**: Add a `location` schema to `lib/Settings/procest_register.json` with `slug: location`, `x-schema-org-type: schema:Place`, properties as defined in `design.md` (`case`, `nummeraanduidingId`, `formattedAddress`, `latitude`, `longitude`, `parcelId`, `accuracyRadius`, `source`, `label`), and a `required` list of `[case, source]`. Add the `case_location_schema` config key to `lib/Service/SettingsService.php` so the schema UUID is resolvable at runtime. Update `lib/Repair/EnsureProcestSchemas.php` to ensure the schema exists on install/update.

- [ ] **T02**: Document the case ↔ location relation in `procest_register.json` — the `case` property on `location` is the back-reference; the `case` schema is NOT modified in this change (no new property on `case`). Confirm that 0..N locations can hang off a single case purely through OpenRegister object-store queries (no explicit relation array on the case).

### Backend: PDOK Client

- [ ] **T03**: Create `lib/Service/PdokLocatieserverClient.php` wrapping the PDOK Locatieserver v3 endpoints `/suggest`, `/lookup`, and `/reverse`. The client MUST cache `/lookup` and `/reverse` responses in APCu for 24 hours keyed on the input string. When the `openconnector_source` setting is populated, the client MUST route requests through OpenConnector instead of calling PDOK directly.

### Backend: Service & Controller

- [ ] **T04**: Create `lib/Service/LocationService.php` with: `listForCase(caseId)`, `create(caseId, payload)`, `update(locationId, payload)`, `delete(locationId)`, `validate(payload)` (the cross-source rules from `design.md` §Validation Rules), and `reverseGeocode(lat, lng)` (returns `formattedAddress` + best-match `nummeraanduidingId` within 25 m, or null). All persistence MUST go through the OpenRegister object store via the `case_location_schema` config key — no direct DB writes.

- [ ] **T05**: Create `lib/Controller/LocationController.php` exposing the eight routes from `design.md` §API Surface. Suggest and reverse routes are pure pass-throughs to the PDOK client. Import preview / commit are wired in T07.

### Frontend: Case Detail Component

- [ ] **T06**: Build `src/views/cases/components/CaseLocationsTab.vue` (and the `LocationCard.vue` + `LocationEditDialog.vue` children) using the Options-API `createObjectStore` pattern. The tab MUST render in `CaseDetail.vue` as the "Locaties" tab. The edit dialog MUST integrate PDOK autocomplete via `/api/locations/suggest` and MUST show a map preview using the existing `map-component` capability.

### Admin Import / Export

- [ ] **T07**: Build the admin import flow: `lib/Command/ImportCaseLocations.php` (OCC command), the `/import/preview` + `/import/commit` controller endpoints, and `src/views/settings/tabs/LocationImportTab.vue` (CSV upload, dry-run report, commit button). The dry-run MUST report per-row outcomes (`ok`, `bag-not-found`, `case-not-found`, `missing-required`) without writing anything.

- [ ] **T08**: Add a CSV export endpoint `GET /api/locations/export?format=csv` and a button in `LocationImportTab.vue`. Shapefile export is OUT OF SCOPE for this change — file a follow-up issue when this task lands.

## Verification Tasks

- [ ] **V01**: Saving a location with `source = bag` and an unknown `nummeraanduidingId` MUST be rejected with a 422 response carrying a `nummeraanduidingId.not-found` error code; no row is persisted.
- [ ] **V02**: Saving a location with `source = pdok-reverse` and valid coordinates MUST result in a persisted row whose `formattedAddress` and (when matched) `nummeraanduidingId` are populated by the service, not by the request body.
- [ ] **V03**: Importing a CSV in preview mode against 50 rows where 47 are valid MUST return a report with 47 `ok` and 3 categorised errors, AND MUST NOT create any location objects.
- [ ] **V04**: A case with 3 locations MUST surface all 3 in the `CaseLocationsTab` in the order they were created; deleting one MUST leave the other two intact and visible without a page reload.
