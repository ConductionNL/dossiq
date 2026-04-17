<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: case-location

## Deduplication Check

- [ ] **D01**: Verify no existing map/geo component exists in `src/` — search for `leaflet`, `map`, `geometry`, `pdok` across all `.vue` and `.js` files. Document finding: "No overlap found — no existing location UI components in Procest."
- [ ] **D02**: Verify `case.geometry` field exists in `lib/Settings/procest_register.json` under the `case` schema and that no separate location entity is introduced. Document finding: "Reusing existing field — no new schema created."

---

## Implementation Tasks

### Dependencies

- [ ] **T01**: Add npm dependencies to `package.json` and install — `leaflet` (map rendering), `leaflet-draw` (polygon tool). Import leaflet CSS in the component that mounts the map (`import 'leaflet/dist/leaflet.css'`). Verify both packages appear in `package.json` under `dependencies` (not `devDependencies`). Run `npm ci && npm run lint` to confirm no import errors.

### Schema & Configuration

- [ ] **T02**: Add `requiresLocation` boolean to the `caseType` schema in `lib/Settings/procest_register.json`. Insert under `caseType.properties`:
  ```json
  "requiresLocation": {
    "type": "boolean",
    "default": false,
    "description": "Whether cases of this type require a location to be set before saving",
    "x-translatable": false
  }
  ```
  This is a non-breaking optional addition. No migration required.

### Seed Data

- [ ] **T03**: Update `lib/Settings/procest_register.json` to add geometry values to existing case seed objects and set `requiresLocation: true` on the "Omgevingsvergunning" case type seed object. Add or update these seed entries (use `@self` envelope, slug-based idempotency):

  - Case: "Omgevingsvergunning Keizersgracht 100" — geometry `{"type":"Point","coordinates":[4.8897,52.3702]}`
  - Case: "Subsidieaanvraag Utrecht 2026-001" — geometry `{"type":"Point","coordinates":[5.1214,52.0907]}`
  - Case: "Melding openbare ruimte Vondelpark" — polygon geometry covering the Vondelpark area
  - Case: "Handhaving Prinsengracht 250" — geometry `{"type":"Point","coordinates":[4.8843,52.3747]}`
  - Case: "Klacht behandeling 2026-001" — no geometry (tests the empty state)
  - caseType: "Omgevingsvergunning" — set `requiresLocation: true`

  Verify idempotency: re-running `importFromApp()` must not create duplicates (match by slug).

### Frontend: PDOK Service

- [ ] **T04**: Create `src/services/pdokLocatieserver.js` — Export two functions:

  1. `suggest(query, rows = 5)` — GET `https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest?q={query}&rows={rows}`. Returns array of `response.docs[]`. Each doc has `weergavenaam` (display string) and `centroide_ll` (WKT POINT format: `"POINT(lon lat)"`). Parse `centroide_ll` into `{ lat, lon }` before returning. Debounce is handled in the component (NOT in this service).

  2. `reverseGeocode(lat, lon)` — GET `https://api.pdok.nl/bzk/locatieserver/search/v3_1/reverse?lat={lat}&lon={lon}&rows=1`. Returns `weergavenaam` string of the nearest BAG address, or `null` if no result.

  Both use `axios` from `@nextcloud/axios`. Add SPDX header: `// SPDX-License-Identifier: EUPL-1.2`.

### Frontend: CaseLocationTab Component

- [ ] **T05**: Create `src/views/cases/tabs/CaseLocationTab.vue` — Displays case location, keyed on `case.geometry`. Two states:

  **State A (geometry is set):**
  - Left 60%: read-only Leaflet map (`id="case-location-map"`). Mount in `mounted()` with `L.map()`. Base tile: PDOK BRT Achtergrondkaart (`https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png`, attribution: "© PDOK / Kadaster"). Parse `geometry` from `case.geometry` (JSON.parse). If `type === 'Point'`: add `L.marker([lat, lon])`, set view to `[lat, lon]` zoom 16. If `type === 'Polygon'`: add `L.polygon(coords)`, call `map.fitBounds()` with 20px padding; compute area using `L.GeometryUtil.geodesicArea()` from `leaflet.geometryutil`.
  - Right 40%: address sidebar — on mount call `pdokLocatieserver.reverseGeocode()` for point coordinates (or polygon centroid = arithmetic mean of vertices). Display `weergavenaam`. For polygon: prefix with "Nabij: ". If polygon: also show area in m² (format with `toLocaleString('nl-NL')`). Cache result in `data.cachedAddress`.
  - "Locatie wijzigen" `NcButton` → `$emit('open-picker')`

  **State B (no geometry):**
  - `NcEmptyContent` with location icon, title `t('procest', 'Geen locatie ingesteld')`, subtitle `t('procest', 'Voeg een locatie toe aan deze zaak')`.
  - "Locatie toevoegen" `NcButton` (variant="primary") → `$emit('open-picker')`

  Props: `caseObject` (Object, required) — full case object with `geometry` string field.
  Emits: `open-picker`.
  Add SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`.
  All user-visible strings via `this.t('procest', '...')`.
  Scoped `<style>` block using only Nextcloud CSS variables.

### Frontend: LocationPicker Component

- [ ] **T06**: Create `src/views/cases/tabs/LocationPicker.vue` — Modal dialog for picking or drawing a location. Use `NcDialog` from `@conduction/nextcloud-vue`.

  **Map initialization** (`mounted()`): create Leaflet map inside the dialog's map container. Apply same PDOK BRT base tile as CaseLocationTab. If `currentGeometry` prop is set: show existing geometry and center map on it. Otherwise: center at Netherlands center `[52.1551744, 5.3850548]`, zoom 8.

  **Address search:**
  - `NcTextField` input, `v-model="searchQuery"`, `@input` handler debounced 300ms using `setTimeout`/`clearTimeout`.
  - On debounce fire: call `pdokLocatieserver.suggest(searchQuery)`. Render results as `NcListItem` in a dropdown below the input.
  - On result select: parse `centroide_ll` WKT → `[lon, lat]`, call `map.setView([lat, lon], 16)`, place/update marker, clear search.

  **Point mode (default):**
  - `map.on('click', handler)` — places/moves `L.marker()` at click coordinates.
  - Display lat/lon below map (formatted to 6 decimal places).

  **Polygon mode:**
  - "Gebied tekenen" `NcButton` toggles Leaflet Draw's polygon tool (`new L.Draw.Polygon(map).enable()`).
  - `map.on(L.Draw.Event.CREATED, handler)` — receives the polygon layer, computes area with `L.GeometryUtil.geodesicArea()`, displays area below map.
  - Double-click closes polygon (default Leaflet Draw behaviour).

  **Actions:**
  - "Opslaan" `NcButton` (variant="primary"): serialize current geometry to GeoJSON string (`JSON.stringify(geojson)`), `$emit('save', geojson)`, close dialog.
  - "Annuleren" `NcButton`: `$emit('close')` without saving.

  Props: `show` (Boolean), `currentGeometry` (String, optional — existing GeoJSON string).
  Emits: `save(geojsonString)`, `close`.
  SPDX header. Scoped styles. All strings via `this.t('procest', '...')`.

### Frontend: CaseDetail Integration

- [ ] **T07**: Update `src/views/cases/CaseDetail.vue` to add the "Locatie" tab:

  1. Import `CaseLocationTab` from `./tabs/CaseLocationTab.vue` and `LocationPicker` from `./tabs/LocationPicker.vue`. Register both in `components: {}`.
  2. Add a tab entry `{ id: 'location', label: t('procest', 'Locatie'), icon: 'MapMarker' }` to the tabs array (after existing tabs).
  3. In the tab panel for `location`: render `<CaseLocationTab :caseObject="currentCase" @open-picker="showLocationPicker = true" />`.
  4. Add `<LocationPicker :show="showLocationPicker" :currentGeometry="currentCase.geometry" @save="onLocationSave" @close="showLocationPicker = false" />`.
  5. Add `data` property `showLocationPicker: false`.
  6. Add method `onLocationSave(geojsonString)`: call `try { await caseStore.saveObject(register, schema, { ...currentCase, geometry: geojsonString }); showLocationPicker = false; } catch (e) { /* NcDialog error */ }`.

  SPDX header must already exist — verify, add if missing. All new strings via `this.t('procest', '...')`.

### Frontend: Case Creation Integration

- [ ] **T08**: Update the case creation form (typically `src/views/cases/CaseCreate.vue` or the new case dialog component) to add an optional "Locatie" section:

  1. Import `LocationPicker` and register in `components: {}`.
  2. Add a collapsible "Locatie (optioneel)" section below the required fields, using `NcButton` toggle to expand.
  3. When expanded: render an inline `LocationPicker`-style map (or a compact version with address search and click-to-place). Alternatively, show a "Locatie instellen" `NcButton` that opens the full `LocationPicker` modal.
  4. Store selected geometry in `data.newCaseGeometry`.
  5. On form submit: include `geometry: this.newCaseGeometry` (or `null`) in the case payload.

### Frontend: Case Type Requires-Location Validation

- [ ] **T09**: In the case creation form's submit handler, add validation for `requiresLocation`:

  1. After the user clicks "Opslaan", before calling `caseStore.saveObject()`:
  2. Check if `selectedCaseType.requiresLocation === true && !this.newCaseGeometry`.
  3. If so: show `NcDialog` with message `t('procest', 'Dit zaaktype vereist een locatie')`. Do NOT call saveObject. Focus the location section.
  4. Wrap the saveObject call in `try { await ... } catch { show error dialog }` per ADR-015 pattern.

---

## Verification Tasks

- [ ] **V01**: Case with Point geometry shows "Locatie" tab with Leaflet map centered on the coordinates and a visible marker
- [ ] **V02**: Case with no geometry shows "Geen locatie ingesteld" empty state and "Locatie toevoegen" button
- [ ] **V03**: Case with Polygon geometry shows the polygon rendered on the map with `fitBounds`, and area in m² in the sidebar
- [ ] **V04**: Reverse geocoding displays "Keizersgracht 100, 1015 AA Amsterdam" for coordinates `[4.8897, 52.3702]`
- [ ] **V05**: Polygon reverse geocode displays "Nabij: [address]" prefix
- [ ] **V06**: Address search "Keizersgracht 100 Amsterdam" returns autocomplete suggestions within 300ms
- [ ] **V07**: Selecting a PDOK suggestion centers the map and places a marker
- [ ] **V08**: Drawing a polygon with leaflet-draw and clicking "Opslaan" saves the polygon GeoJSON to `case.geometry`
- [ ] **V09**: Case creation form shows optional location section; geometry is saved with the case
- [ ] **V10**: Saving a new case of type "Omgevingsvergunning" (requiresLocation: true) without geometry shows "Dit zaaktype vereist een locatie" and blocks save
- [ ] **V11**: Seed data is visible after install — Omgevingsvergunning Keizersgracht case shows location on map immediately
- [ ] **V12**: No hardcoded Dutch/English strings in templates — all via `this.t('procest', '...')`
- [ ] **V13**: All new `.vue` files have SPDX header and scoped `<style>` blocks
- [ ] **V14**: No `@nextcloud/vue` direct imports — all from `@conduction/nextcloud-vue`
- [ ] **V15**: `npm run lint` passes with leaflet packages in `package.json`
