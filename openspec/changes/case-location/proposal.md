# Proposal: Case Location

## Summary

Formalise the case ↔ location relationship in Procest by introducing a first-class `location` entity that captures the address, BAG nummeraanduiding ID, parcel reference, latitude/longitude, accuracy radius, and source. Cases gain a 0..N relation to locations so that complex case types (multi-perceel handhaving, area inspections, environmental complaints) can carry more than one geographic anchor without collapsing them into a single GeoJSON blob.

## Problem

Many case types are inherently geographic: a building permit attaches to a perceel, a complaint about a property targets a specific address, an inspection visits one or more locations, and a milieu-melding may span several BAG objects. Today Procest only carries a single `geometry` field on the case (a GeoJSON string) and renders that on a map tab. There is no validated address, no BAG nummeraanduiding reference, no per-location source or accuracy, and no way to attach more than one location to a case. As a result, downstream specs that depend on validated location data — `case-map-overview` (clustering by perceel), `pdok-integration` (reverse geocoding & BAG lookup at the entity boundary), VTH inspection routing, and any future Omgevingsloket sync — have nowhere to anchor.

## Scope -- MVP

**In scope:**
- `location` schema with `nummeraanduidingId`, `formattedAddress`, `latitude`, `longitude`, `parcelId`, `accuracyRadius`, `source`, `case` (linked case UUID)
- 0..N locations per case (no cap)
- PDOK Locatieserver suggest + lookup client (server-side proxy)
- Address validation service: a saved location MUST resolve to a BAG nummeraanduiding OR be flagged `source: free`
- Reverse geocoding when only coordinates are provided
- Case detail "Locaties" component: list, add, edit, remove locations
- Admin import flow: CSV upload with one row per location, dry-run preview, commit
- Admin export: CSV of all case locations for reporting (shapefile export is V2)

**Out of scope:**
- Map overview UI (lives in `case-map-overview`)
- WMS/WFS layer configuration (lives in `wms-wfs-layers`)
- Real-time GPS tracking, mobile field capture, 3D coordinates
- Cadastral parcel geometry rendering — only the BRK parcel identifier is stored
- Shapefile / GML export (V2 follow-up)
- Migration of existing `case.geometry` GeoJSON into `location` rows (handled by a separate data-migration change once `location` ships)

## Dependencies

- OpenRegister (entity persistence; ADR-022 `geo-metadata-kaart` capability for annotation-driven geo metadata)
- PDOK Locatieserver (public, no auth) for address validation and reverse geocoding — routed through OpenConnector when the municipality requires it
- BAG (Basisregistratie Adressen en Gebouwen) as the authoritative address source
- BRK (Basisregistratie Kadaster) for parcel identifiers (no parcel geometry fetch in MVP)
- Existing `case` schema in `lib/Settings/procest_register.json` (the `geometry` field remains for backwards compatibility but is deprecated for new cases once `location` ships)
- Downstream consumers: `case-map-overview`, `pdok-integration`, `gis-integration` (proposal), VTH inspection flow
