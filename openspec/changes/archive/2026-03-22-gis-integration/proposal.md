# GIS Integration

## Summary

Add geographic information system (GIS) capabilities to Procest, enabling map views on cases, PDOK base map integration, WMS/WFS layer support, and spatial querying. Cases already have a `geometry` field (GeoJSON) in the OpenRegister schema; this change builds the frontend map components and backend proxy to visualize and interact with that data.

Municipality case management frequently involves location-bound processes (omgevingsvergunningen, handhaving, meldingen openbare ruimte). Inspectors, vergunningverleners, and handhavers need to see where a case is located, overlay cadastral boundaries, zoning plans (bestemmingsplannen), and BAG data, and filter cases by geographic area.

## Demand Evidence

### Cluster Data (from market intelligence)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| GIS / kaartweergave | 487 | 138 |
| PDOK / basisregistraties koppeling | 312 | 97 |
| Kaart met zaaklocaties | 245 | 89 |
| WMS/WFS layer support | 178 | 64 |
| Ruimtelijke filtering / spatial query | 134 | 52 |
| BAG/kadaster integratie | 289 | 103 |
| **Total** | **~1,645** | **~340 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Levering en ondersteuning nieuw VTH systeem op basis van SaaS | Omgevingsdienst Noordzeekanaalgebied | https://www.tenderned.nl/aankondigingen/overzicht/308208 |
| Zaaksysteem | Gemeente Overbetuwe | https://www.tenderned.nl/aankondigingen/overzicht/331221 |
| Zaaksysteem | Veiligheidsregio Brabant-Noord | https://www.tenderned.nl/aankondigingen/overzicht/319120 |
| Omgevingsdienst informatiesysteem | DCMR Milieudienst Rijnmond | https://www.tenderned.nl/aankondigingen/overzicht/345891 |

### Representative Requirements from Tenders

1. "Het zaaksysteem beschikt over een kaartviewer waarin de locatie van zaken op een kaart kan worden weergegeven, met mogelijkheid tot doorklikken naar de zaak."
2. "Het systeem ondersteunt het tonen van WMS/WFS kaartlagen van externe bronnen, waaronder PDOK, het Kadaster en gemeentelijke geo-servers."
3. "Bij het aanmaken van een zaak kan een locatie worden aangewezen op de kaart of opgezocht via een BAG-adres. De geometrie wordt als GeoJSON opgeslagen."
4. "Het systeem biedt een overzichtskaart van alle lopende zaken met filtering op status, zaaktype en gebied (polygoon-selectie)."
5. "De kaartviewer ondersteunt het tonen van bestemmingsplannen, kadastrale grenzen en luchtfoto's als achtergrondkaartlagen."

## Scope

### In Scope

- **Map component library**: Leaflet-based map component for embedding in case views and dashboards
- **PDOK base maps**: BRT Achtergrondkaart, luchtfoto, CBS wijken/buurten as default tile layers
- **Case location on map**: Display case geometry on case detail view with interactive map
- **Case map overview**: Dashboard/list view showing all cases on a map with clustering and filtering
- **WMS/WFS layer support**: Admin-configurable external map layers (PDOK, kadaster, municipal geo-servers)
- **Location picker**: Map-based location selection when creating/editing cases, with BAG address search
- **Spatial filtering**: Filter cases by drawing a polygon or selecting a wijk/buurt on the map
- **GIS proxy endpoint**: PHP backend proxy to avoid CORS issues with external WMS/WFS services

### Out of Scope

- Full GIS analysis tools (buffering, intersection, routing)
- Offline map tiles for mobile inspection (covered by mobiel-inspectie spec)
- 3D visualization or BIM integration
- Own tile server or map data hosting
- Direct database spatial queries (PostGIS) -- uses client-side spatial filtering on GeoJSON

## Impact Analysis

### Existing Specs Affected

| Spec | Impact |
|------|--------|
| case-management | Extends case detail view with map tab; geometry field already exists |
| dashboard | Adds map widget option to dashboard |
| vth-module | VTH cases heavily use location data; GIS enables spatial inspection views |
| mobiel-inspectie | Mobile inspection benefits from location display; "Navigeer" button already planned |
| admin-settings | Adds GIS layer configuration section |

### Sister App Impact

- **Pipelinq**: No direct impact. Cases sent from Pipelinq will include geometry if set during intake.

### Technical Dependencies

- **Leaflet.js**: Lightweight open-source map library (BSD-2-Clause, ~40KB gzipped)
- **PDOK**: Dutch government geodata services (free, no API key required for base maps)
- **OpenRegister**: Already stores geometry as GeoJSON string; no schema changes needed
- **Proj4js**: Optional, for coordinate system transformation (RD/EPSG:28992 to WGS84)

## Risks

| Risk | Mitigation |
|------|-----------|
| Leaflet bundle size increases app load | Lazy-load map components; code-split map chunk |
| CORS on external WMS/WFS services | PHP proxy endpoint in backend |
| Large case datasets slow down map rendering | Marker clustering + viewport-based loading |
| PDOK service availability | Graceful degradation; show placeholder when tiles unavailable |
| RD (Rijksdriehoekscoordinaten) vs WGS84 | Use Proj4js for conversion; store as WGS84 GeoJSON |
