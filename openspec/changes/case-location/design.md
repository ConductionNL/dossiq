<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Design: case-location

## Architecture

### Data Storage

No new schemas or properties are required. The `case` entity already has:

```
geometry | string (JSON object) | No | GeoJSON geometry for location-based cases
```

All location data is read from and written to `case.geometry` via the existing OpenRegister ObjectService (`saveObject($register, $schema, $object)`). The frontend writes a GeoJSON string (serialized JSON object conforming to RFC 7946) to this field when saving.

### Map Library

Use **Leaflet** with **leaflet-draw** for all map interactions. Leaflet is the lightest compliant option and is already used in the Dutch government ecosystem (PDOK viewer, NLMaps). OpenLayers is out of scope — too large for this V1 feature.

Tile base layer: PDOK BRT Achtergrondkaart (public, no auth, Dutch government standard).

```
https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/{z}/{x}/{y}.png
```

### PDOK Locatieserver Integration

The PDOK Locatieserver v3 offers two endpoints consumed from the **frontend** (no backend proxy needed — the API is public CORS-enabled):

| Endpoint | Purpose | Notes |
|----------|---------|-------|
| `https://api.pdok.nl/bzk/locatieserver/search/v3_1/suggest?q={query}&rows=5` | Address autocomplete | Debounced 300ms, returns `response.docs[]` |
| `https://api.pdok.nl/bzk/locatieserver/search/v3_1/reverse?lat={lat}&lon={lon}&rows=1` | Reverse geocode | Called on tab load when geometry is a Point |

Response field used for display: `weergavenaam` (full formatted address).
Coordinates returned as WGS84 (longitude/latitude) in `centroide_ll` field (WKT POINT format).

Polygon centroid for reverse geocoding is calculated client-side using the arithmetic mean of vertex coordinates (sufficient for display purposes).

**Caching**: Reverse geocoding results are cached in component data for the lifetime of the tab to avoid repeat API calls on re-render.

### Component Structure

```
src/views/cases/
  CaseDetail.vue              -- Existing: add "Locatie" tab item
  tabs/
    CaseLocationTab.vue       -- New: map display + address sidebar
    LocationPicker.vue        -- New: modal picker (search + draw)
```

### CaseLocationTab.vue

Renders inside the existing `CaseDetail.vue` tab panel. Two states:

**State A — Geometry set:**
- Left panel (60%): Leaflet map, read-only, centered on geometry
- Right panel (40%): Address sidebar — `weergavenaam` from PDOK reverse, or "Nabij: [address]" for polygon centroid
- "Locatie wijzigen" button → opens `LocationPicker.vue`

**State B — No geometry:**
- `NcEmptyContent` with icon, message "Geen locatie ingesteld"
- "Locatie toevoegen" `NcButton` → opens `LocationPicker.vue`

Map interactions:
- Point geometry: marker centered, zoom 16
- Polygon geometry: `fitBounds()` with 20px padding; area (m²) shown below address using `L.GeometryUtil.geodesicArea()` from `leaflet.geometryutil`
- Map is read-only in this tab (no click events)

### LocationPicker.vue

A modal dialog wrapping Leaflet in edit mode:

**Toolbar:**
- Address search input (NcTextField, debounced 300ms → PDOK suggest API)
- Autocomplete dropdown (NcListItem per suggestion)
- "Punt plaatsen" mode (default): click on map places marker
- "Gebied tekenen" toggle: activates Leaflet Draw polygon tool
- Double-click closes polygon

**Coordinate display:**
- Point: lat/lon shown below map
- Polygon: area in m² shown below map

**Actions:**
- "Opslaan": serializes current geometry to GeoJSON, calls `caseStore.updateCase({ geometry: JSON.stringify(geojson) })`, closes modal
- "Annuleren": closes modal without saving

### State Management

No new Pinia store is needed. The existing case object store (created by `createObjectStore('case')`) is used to save the `geometry` field via `saveObject`. The `CaseLocationTab` reads `currentCase.geometry` from the existing store state.

### Validation (REQ-LOC-04b)

The case creation form reads `caseType.requiresLocation` from the selected case type object. If `true` and geometry is empty on submit, show an `NcDialog` with message "Dit zaaktype vereist een locatie" — do not proceed with save.

Note: `requiresLocation` is a new field on `caseType`. It must be added to the `caseType` schema in `procest_register.json` as an optional boolean (default: false). This is the only schema change required by this feature.

---

## Reuse Analysis

Per ADR-001 (Deduplication Check):

| Capability | Reused From |
|-----------|-------------|
| Case CRUD (read/save geometry) | OpenRegister `ObjectService.saveObject()` — no custom controller needed |
| Modal dialog | `NcDialog` from `@conduction/nextcloud-vue` |
| Form inputs | `NcTextField`, `NcButton` from `@conduction/nextcloud-vue` |
| Error handling | existing `try/catch` pattern around store actions |
| PDOK calls | frontend `axios` calls to public API — no backend proxy required |

Custom code is limited to: Leaflet map rendering, PDOK API calls, geometry serialization/deserialization, and the picker UI.

---

## Decisions

1. **Leaflet over OpenLayers** — smaller bundle, sufficient for point/polygon display and simple draw. OpenLayers is enterprise scope.
2. **Frontend PDOK calls** — PDOK Locatieserver is CORS-enabled and public; no backend proxy needed, simplifying architecture.
3. **No new entity** — geometry lives on `case.geometry`; no `caseLocation` entity is introduced.
4. **`caseType.requiresLocation` boolean** — minimal schema addition (non-breaking optional field) to support validation requirement REQ-LOC-04b.
5. **Centroid approximation** — arithmetic mean of polygon vertices is sufficient for reverse geocode display; Turf.js not added to keep bundle size low.

---

## Seed Data

This change does not introduce new schemas, so the ADR-001 seed data requirement for new schemas does not apply. However, existing case seed objects in `procest_register.json` SHOULD be updated to include geometry values so the Locatie tab is immediately testable after install.

### Example seed geometry additions to existing cases

**Case: Omgevingsvergunning Keizersgracht 100**
```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "omgevingsvergunning-keizersgracht" },
  "title": "Omgevingsvergunning Keizersgracht 100",
  "geometry": "{\"type\":\"Point\",\"coordinates\":[4.8897,52.3702]}"
}
```

**Case: Subsidieaanvraag Gemeentehuis Utrecht**
```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "subsidieaanvraag-utrecht-001" },
  "title": "Subsidieaanvraag Utrecht 2026-001",
  "geometry": "{\"type\":\"Point\",\"coordinates\":[5.1214,52.0907]}"
}
```

**Case: Melding Vondel park — polygon area**
```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "melding-vondelpark-2026" },
  "title": "Melding openbare ruimte Vondelpark",
  "geometry": "{\"type\":\"Polygon\",\"coordinates\":[[[4.8693,52.3604],[4.8777,52.3604],[4.8777,52.3644],[4.8693,52.3644],[4.8693,52.3604]]]}"
}
```

**Case: Handhaving Prinsengracht — point**
```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "handhaving-prinsengracht-2026" },
  "title": "Handhaving Prinsengracht 250",
  "geometry": "{\"type\":\"Point\",\"coordinates\":[4.8843,52.3747]}"
}
```

**Case: Klacht behandeling — no geometry (tests empty state)**
```json
{
  "@self": { "register": "procest", "schema": "case", "slug": "klacht-behandeling-2026-001" },
  "title": "Klacht behandeling 2026-001"
}
```

### caseType schema addition

Add to `procest_register.json` under the `caseType` schema properties:

```json
"requiresLocation": {
  "type": "boolean",
  "default": false,
  "description": "Whether cases of this type require a location to be set before saving",
  "x-translatable": false
}
```

Seed caseType update for "Omgevingsvergunning":
```json
{
  "@self": { "register": "procest", "schema": "caseType", "slug": "omgevingsvergunning" },
  "requiresLocation": true
}
```
