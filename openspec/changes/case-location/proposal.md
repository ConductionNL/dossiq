# Case Location Specification

## Why

Cases in Procest already carry a `geometry` field (GeoJSON string) in the data model, but there is no UI for viewing or editing it. This blocks location-based workflows — particularly VTH (vergunningen, toezicht, handhaving) — where the precise address or parcel of a case is essential for processing. Tender analysis shows 1,300+ requirements across GIS/geo-viewer, PDOK integration, and location indication clusters, with ~300 unique tenders demanding integrated map capabilities in a case management system.

The `case.geometry` field exists; this change adds the UI layer to make it usable.

## What Changes

- **REQ-LOC-01**: Add a "Locatie" tab to the case detail view showing an interactive map with a marker (Point) or filled polygon, reverse-geocoded address sidebar, and empty-state CTA when no geometry is set
- **REQ-LOC-02**: Add a location picker modal (CnFormDialog-based) with PDOK Locatieserver address search (autocomplete ≤ 300ms), click-to-place-marker, and polygon drawing tool
- **REQ-LOC-03**: Add reverse geocoding from case geometry to human-readable BAG address via PDOK Locatieserver reverse API, with centroid calculation for polygons
- **REQ-LOC-04**: Add optional location section to the case creation form, with validation that blocks saving when the case type requires a location

## Capabilities

### New Capabilities
- `case-location-tab`: Locatie tab in case detail with embedded Leaflet map
- `case-location-picker`: Address search + map click + polygon draw picker modal
- `case-location-reverse-geocode`: PDOK-based reverse geocoding to BAG address display
- `case-location-on-creation`: Optional location field in case creation form

### Modified Capabilities
- `case-detail`: Case detail view gains a new "Locatie" tab

## Impact

- **Frontend**: New `CaseLocationTab.vue` component, `LocationPicker.vue` modal, Leaflet integration, PDOK API calls from the Vue layer
- **Backend**: No new PHP controllers — geometry is stored via the existing OpenRegister ObjectService on the `case.geometry` field
- **Register config**: No new schemas — `case.geometry` already exists in `procest_register.json`
- **External API**: PDOK Locatieserver v3 (public, no auth required)
- **Dependencies**: Add `leaflet` and `leaflet-draw` npm packages

## Standards & References

- **GeoJSON** (RFC 7946) — geometry storage format in `case.geometry`
- **PDOK Locatieserver v3** — address autocomplete and reverse geocoding
- **BAG (Basisregistratie Adressen en Gebouwen)** — authoritative address data underlying PDOK results
- **Feature tier**: V1
