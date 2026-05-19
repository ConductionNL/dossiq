# GIS Integration — Task List

**Change:** gis-integration | **Issue:** #462

## Already implemented (pre-existing)

- [x] task-1: `location` schema in `lib/Settings/procest_register.json`
- [x] task-2: `wmsLayer` + `mapLayer` schemas in `lib/Settings/procest_register.json`
- [x] task-3: `PdokLocatieserverService` — PDOK suggest/lookup/reverse geocoding
- [x] task-4: `PdokBagService` — BAG nummeraanduiding/verblijfsobject/pand lookup
- [x] task-5: `LocationService` — validate, reverseGeocode, attachToCase, listForCase
- [x] task-6: `GisProxyService` + `GisProxyController` — proxy with allowlist + rate limit
- [x] task-7: `WmsWfsService` + `WmsWfsController` — per-layer WMS/WFS proxy
- [x] task-8: Routes for GIS proxy + WMS/WFS proxy in `appinfo/routes.php`
- [x] task-9: Pinia gis store (`src/store/modules/gis.js`)
- [x] task-10: `MapComponent.vue`, `CaseMap.vue`, `CasePopup.vue`, `MapLegend.vue`
- [x] task-11: `LocationPicker.vue`, `AddressSearch.vue` (PDOK autocomplete)
- [x] task-12: `MapLayerSwitcher.vue`, `SpatialFilter.vue`
- [x] task-13: `LocationTab.vue` — case detail location management tab
- [x] task-14: `MapLayerSettings.vue` — admin layer configuration
- [x] task-15: `CaseMapWidget.vue` — dashboard widget
- [x] task-16: `CaseMap` manifest page (type: map, filters: status/caseType/assignee/deadlineRange, clustering)
- [x] task-17: `GisProxyControllerTest` + `GisProxyServiceTest` — existing unit tests

## New implementation required

- [x] task-18: `WfsExportService` — fetch case locations and format as GeoJSON FeatureCollection (`lib/Service/WfsExportService.php`)
- [x] task-19: `WfsExportController` — GET `/api/gis/wfs` WFS endpoint for external GIS apps (`lib/Controller/WfsExportController.php`)
- [x] task-20: WFS export routes in `appinfo/routes.php`
- [x] task-21: `WfsExportControllerTest` — unit test for WFS export controller
- [x] task-22: `WfsExportServiceTest` — unit test for WFS export service
- [x] task-23: `LocationServiceTest` — unit tests for LocationService.validate(), reverseGeocode(), attachToCase(), listForCase()
- [x] task-24: `WmsWfsControllerTest` — unit tests for WmsWfsController.proxy()
