# GIS-integratie

Geografische functionaliteit in Procest: locaties op zaken, kaartlagen, een geo-viewer en een externe WFS-bron voor zaaklocaties. Deze pagina beschrijft het gebruik vanuit het perspectief van behandelaar, beheerder en manager, en sluit af met een externe-integratie- en troubleshooting-paragraaf.

> **Specs:** `openspec/changes/gis-integration/proposal.md`, `openspec/changes/gis-integration/design.md`
> **Schema's:** `case.geometry`, `mapLayer` (zie [ADR-000](../openspec/architecture/adr-000-data-model.md))
> **Status:** in opbouw — frontend-componenten en WFS-endpoint worden geleverd in opvolgende iteraties van `gis-integration`.

## Overzicht

Procest biedt vier GIS-bouwstenen:

1. **Locatie op een zaak** — koppel een punt of polygoon aan elke zaak via adres, perceel of kaartklik.
2. **Geo-viewer** — ingebouwde kaart in het zaakdetail met configureerbare achtergrondlagen (luchtfoto, kadaster, bestemmingsplan).
3. **Zaken op de kaart** — overzichtskaart met alle zaken, filterbaar op zaaktype en status.
4. **Zaken als WFS-bron** — `/wfs/cases` endpoint zodat externe GIS-applicaties (QGIS, ArcGIS) zaaklocaties als laag kunnen tonen.

Alle achtergrondlagen worden via de standaard PDOK-services (Locatieserver, BAG, BRK, WMS/WFS) opgehaald. Procest hoeft daarvoor geen externe credentials te beheren.

## Gebruikersgids — Een locatie zetten op een zaak

Op het zaakdetail vind je het paneel **Locatie**. Daar zijn vier manieren om een locatie vast te leggen.

### Adres zoeken

1. Tik in het zoekveld een (deel van een) adres, bijvoorbeeld `Marktplein 12, Utrecht`.
2. De autocomplete laat suggesties zien uit de PDOK Locatieserver (BAG).
3. Klik een suggestie aan. De kaart centreert op het adres; de zaak krijgt een puntgeometrie (lon/lat) met `addressId` uit BAG.

### Perceel selecteren

1. Klik op de tab **Perceel** in het locatiepaneel.
2. Voer een kadastraal perceelnummer in (bv. `UTR00A12345`) of klik op een perceel in de kaart. Procest haalt de perceelgrens op via PDOK/BRK en slaat de polygoon op `case.geometry` op.

### Op de kaart prikken

1. Klik op **Prikken op de kaart**.
2. Sleep en zoom de kaart naar de juiste positie en klik. Procest slaat de coördinaten (EPSG:4326) op en — indien beschikbaar — voert een reverse geocode uit om het dichtstbijzijnde adres erbij te zetten.

### Vrije locatie

Voor locaties zonder BAG-adres (veldwegen, sloten, evenementterreinen) is er **Vrije locatie**: vul een tekstuele omschrijving en optioneel GPS-coördinaten in. De vrije tekst wordt gebruikt voor zoeken; de coördinaten voor weergave op de overzichtskaart.

### Locatie wijzigen of verwijderen

- Gebruik **Aanpassen** om de selectiemethode opnieuw te kiezen.
- Gebruik **Locatie verwijderen** om `case.geometry` leeg te maken. Dit wordt in de audit trail vastgelegd.

## Beheerdersgids — Kaartlagen configureren

Procest kan willekeurige WMS-, WFS-, tile- en GeoJSON-lagen tonen. Beheerders configureren deze lagen onder **Instellingen → Procest → Kaartlagen**.

### Een laag toevoegen

1. Open **Instellingen → Procest → Kaartlagen**.
2. Klik **Laag toevoegen** en kies een type:
    - **Tile** — XYZ-tegels (bijvoorbeeld OpenStreetMap, PDOK luchtfoto).
    - **WMS** — Web Map Service (kadasterkaarten, bestemmingsplan).
    - **WFS** — Web Feature Service (vectorlagen).
    - **GeoJSON** — statisch GeoJSON-bestand of remote URL.
3. Vul in:
    - **Naam** (verplicht) — getoond in de laagselectie van de geo-viewer.
    - **URL** (verplicht) — het tile- of service-endpoint.
    - **Layer / typeName** — alleen voor WMS/WFS.
    - **Attributie** — bronvermelding die in de kaartrand wordt getoond (bijv. `© Kadaster / PDOK`).
    - **Standaard aan** — staat de laag standaard aan voor nieuwe gebruikers?
    - **Zichtbaar in lijst** — verbergt de laag in de gebruikersselector zonder hem te verwijderen.
4. Klik **Opslaan**. De laag verschijnt direct in de geo-viewer.

### Aanbevolen PDOK-lagen

| Naam | Type | URL | Layer |
|------|------|-----|-------|
| BRT achtergrond | tile | `https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0/standaard/EPSG:28992/{z}/{x}/{y}.png` | — |
| Luchtfoto actueel | wms | `https://service.pdok.nl/hwh/luchtfotorgb/wms/v1_0` | `Actueel_orthoHR` |
| Kadastrale kaart | wms | `https://service.pdok.nl/kadaster/kadastralekaart/wms/v5_0` | `Perceel` |
| Bestemmingsplan (RO Online) | wms | `https://service.pdok.nl/ruimtelijkeplannen/ro-online/wms/v2_0` | `Bestemmingsplan` |

### Laag verwijderen of uitschakelen

- **Uitschakelen** (zichtbaar = uit) — de laag verdwijnt uit de selector, maar bestaande verwijzingen blijven werken.
- **Verwijderen** — de laag wordt definitief uit de configuratie gehaald. Gebruikers die deze laag aan hadden staan, vallen terug op de standaardlagen.

## Managersgids — Zaken op de kaart

Het overzicht **Zaken op de kaart** (`/cases/map`) toont alle zaken met geometrie als markers.

- **Filter op zaaktype** — alleen `omgevingsvergunning`, `handhavingszaak`, etc. tonen.
- **Filter op status** — bijv. alleen `in behandeling` en `openstaand`.
- **Clustering** — bij hoog zoom-niveau worden nabijgelegen zaken gegroepeerd. Klik op een cluster om in te zoomen.
- **Klik op marker** — opent een popup met zaaknummer, titel, status, behandelaar en een link naar het zaakdetail.
- **Export** — exporteer de zichtbare zaken als CSV (incl. lat/lon) of GeoJSON voor verdere analyse.

De filters zijn deelbaar via URL-parameters (`?type=omgevingsvergunning&status=in_behandeling`), zodat dashboards en signaleringen naar een specifieke kaartweergave kunnen linken.

## Externe integratie — WFS-endpoint

Externe GIS-applicaties kunnen zaken consumeren via `https://<procest-host>/index.php/apps/procest/wfs/cases`.

- **Protocol:** OGC WFS 2.0 (`GetCapabilities`, `DescribeFeatureType`, `GetFeature`).
- **Authenticatie:** Bearer-token (procest user/app password) of `Basic` auth. Anonieme toegang is uitgeschakeld om PII-lekkage te voorkomen.
- **Velden in `GetFeature`:** `id`, `caseNumber`, `title`, `caseType`, `status`, `geometry`, `createdAt`.
- **CORS:** standaard alleen same-origin. Voor externe consumptie moet de beheerder de toegestane origin toevoegen in **Instellingen → Procest → WFS toegang**.

Voorbeeld QGIS-toevoeging:

1. Layer → Add Layer → Add WFS Layer → New connection.
2. URL: `https://<procest-host>/index.php/apps/procest/wfs/cases`.
3. Authentication: Basic, met procest-gebruiker + app password.
4. Connect, kies feature type `procest:cases`, klik Add.

## Troubleshooting

| Symptoom | Oorzaak | Oplossing |
|----------|---------|-----------|
| Adres zoeken geeft geen suggesties | PDOK Locatieserver onbereikbaar | Controleer netwerk-egress naar `https://geodata.nationaalgeoregister.nl`. Bij langdurige uitval: meld zaakkanteam dat locatie tijdelijk handmatig met coördinaten moet worden ingevuld. |
| Kaartlaag laadt grijs/leeg | Verkeerde URL of `layer`-naam | Test het endpoint in een browser (`?REQUEST=GetCapabilities`) en vergelijk de `Layer`-namen met de configuratie. |
| Perceel verschijnt niet | BRK-laag heeft alleen perceelgrenzen vanaf bepaald zoomniveau | Zoom verder in (≥ niveau 15) of zoek het perceel via nummer. |
| WFS-export werkt niet vanuit QGIS | CORS of auth | Controleer dat de origin in **WFS toegang** staat en dat de bearer-token niet verlopen is. |
| Geo-viewer toont oude locatie | Browser-cache | Hard refresh (Ctrl-F5). Procest stuurt `Cache-Control: no-store` op zaakdetails, maar bundle-caches kunnen blijven hangen. |

## Privacy en BIO

- Zaaklocaties zijn vaak herleidbaar tot personen (een omgevingsvergunning op een woonadres). De WFS-bron is daarom **niet** anoniem benaderbaar.
- Verwijderde zaken verdwijnen direct uit `GetFeature`-respons; zachte verwijdering houdt de geometrie verborgen.
- Logging van WFS-toegang bevat het user-id en het gefilterde feature-id maar **geen** zaaktitel of -inhoud (BIO 8.3.1).

## Specs

- `openspec/specs/gis-integration/spec.md` (concept — wordt geseed bij merge van change `gis-integration`)
- `openspec/specs/pdok-integration/spec.md`
- `openspec/specs/wms-wfs-layers/spec.md`
- `openspec/specs/case-location/spec.md`
- `openspec/specs/case-map-overview/spec.md`
