# Tasks: migrate-cases-on-map-to-maps-overview-leaf

All tasks are in the `procest` repo. Completes the maps-leaf migration that
`migrate-maps-to-maps-leaf` deferred (issue #112), now that OR ships the
page-level maps-overview surface (openregister PR #154).

## [procest] Consume the OR maps-overview surface

### P1. Wire the cases-on-map overview to OR (M)

- [x] P1.1 Add `src/services/casesOnMapApi.js` — a thin OR maps-overview client:
  `registerCasesOnMapOverview()` (POST `/apps/openregister/api/integrations/maps/overviews`)
  and `fetchCasePoints()` (GET `.../maps/overviews/{register}/{schema}/points`,
  unwraps `{ points }`). Fail-closed: failures return `[]` / `null`, never throw.
- [x] P1.2 Add `shapeMarkerFeatures()` to `src/services/mapFormatters.js` — pure
  presentation that turns OR point rows into the GeoJSON Feature array
  `CnMapWidget` renders, with status colour/icon for the marker.
- [x] P1.3 Rewrite `src/views/CasesOnMapView.vue` to declare the overview at
  mount and render the RBAC-scoped points through the library's `CnMapWidget`
  (clustering on, marker click → case detail). No in-app Leaflet.
- [x] P1.4 Migrate the `/map` manifest page from `type: "map"` (bespoke
  marker.formatter / clustering / bboxQuery / tileLayer config) to
  `type: "custom"` → `CasesOnMapView`, forwarding `{ register, schema }`.

## [procest] Remove the bespoke multi-object Leaflet + WMS/WFS stack

### P2. Frontend removal (S)

- [x] P2.1 Remove `src/components/map/{CaseMap,MapComponent,MapLayerSwitcher,MapLegend,SpatialFilter,CasePopup,GeoViewer}.vue`.
- [x] P2.2 Remove `src/views/dashboard/CaseMapWidget.vue`, `src/views/settings/MapLayerSettings.vue`.
- [x] P2.3 Remove `src/services/{caseGeoService,coordinateService,gisProxyService}.js` and `src/store/modules/gis.js`.
- [x] P2.4 Drop the `MapComponent` / `GeoViewer` registry + `customComponents`
  entries and the `MapLayerSettings` section in `AdminRoot.vue`.

### P3. Backend removal (S)

- [x] P3.1 Remove `lib/Service/{WmsWfsService,WfsService,WfsExportService,GeoService,LocationService,MapLayerService,GisProxyService}.php`.
- [x] P3.2 Remove `lib/Controller/{WmsWfsController,WfsController,WfsExportController,CaseGeoController,GisProxyController,MapLayerController}.php`.
- [x] P3.3 Remove the geo routes from `appinfo/routes.php` (`/api/cases/geo`,
  `/wfs/cases`, `/api/gis/*`, `/api/wms-wfs/proxy`, `/api/map-layers`).
- [x] P3.4 Remove the obsolete tests (`tests/Unit/{Controller,Service}/*` for the
  removed classes, `tests/vitest/caseGeoService.spec.js`, the
  `gis-integration` Newman collection).

## [procest] Tests + closure

### P4. Coverage + spec (S)

- [x] P4.1 Add `tests/vitest/casesOnMap.spec.js` — covers `shapeMarkerFeatures`
  (marker shaping) and the OR maps-overview client (endpoint URLs, `{ points }`
  unwrapping, fail-closed degradation).
- [x] P4.2 Update the `case-map-overview` + `case-map-via-maps-leaf` specs to
  reflect OR-surface consumption and resolve the issue #112 deferral NOTE.
- [x] P4.3 Close Codeberg procest issue #112.
