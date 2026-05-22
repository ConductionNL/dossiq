# GIS Integration

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Integraties

**Rationale:** GIS-backbone.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Summary

Add geographic information system (GIS) capabilities to Procest, enabling map-based views of cases, location tagging on cases via address lookup or map interaction, and integration with Dutch geo-data services (PDOK, WMS/WFS). This allows case handlers to visualize where cases are located, tag cases with precise geographic coordinates, and overlay cases on relevant map layers (kadaster, BAG, bestemmingsplan).

Location-aware case management is particularly important for VTH processes (vergunningen tied to specific parcels), but the GIS functionality is generic and applicable to any case type.

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| GIS / geo-viewer | 98 | 35 |
| GIS map layers | 212 | 111 |
| Location indication | 21 | 13 |
| PDOK geo-data integration | 1,030 | 235 |
| **Total** | **1,361** | **~300 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Levering en ondersteuning nieuw VTH systeem op basis van SaaS | Omgevingsdienst Noordzeekanaalgebied | https://www.tenderned.nl/aankondigingen/overzicht/308208 |
| VTH systeem | Gemeente Zoetermeer | https://www.tenderned.nl/aankondigingen/overzicht/263767 |
| Levering en implementatie van een SaaS-oplossing ter ondersteuning van de VTH-pr | Rijkswaterstaat Centrale Informatievoorz | https://www.tenderned.nl/aankondigingen/overzicht/402863 |
| VTH-applicatie | Gemeente Hilversum | https://www.tenderned.nl/aankondigingen/overzicht/404703 |
| Zaaksysteem gemeente Winterswijk | Gemeente Winterswijk | https://www.tenderned.nl/aankondigingen/overzicht/198896 |
| Bedrijfssoftware AFV | Gemeente 's-Hertogenbosch | https://www.tenderned.nl/aankondigingen/overzicht/411516 |

### Representative Requirements from Tenders

1. "De Oplossing maakt gebruik van een geintegreerde geoviewer. Het benaderen van de geoviewer van de Oplossing kan door de gebruiker zonder hiervoor separate software op te starten."
2. "Het is mogelijk om een vrije locatie of perceel te koppelen aan een zaak. Waarbij het zaaksysteem dit overneemt in de kaartviewer van het zaaksysteem."
3. "De applicatie ondersteunt locatiegericht werken. Dit betekent dat de geografische locatie (X-, Y-coordinaten en Z indien relevant) en de geometrie van een VTH-object of activiteit waarmee gewerkt wordt..."
4. "Vanuit de zaak kan direct naar de juiste locatie in de geo-viewer van de Oplossing worden genavigeerd, zonder dat de gebruiker hiervoor extra handelingen hoeft uit te voeren."
5. "Vanuit de zaak kan de gebruiker direct naar de juiste locatie in de geoviewer van de Oplossing navigeren, zonder dat de gebruiker hiervoor extra handelingen zoals het overnemen van gegevens van de activiteit hoeft uit te voeren."
6. "De Oplossing biedt de mogelijkheid om via WMS/WFS kaartenlagen binnen de Oplossing te tonen en om zaken als kaartlaag aan te bieden aan andere systemen."
7. "Bij het vastleggen van een zaak dient door middel van een adres of een andere locatieaanduiding (zoals het prikken op de kaart) de locatie van de betreffende zaak worden vastgelegd."
8. "Bij het registreren van een zaak kan de locatie van de betreffende zaak worden vastgelegd door: het selecteren/invoeren van een BAG- of BRK-adres, of vrije locatieaanduiding (bijvoorbeeld veldnaam of GPS-coordinaat), of het aanwijzen van een locatie op de kaart."

## Scope

### In Scope

- **Location tagging on cases**: Attach geographic coordinates (point or polygon) to any case via address lookup, parcel selection, or map click (prikken op de kaart)
- **Integrated geo-viewer**: Embedded map component (Leaflet/OpenLayers) within the case detail view that shows the case location without launching separate software
- **Address lookup (PDOK Locatieserver)**: Search by address with autocomplete using PDOK Locatieserver API, resolving to BAG address with coordinates
- **Parcel lookup (PDOK/BRK)**: Select cadastral parcels from the map or by parcel number
- **WMS/WFS map layers**: Configure and display external map layers (bestemmingsplan, kadaster, BAG, luchtfoto) via standard WMS/WFS protocols from PDOK and other providers
- **Cases-on-map view**: Overview map showing all cases as markers/clusters, filterable by zaaktype and status, enabling geographic case management
- **Case-to-map navigation**: One-click navigation from case detail to the correct location in the geo-viewer
- **Zaken als kaartlaag (WFS)**: Expose case locations as a WFS service so external GIS applications can consume case data as a map layer
- **Free location support**: Support for locations without a BAG address (field names, GPS coordinates, free text description)

### Out of Scope

- Full GIS editing (drawing complex geometries, spatial analysis)
- 3D visualization (Z-coordinate storage is supported but no 3D viewer)
- Custom map tile hosting (rely on PDOK and other public tile services)
- OpenStreetMap editing integration (read-only consumption)
- Mobile GPS tracking for inspectors (covered by `vth-workflow-configuration` mobile inspection)

## Dependencies

- **PDOK services**: Locatieserver (geocoding), WMS/WFS tile services (read-only, public, no auth required)
- **OpenRegister**: Case location data stored as coordinates/geometry in OpenRegister objects
- **Leaflet or OpenLayers**: Frontend map library (evaluate lightweight Leaflet vs. full-featured OpenLayers)
- **vth-workflow-configuration** (OPTIONAL): VTH benefits most from GIS but this change is generic
- **BAG/BRK registries**: For address and parcel lookup (via PDOK APIs)

## Acceptance Criteria

1. GIVEN a case detail view, WHEN a handler clicks the location field, THEN an integrated map appears where they can search by address (with PDOK autocomplete), select a parcel, or click on the map to set the location
2. GIVEN a case with a location set, WHEN the handler views the case detail, THEN an embedded map shows the case location with relevant context layers (satellite, kadaster) without launching external software
3. GIVEN a case with a BAG address, WHEN the handler navigates to the map, THEN the map automatically centers on the correct location without manual coordinate entry
4. GIVEN an administrator, WHEN they configure map layers, THEN they can add WMS/WFS endpoints (PDOK bestemmingsplan, kadaster, luchtfoto) that appear as toggleable overlays in the geo-viewer
5. GIVEN multiple cases with locations, WHEN a manager opens the cases-on-map view, THEN they see all cases as markers on the map with clustering for dense areas, filterable by zaaktype and status
6. GIVEN the cases-on-map WFS endpoint, WHEN an external GIS application connects to it, THEN case locations are available as a standard WFS layer with case metadata
7. GIVEN a location without a BAG address (e.g., a field or waterway), WHEN a handler registers the case, THEN they can pin a free location on the map or enter GPS coordinates manually
8. GIVEN the PDOK Locatieserver integration, WHEN a handler types an address in the search field, THEN autocomplete suggestions appear in real-time and selecting one fills in the full address with coordinates
