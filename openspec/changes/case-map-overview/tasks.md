# Tasks: case-map-overview

## Pre-Implementation

- [ ] **T00 — Deduplication check**: Confirm no overlap with existing GIS functionality. Search `openspec/specs/` and `src/components/map/` for duplicate spatial-selection or wijk-selection implementations. **Finding**: `SpatialFilter.vue` covers rectangle/polygon draw but lacks wijk WFS selection and area display. `CaseMapView.vue`, `CaseMap.vue`, `CasePopup.vue`, `CaseMapWidget.vue`, and `gis.js` are all already built. Only 3 items remain unimplemented (sidebar, area display, wijk selection). No duplication risk.

---

## Implementation Tasks

### Seed Data

- [ ] **T01 — Add seed objects to `procest_register.json`**:
  Add a `components.objects` section (or merge into existing if present). Include:
  - 5 `mapLayer` objects (slugs: `layer-pdok-brt-standaard`, `layer-pdok-brt-grijs`, `layer-pdok-luchtfoto`, `layer-cbs-wijken`, `layer-bag-panden-wms`) — see `design.md` Seed Data section for full field values
  - 5 `case` objects with `geometry` (GeoJSON Points in Amsterdam, Rotterdam, Utrecht, Den Haag, Eindhoven) — see `design.md` Seed Data section for full field values
  - Use `@self` envelope with `register: "procest"` and unique `slug` per object
  - Verify idempotency: re-importing MUST NOT create duplicates (match by slug)

### Frontend: SpatialSelectionSidebar

- [ ] **T02 — Create `src/views/cases/components/SpatialSelectionSidebar.vue`**:
  - `<!-- SPDX-License-Identifier: EUPL-1.2 -->` header
  - Props: `cases` (Array, required), `wijkName` (String, default null), `wijkCode` (String, default null), `visible` (Boolean, default false)
  - Emits: `close`, `case-click` (case id)
  - Template: slide-in panel (position fixed, right side). Header shows wijk name+code if provided, else generic "Geselecteerde zaken". Count badge. Scrollable case list — each row: title, identifier chip, status badge (`CnStatusBadge`). "Sluit selectie" button (emits `close`).
  - Bulk actions section: show only if `$store.settings.userCanBulkEdit` — placeholder for future use, render as disabled bar
  - Import from `@conduction/nextcloud-vue` (NOT `@nextcloud/vue`)
  - ALL user-visible strings via `this.t('procest', '...')`. Translation keys in English.
  - `<style scoped>` using only `var(--color-*)` Nextcloud CSS variables

### Frontend: SpatialFilter additions

- [ ] **T03 — Add polygon area display to `SpatialFilter.vue`**:
  - After `L.Draw.Event.CREATED` fires for a polygon, calculate area:
    ```js
    import L from 'leaflet'
    import 'leaflet-geometryutil' // or use L.GeometryUtil if already available
    const latLngs = e.layer.getLatLngs()[0]
    const areaSqm = Math.abs(L.GeometryUtil.geodesicArea(latLngs))
    this.areaDisplay = areaSqm < 10000
      ? `${Math.round(areaSqm).toLocaleString('nl-NL')} m²`
      : `${(areaSqm / 10000).toFixed(2)} ha`
    ```
  - Add `areaDisplay` to `data()` (default `null`)
  - Render below tools toolbar: `<p v-if="areaDisplay" class="spatial-filter__area">{{ areaDisplay }}</p>`
  - Clear `areaDisplay` in `clearSelection()`
  - Fix import: change `NcButton` import from `@nextcloud/vue` → `@conduction/nextcloud-vue`

- [ ] **T04 — Add wijk/buurt selection to `SpatialFilter.vue`**:
  - Add `activeMode === 'wijk'` button: `t('procest', 'Select district')`
  - On wijk mode activate:
    1. Import `useGisStore` from `../../store/modules/gis.js`
    2. Find the WFS wijken layer: `gisStore.layers.find(l => l.layerType === 'wfs' && l.layers?.includes('wijk'))`
    3. Fetch wijk GeoJSON (if `proxyEnabled`: POST to `/api/gis/proxy` with the WFS GetFeature URL; else fetch directly)
    4. Add a `L.GeoJSON` layer to the map with `style` from `layer.style` and `onEachFeature` that adds a click handler
    5. On feature click: extract `wijknaam` and `wijkcode` from feature properties; emit `selection-change` with the feature geometry; emit `wijk-selected({ name, code, geometry })`
    6. Store the wijk layer as `this.wijkLayer` for cleanup in `clearSelection()` and `beforeDestroy()`
  - `clearSelection()` must also remove `wijkLayer` from map and set `this.wijkLayer = null`
  - Emit `wijk-selected(null)` when clearing in wijk mode

- [ ] **T05 — Wire SpatialSelectionSidebar into `CaseMapView.vue`**:
  - Import and register `SpatialSelectionSidebar`
  - Add `data()` fields: `spatialSelectedCases: []`, `wijkName: null`, `wijkCode: null`
  - Handle `@selection-change` from `SpatialFilter`: run point-in-polygon filter on `filteredCases` using the emitted geometry; set `spatialSelectedCases`
  - Handle `@wijk-selected` from `SpatialFilter`: set `wijkName` and `wijkCode`
  - Handle `@clear` from `SpatialFilter`: reset `spatialSelectedCases`, `wijkName`, `wijkCode`
  - Render `<SpatialSelectionSidebar :cases="spatialSelectedCases" :wijk-name="wijkName" :wijk-code="wijkCode" :visible="spatialSelectedCases.length > 0" @close="onSpatialClear" @case-click="onCaseSidebarClick" />`
  - `onCaseSidebarClick(id)`: `this.$router.push({ name: 'CaseDetail', params: { id } })`

### i18n

- [ ] **T06 — Add translation keys to `l10n/en.json` and `l10n/nl.json`**:

  New keys required (English source):

  | Key | nl translation |
  |-----|----------------|
  | `Select district` | `Selecteer wijk` |
  | `Selected cases` | `Geselecteerde zaken` |
  | `Close selection` | `Sluit selectie` |
  | `{count} cases selected` | `{count} zaken geselecteerd` |
  | `{area} selected area` | `{area} geselecteerd gebied` |
  | `District: {name}` | `Wijk: {name}` |
  | `Code: {code}` | `Code: {code}` |

  Verify all existing keys in `CaseMapView.vue`, `SpatialFilter.vue`, `CaseMapWidget.vue`, `CasePopup.vue` are already present in both `l10n/en.json` and `l10n/nl.json`.

---

## Verification Tasks

- [ ] **V01 — Map loads with PDOK base tiles**: Navigate to `/map`; map displays with PDOK BRT Achtergrondkaart tiles; no 404 errors in console.
- [ ] **V02 — Cases with geometry appear as markers**: 5 seed cases appear on the map at correct Dutch coordinates.
- [ ] **V03 — Marker clustering at low zoom**: Zoom out to level 7 (Netherlands overview); markers cluster into count bubbles.
- [ ] **V04 — Status-based marker colors**: Each seed case has the correct color; legend matches.
- [ ] **V05 — Popup shows required fields**: Click a marker; popup shows title, identifier, status badge, assignee, case type, "Bekijk zaak" link.
- [ ] **V06 — Filter by case type**: Select a case type; only matching cases remain; cluster counts update.
- [ ] **V07 — Filter by status toggle**: Toggle off "Closed"; green markers disappear.
- [ ] **V08 — Mijn zaken filter**: Activate "My cases"; only cases assigned to the logged-in user are shown.
- [ ] **V09 — Combined filters badge**: Activate case type filter + my-cases toggle; badge shows "2 filters actief".
- [ ] **V10 — Rectangle selection**: Activate "Gebied selecteren", draw rectangle; `SpatialSelectionSidebar` appears listing selected cases.
- [ ] **V11 — Polygon area display**: Draw polygon; area in m² or ha appears below toolbar.
- [ ] **V12 — Wijk selection tool**: Activate "Selecteer wijk" (requires CBS wijken mapLayer seed); click a wijk polygon; sidebar shows wijk name and code in header; cases within wijk appear in sidebar.
- [ ] **V13 — Dashboard widget renders**: Dashboard shows `CaseMapWidget`; only current user's cases appear; map is at least 400 px tall.
- [ ] **V14 — Dashboard widget navigation**: Click marker popup "Bekijk zaak"; navigates to case detail.
- [ ] **V15 — Seed data idempotency**: Re-import `procest_register.json` via repair step; no duplicate mapLayer or case objects created.
- [ ] **V16 — SPDX headers**: Run `grep -rL 'SPDX-License-Identifier' src/views/cases/components/SpatialSelectionSidebar.vue src/components/map/SpatialFilter.vue`; both files must have headers.
- [ ] **V17 — No @nextcloud/vue imports**: Run `grep -rn "from '@nextcloud/vue'" src/views/cases/components/SpatialSelectionSidebar.vue src/components/map/SpatialFilter.vue`; must return zero matches.
