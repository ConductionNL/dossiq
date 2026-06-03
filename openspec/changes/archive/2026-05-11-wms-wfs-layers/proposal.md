# Proposal: wms-wfs-layers

## Summary

Add tenant-configurable OGC WMS (Web Map Service) and WFS (Web Feature Service) overlay layers to Procest maps. Administrators register layer endpoints (URL, layer name, type, SRS, opacity, attribution, queryable) as `wmsLayer` objects in OpenRegister, then subscribe specific layers to specific case types. The shared `CaseMap` / `CnMapPage` component reads the subscription, lazily fetches tiles or features through the existing GIS proxy, and renders a legend with toggle/opacity controls. Sister capability to `map-component` (rendering) and `pdok-integration` (presets), bringing the WMS/WFS slice of the existing `map-component` data model under its own manifest-driven admin page and per-case-type association.

## Motivation

Dutch municipalities need to overlay zoning (bestemmingsplannen), environmental (Natura 2000, milieucontouren), infrastructure (kabels & leidingen) and cadastral (Kadaster) data on case maps. Today these layers are hard-coded in the `MapLayer` config or hand-toggled per user. Case handlers for an environmental permit need different overlays than handlers for a building permit. Without per-case-type layer subscriptions, every user sees every layer or none, and admins cannot tailor the map per workflow.

## Affected Projects

- [ ] Project: `procest` — `wmsLayer` schema in `procest_register.json`, admin manifest page, case-type→layer association, `WmsWfsService`, legend renderer, `CaseMap` layer prop wiring

## Scope

### In Scope

- `wmsLayer` schema with WMS/WFS-specific fields (layer-name, srs, queryable, opacity, attribution)
- Manifest-driven admin index page (`type: 'index'` on `wmsLayer`) for layer CRUD
- Per-case-type layer subscription (which layers visible for which case types)
- `WmsWfsService` client (GetCapabilities, GetMap, GetFeature via existing GIS proxy)
- `CaseMap` layer prop + legend renderer with toggle and opacity slider
- Lazy load + tile size cap performance guards

### Out of Scope

- Backend GIS proxy itself (lives in `wms-wfs-layers` parent spec REQ-LAYER-03; reused here)
- PDOK preset catalogue (lives in `pdok-integration`)
- Per-user layer preferences (future)
- WMTS-specific tile-matrix handling (base maps only, already in `map-component`)
- Vector tile / MVT support (future)

## Approach

1. Add `wmsLayer` schema (and `caseTypeLayerSubscription`) to `lib/Settings/procest_register.json`; register config keys in `SettingsService`.
2. Build `WmsWfsService` (PHP) — thin wrapper that delegates to the existing GIS proxy and parses GetCapabilities for admin assistance.
3. Author manifest `index` page for `wmsLayer` admin in `src/manifest.json` (no hand-written view).
4. Add `layerIds` (array) to the `caseType` schema and a "Kaartlagen" tab in case-type admin.
5. Extend `CaseMap` / `CnMapPage` to read `layerIds` from the active case type, fetch the subscribed layers, and render a legend with toggle + opacity.

## Risks

- WMS/WFS endpoints vary in spec compliance — must tolerate version drift (1.1.1 vs 1.3.0; 2.0.0 vs 1.0.0).
- Tile request volume can blow up under deep zoom — must enforce tile-size cap and lazy load (no fetch when layer toggled off).
- Layer subscription must not bypass the proxy URL allowlist (REQ-LAYER-03c in parent spec).
