# Proposal: gis-integration

## Summary

Add geographic information system (GIS) capabilities to Procest, enabling map-based views of cases, location tagging on cases via address lookup or map interaction, and integration with Dutch geo-data services (PDOK, WMS/WFS). This allows case handlers to visualize where cases are located, tag cases with precise geographic coordinates, and overlay cases on relevant map layers (kadaster, BAG, bestemmingsplan). Location-aware case management is particularly important for VTH processes (vergunningen tied to specific parcels), but the GIS functionality is generic and applicable to any case type.

## Motivation

Market intelligence analysis of Dutch municipal tenders reveals strong demand for GIS capabilities:
- **1,361 functional requirements** across four GIS-related clusters (geo-viewer, map layers, location indication, PDOK integration)
- **~300 unique tenders** referencing GIS functionality
- **35 tenders specifically for geo-viewer** functionality
- **111 tenders for map layer configuration**
- **235 tenders requiring PDOK integration**

Top tenders explicitly require:
- Integrated geo-viewer without launching separate software (VTH applicatie, Gemeente Zoetermeer, Omgevingsdienst Noordzeekanaalgebied, Rijkswaterstaat)
- Ability to attach parcel locations and parcels to cases with automatic map centering (VTH systeem requirements)
- Location tagging via address lookup, parcel selection, or map click (prikken op de kaart)
- WMS/WFS layer support for bestemmingsplan, kadaster, luchtfoto
- Cases-on-map overview with geographic filtering and clustering

Without GIS support, municipalities lack location-aware case management, cannot visualize case distributions geographically, and risk losing competitive positioning for VTH and environmental permit workflows where location is mission-critical.

## Affected Projects

- [ ] Project: `procest` — Case geometry storage, address lookup integration, map UI components, WFS endpoint, configuration

## What Changes

### Features
1. **Location tagging on cases** — Attach geographic coordinates (point or polygon) to any case via:
   - PDOK Locatieserver address lookup with autocomplete
   - Cadastral parcel selection via PDOK/BRK
   - Direct map click (prikken op de kaart)
   - Free-text GPS coordinates or location names (field names, waterways)

2. **Integrated geo-viewer** — Embedded map component (Leaflet/OpenLayers) within case detail view showing case location with relevant context layers (satellite, kadaster) without launching external software

3. **Address lookup (PDOK Locatieserver)** — Search by address with autocomplete, resolving to BAG address with coordinates

4. **Parcel lookup (PDOK/BRK)** — Select cadastral parcels from map or by parcel number

5. **Configurable WMS/WFS map layers** — Configure and display external map layers (bestemmingsplan, kadaster, BAG, luchtfoto) via standard WMS/WFS protocols from PDOK and other providers

6. **Cases-on-map overview** — Map view showing all cases as markers/clusters, filterable by zaaktype and status, enabling geographic case management

7. **Case-to-map navigation** — One-click navigation from case detail to the correct location in the geo-viewer

8. **Cases-as-WFS service** — Expose case locations as a WFS endpoint so external GIS applications can consume case data as a map layer

9. **Free location support** — Support locations without a BAG address (field names, GPS coordinates, free text descriptions)

### Data Model Changes
- **case.geometry** — GeoJSON geometry (already defined in ADR-000, activated by this change)
- **mapLayer** — Configuration entity for map layers (tile, wms, wfs, geojson) — already defined in ADR-000

### Integration Points
- PDOK Locatieserver API (geocoding, read-only, no authentication)
- OpenRegister case entity with geometry field
- WMS/WFS standards (read-only tile services)
- Leaflet or OpenLayers frontend library

## Impact

- New backend services for PDOK integration and WFS endpoint generation
- New frontend map components (LocationPicker, GeoViewer, CasesOnMapView)
- Case detail enhancement with embedded map
- Configuration UI for map layer management
- External API endpoint for WFS case-location layer
- Reuses existing case infrastructure (status, filtering, relations)

## Out of Scope

- Full GIS editing (drawing complex geometries, spatial analysis tools)
- 3D visualization (Z-coordinate storage supported but no 3D viewer)
- Custom map tile hosting (rely on PDOK and other public services)
- OpenStreetMap editing integration (read-only consumption only)
- Mobile GPS tracking for inspectors (covered separately by mobile-inspection module)
- Real-time case tracking or live location updates

## Dependencies

- **PDOK services** — Locatieserver (geocoding), WMS/WFS tile services (public, no auth)
- **OpenRegister** — Case location data stored as coordinates/geometry in case objects
- **Leaflet or OpenLayers** — Frontend map library (lightweight Leaflet vs. full-featured OpenLayers to be evaluated)
- **BAG/BRK registries** — Address and parcel lookup via PDOK APIs

## Success Criteria

1. Case handlers can attach locations to cases via address search, parcel selection, or map click
2. Case detail view displays embedded map showing case location without external software launch
3. Administrators can configure and toggle map layers (bestemmingsplan, kadaster, etc.)
4. Managers can view all cases on an overview map with filtering by case type and status
5. External GIS systems can consume case locations via WFS endpoint
6. Free locations (non-BAG addresses) can be stored and displayed
7. Address autocomplete from PDOK provides real-time suggestions
8. Map navigation from case detail to correct location works seamlessly
