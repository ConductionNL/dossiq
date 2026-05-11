<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: case-map-overview

## Deduplication Check

- [ ] **D01**: Verify no other manifest page already uses `type: 'map'` — `grep '"type": "map"' src/manifest.json`. Document finding: "No overlap — `CaseMap` is currently `type: 'custom'`."
- [ ] **D02**: Confirm `case-location` change (REQ-LOC-*) lands first so `case.geometry` field is in use across seed data; reference its PR in this PR description. Document finding: "Depends on `case-location`; geometry field already exists in the schema."

---

## Implementation Tasks

### Manifest

- [ ] **T01**: In `src/manifest.json`, replace the `CaseMap` page entry. Change `type` from `"custom"` to `"map"`, remove `"component": "CaseMapView"`, and add the full `config` block: `register: "procest"`, `schema: "case"`, `geometryField: "geometry"`, `filters: ["status", "caseType", "assignee", "deadlineRange"]`, `marker.formatter: "caseMarkerFormatter"`, `clustering: { enabled: true, disableAtZoom: 14 }`, `tileLayer: "pdok-brt"`, `sidebar: { enabled: true, filtersOpen: true }`. Validate against `app-manifest.schema.json` from `nextcloud-vue` beta.30 (must not error).

### Marker Formatter

- [ ] **T02**: Create `src/services/mapFormatters.js` exporting `caseMarkerFormatter(caseObj)` that returns `{ lat, lon, color, icon, popup, onClick }`. Handle three geometry cases: (a) `{type:'Point', coordinates:[lon,lat]}` → use coords directly; (b) `{type:'Polygon', coordinates:[[...]]}` → arithmetic-mean centroid; (c) missing/invalid geometry → return `null` so the lib skips the pin. Set `popup` to `{ title: caseObj.title, subtitle: caseObj.identifier, status: caseObj.status }` and `onClick` to `{ route: 'CaseDetail', params: { id: caseObj.id } }`. Register the formatter in `src/main.js` via the lib's `app.config.globalProperties.$mapFormatters` (mirror existing `widgetComponents` registration pattern).

### Status Palette

- [ ] **T03**: Create `src/services/caseStatusPalette.js` exporting `statusColor(status)` and `statusIcon(status)` functions. Map: `open` → `var(--color-status-info)` + `map-marker`; `in_progress` → `var(--color-status-warning)` + `progress-clock`; `blocked` → `var(--color-status-error)` + `alert-circle`; `closed` → `var(--color-text-maxcontrast)` + `check-circle`. No hardcoded hex anywhere — only CSS-variable tokens from NL Design System. Import these helpers into `caseMarkerFormatter`.

### Pin Click Handler

- [ ] **T04**: Verify the lib's `CnMapPage` dispatches `vue-router` push for `onClick: { route, params }` shape. If the lib instead emits a `pin:click` event, add an `@pin:click` handler stub in `src/main.js` mount config that calls `router.push({ name: 'CaseDetail', params: { id: payload.id } })`. Document the chosen route in the spec scenario CMO-04 G/W/T.

### Filter Sidebar

- [ ] **T05**: Confirm `CnMapPage` reads `config.filters` as filter ids and resolves their UI from the same `filterRegistry` used by `type: 'index'` pages. If filter ids must be migrated, copy the four filter definitions (`status`, `caseType`, `assignee`, `deadlineRange`) from the `Cases` index page in `src/manifest.json` into a shared `filters` block at manifest root (per beta.30 schema). Verify changing a filter triggers a re-query and re-renders pins without resetting map viewport (center + zoom must be preserved).

### Clustering

- [ ] **T06**: Confirm `leaflet.markercluster` is transitively bundled by `@conduction/nextcloud-vue` beta.30; if not, add it to `package.json` `dependencies` and let the lib pick it up via peer. Verify cluster icon styles inherit the status palette (cluster color = most severe child status). No app-side CSS overrides needed unless cluster icons render unstyled.

### Cleanup

- [ ] **T07**: Delete `src/views/CaseMapView.vue` and its route registration in `src/router.js` (if present outside the manifest auto-router). Remove any `import` statements referencing it across `src/`. Run `npm run lint && npm run build` to confirm zero dangling references.

### Empty State

- [ ] **T08**: Confirm `CnMapPage` renders `NcEmptyContent` automatically when the query returns zero results. If it does not, add an `emptyState: { icon: 'icon-address', title: 'Geen zaken op de kaart', body: 'Pas filters aan of voeg locaties toe aan bestaande zaken.' }` block to the manifest `config`. Provide English variant via the i18n translation file.

---

## Verification

- [ ] **V01**: Navigate to `/map` in the dev environment — page renders, default tile layer is PDOK BRT, and pins appear for all seed cases that have `geometry` set (verify against `case-location` seed objects). No console errors.
- [ ] **V02**: Click a pin → router navigates to `/cases/:id` and the case detail loads. Click the back button → returns to map with viewport preserved.
- [ ] **V03**: Apply filter "status = open" in the sidebar → only blue pins remain; switch to "status = closed" → only grey pins. Filter combinations (status + assignee) reduce pin set accordingly.
- [ ] **V04**: Seed 5,000 synthetic cases with random geometry inside the Netherlands bbox; load `/map` → initial paint < 2s (measure via Chrome DevTools Performance panel "Largest Contentful Paint"); cluster icons visible at zoom 8; individual pins at zoom 14+.
