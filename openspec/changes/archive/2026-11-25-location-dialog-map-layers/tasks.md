## 1. Build

- [x] 1.1 Two `wmsLayer` objects in `lib/Settings/example-sets/zuiddrecht.json` (test `ZuiddrechtMapLayersTest`, red on development)
- [x] 1.2 `https://service.pdok.nl` as image source in `MapCspRegistrar` (test `MapCspRegistrarTest`, red on development)
- [x] 1.3 A stored "WFS" layer is drawn as WFS (`tests/vitest/caseLocationDialog.spec.js`, red on development)
- [x] 1.4 Live check after a set re-install: the location dialog lists Luchtfoto (PDOK) and Omgevingsplan (WMS), both checked, drawn over the base map (COMPDIFF DqZaakLocatie)
  - Done on a sandbox instance, 25 Nov 2026 (NC 34.0.6, MariaDB, dossiq/opencatalogi development, openregister main, Zuiddrecht set), Dutch locale: Locatie toevoegen on Verlichting fietspad Lindelaan lists Luchtfoto (PDOK) and Omgevingsplan (WMS), both checked, and draws both service.pdok.nl WMS layers (images loaded) over the base map, as DqZaakLocatie.
