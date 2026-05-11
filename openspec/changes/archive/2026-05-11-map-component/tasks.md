# Tasks: map-component

## Deduplication Check

- [ ] **D01**: Verify no `MapComponent.vue` (or similarly-named wrapper) already exists in `src/components/` — `grep -r "MapComponent" src/` and `find src/ -iname "*map*.vue"`. Document finding: existing `src/views/CaseMapView.vue` is being deleted by `case-map-overview`; the dashboard `CaseMapWidget.vue` currently calls Leaflet directly and is the target consumer of this new component.
- [ ] **D02**: Confirm `case-location` and `case-map-overview` changes land before this one so `case.geometry`, the `mapFormatters` registry, and `caseMarkerFormatter` exist. Document finding: this change depends on REQ-LOC-* and REQ-CMO-* infrastructure.

---

## Implementation Tasks

### Component

- [ ] **T01**: Create `src/components/map/MapComponent.vue` as a Vue 2 SFC that wraps `CnMapWidget` from `@conduction/nextcloud-vue` (≥ beta.30). Implement the full prop surface from `design.md` (`locations`, `center`, `zoom`, `interactive`, `markerFormatter`, `tileLayer`, `clustering`, `height`) with defaults matching the design table. Emit `marker-click`, `viewport-change` (debounced 200 ms), and `ready` per the event table. No hardcoded hex; no inline styles that override NL Design System tokens. Add JSDoc on the props block. Add `role="application"` / `role="img"` switching based on `interactive` prop, with i18n labels `map.aria-label` and `map.aria-label-readonly` from `src/l10n/`.

### Registration

- [ ] **T02**: Register `MapComponent` in the `customComponents` registry in `src/main.js` under the name `MapComponent` (mirror the registration pattern used by `widgetComponents` and `mapFormatters`). This makes the component referenceable by name from manifest entries and from other Procest components without explicit imports.

### Case Detail Integration

- [ ] **T03**: In `src/views/cases/components/CaseMapTab.vue` (or create it if missing as part of this change), replace any direct Leaflet / `CnMapWidget` usage with `<MapComponent :locations="[caseObj]" :interactive="true" @marker-click="..." />`. Wire `viewport-change` to a local component-state cache keyed on `caseObj.id` so that toggling tabs preserves the last viewport for that case. Remove any inline marker creation code.

### Dashboard Widget Integration

- [ ] **T04**: In `src/views/dashboard/CaseMapWidget.vue`, replace the existing direct Leaflet/`CnMapWidget` block with `<MapComponent :locations="filteredCases" :clustering="true" @marker-click="onPinClick" />`. `onPinClick` SHALL navigate to `/cases/:id` using `$router.push({ name: 'CaseDetail', params: { id: payload.location.id } })`. Delete any duplicated formatter or palette imports from the widget — they now live in the formatter referenced by `MapComponent`.

### Public Case Page Integration

- [ ] **T05**: In `src/views/public/PublicCaseView.vue` (create the read-only public case view file structure if not yet present), embed `<MapComponent :locations="[caseObj]" :interactive="false" :clustering="false" />`. No event handlers — the public page is read-only. Confirm the `role="img"` branch is taken and no pan/zoom controls render. Public access path is `/public/case/:token`.

---

## Verification Tasks

- [ ] **V01**: Mount `MapComponent` in isolation (unit test or Storybook-style page) with three `locations`: one Point, one Polygon (renders centroid), one with `geometry: null` (skipped). Assert exactly two markers render and clicking one emits `marker-click` carrying both the formatter output and the original object.
- [ ] **V02**: Embed the component in the case detail map tab and confirm: tile layer is PDOK BRT, default centre is the case's marker (not Netherlands default, because the formatter sets initial bounds), keyboard arrow keys pan the map, and `Tab` reaches the marker.
- [ ] **V03**: Embed in the dashboard widget with 50 seed cases; confirm clustering is active at zoom 8, individual pins appear at zoom 14, and clicking a pin navigates to `/cases/:id`.
- [ ] **V04**: Embed in the public case page with `interactive: false`; confirm: zoom controls are hidden, dragging the map does not pan, arrow keys do not pan, `role="img"` is present on the map container, and `aria-label` reads the read-only i18n string in both NL and EN locales. Run `axe-core` against the page — zero violations.
